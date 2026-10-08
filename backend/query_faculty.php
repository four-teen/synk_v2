<?php
require_once "db.php";
require_once __DIR__ . '/faculty_directory_helper.php';

function query_faculty_designation_style(int $designationId, string $designationName): string
{
    $designationName = trim($designationName);
    if ($designationName === '') {
        return 'background-color:#f1f2f6;color:#697a8d;border-color:#d9dce1;';
    }

    $seed = ($designationId > 0) ? $designationId : (int)sprintf('%u', crc32(strtolower($designationName)));
    $hue = (($seed * 47) + 17) % 360;
    return "background-color:hsla({$hue}, 78%, 91%, 0.95);color:hsla({$hue}, 72%, 32%, 1);border-color:hsla({$hue}, 68%, 78%, 1);";
}

function query_faculty_employment_classification_options(): array
{
    return [
        'permanent' => 'Permanent',
        'temporary' => 'Temporary',
        'contract_of_service' => 'Contract of Service',
        'part_time' => 'Part Time',
    ];
}

function query_faculty_normalize_employment_classification(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = str_replace(['-', ' '], '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;

    $aliases = [
        'contract_service' => 'contract_of_service',
        'contract_services' => 'contract_of_service',
        'contract_of_services' => 'contract_of_service',
        'cotract_of_service' => 'contract_of_service',
        'parttime' => 'part_time',
    ];

    if (isset($aliases[$normalized])) {
        $normalized = $aliases[$normalized];
    }

    return array_key_exists($normalized, query_faculty_employment_classification_options())
        ? $normalized
        : '';
}

function query_faculty_employment_classification_label(string $value): string
{
    $options = query_faculty_employment_classification_options();
    $normalized = query_faculty_normalize_employment_classification($value);

    return $normalized !== '' ? $options[$normalized] : '';
}

function query_faculty_describe_columns(mysqli $conn, string $tableName): array
{
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$tableName}`");

    if (!($result instanceof mysqli_result)) {
        return $columns;
    }

    while ($row = $result->fetch_assoc()) {
        $fieldName = strtolower((string)($row['Field'] ?? ''));
        if ($fieldName !== '') {
            $columns[$fieldName] = $row;
        }
    }

    return $columns;
}

function query_faculty_schema_info(mysqli $conn): array
{
    $facultyColumns = query_faculty_describe_columns($conn, 'tbl_faculty');

    $designationTableResult = $conn->query("SHOW TABLES LIKE 'tbl_designation'");
    $hasDesignationTable = ($designationTableResult instanceof mysqli_result && $designationTableResult->num_rows > 0);
    $designationColumns = $hasDesignationTable
        ? query_faculty_describe_columns($conn, 'tbl_designation')
        : [];

    $designationTextColumn = null;
    foreach (['designation', 'designation_name'] as $candidate) {
        if (isset($facultyColumns[$candidate])) {
            $designationTextColumn = $candidate;
            break;
        }
    }

    return [
        'has_middle_name' => isset($facultyColumns['middle_name']),
        'has_ext_name' => isset($facultyColumns['ext_name']),
        'has_status' => isset($facultyColumns['status']),
        'has_record_revision' => isset($facultyColumns['record_revision']),
        'has_designation_id' => isset($facultyColumns['designation_id']),
        'has_employment_classification' => isset($facultyColumns['employment_classification']),
        'designation_text_column' => $designationTextColumn,
        'designation_table_exists' => $hasDesignationTable,
        'designation_table_has_name' => isset($designationColumns['designation_name']),
        'designation_table_has_status' => isset($designationColumns['status']),
    ];
}

function query_faculty_bind_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    if ($types === '' || count($params) === 0) {
        return true;
    }

    $bindParams = [$types];
    foreach ($params as $index => &$value) {
        $bindParams[] = &$value;
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function query_faculty_lookup_designation(mysqli $conn, array $schema, int $designationId): ?array
{
    if (
        $designationId <= 0
        || !$schema['designation_table_exists']
        || !$schema['designation_table_has_name']
    ) {
        return null;
    }

    $sql = "SELECT designation_id, designation_name";
    if ($schema['designation_table_has_status']) {
        $sql .= ", status";
    }
    $sql .= " FROM tbl_designation WHERE designation_id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param("i", $designationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    if ($schema['designation_table_has_status']) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if ($status !== 'active') {
            return null;
        }
    }

    return [
        'designation_id' => (int)$row['designation_id'],
        'designation_name' => trim((string)$row['designation_name']),
    ];
}

function query_faculty_persist_schema_ready(array $schema): bool
{
    return $schema['has_designation_id'] || $schema['designation_text_column'] !== null;
}

// ------------------------------------------------------------
// LOAD FACULTY LIST
// ------------------------------------------------------------
if (isset($_POST['load_faculty'])) {
    $schema = query_faculty_schema_info($conn);
    $selectParts = [
        "f.faculty_id",
        "f.last_name",
        "f.first_name",
        $schema['has_middle_name'] ? "f.middle_name" : "NULL AS middle_name",
        $schema['has_ext_name'] ? "f.ext_name" : "NULL AS ext_name",
        $schema['has_status'] ? "f.status" : "'active' AS status",
        $schema['has_employment_classification'] ? "f.employment_classification" : "NULL AS employment_classification",
    ];
    $designationLookup = [];

    if ($schema['has_designation_id']) {
        $selectParts[] = "f.designation_id";
    } else {
        $selectParts[] = "NULL AS designation_id";
    }

    if ($schema['designation_text_column'] !== null) {
        $designationColumn = $schema['designation_text_column'];
        $selectParts[] = "f.`{$designationColumn}` AS designation_name";
    } else {
        $selectParts[] = "NULL AS designation_name";
    }

    $qry = $conn->query("
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM tbl_faculty f
        ORDER BY f.last_name ASC, f.first_name ASC
    ");

    if (!($qry instanceof mysqli_result)) {
        echo "<tr><td colspan='6' class='text-center text-muted'>Unable to load faculty list. Please check database connection/schema.</td></tr>";
        exit;
    }

    if (
        $schema['has_designation_id']
        && $schema['designation_table_exists']
        && $schema['designation_table_has_name']
    ) {
        $designationResult = $conn->query("
            SELECT designation_id, designation_name
            FROM tbl_designation
        ");

        if ($designationResult instanceof mysqli_result) {
            while ($designationRow = $designationResult->fetch_assoc()) {
                $lookupId = (string)($designationRow['designation_id'] ?? '');
                $lookupName = trim((string)($designationRow['designation_name'] ?? ''));

                if ($lookupId !== '' && $lookupName !== '') {
                    $designationLookup[$lookupId] = $lookupName;
                }
            }
        }
    }

    $count = 1;

    while ($row = $qry->fetch_assoc()) {

        // Build formatted full name
        $fullname = $row['last_name'] . ", " . $row['first_name'];
        if (!empty($row['middle_name'])) {
            $fullname .= " " . $row['middle_name'];
        }
        if (!empty($row['ext_name'])) {
            $fullname .= " " . $row['ext_name'];
        }

        // Status Badge
        $statusColor = synk_faculty_record_status_color((string)$row['status']);
        $statusLabel = htmlspecialchars(strtoupper(synk_faculty_record_statuses()[$row['status']] ?? 'Not recorded'), ENT_QUOTES, 'UTF-8');
        $badge = "<span class='badge bg-{$statusColor}'>{$statusLabel}</span>";

        $designationIdRaw = trim((string)($row['designation_id'] ?? ''));
        $designationNameRaw = trim((string)($row['designation_name'] ?? ''));
        if ($designationNameRaw === '' && $designationIdRaw !== '' && isset($designationLookup[$designationIdRaw])) {
            $designationNameRaw = $designationLookup[$designationIdRaw];
        }
        $designationName = $designationNameRaw !== ''
            ? htmlspecialchars($designationNameRaw, ENT_QUOTES, 'UTF-8')
            : 'Not Set';
        $designationStyle = query_faculty_designation_style((int)($row['designation_id'] ?? 0), $designationNameRaw);
        $designationStyleAttr = htmlspecialchars($designationStyle, ENT_QUOTES, 'UTF-8');
        $designationDisplay = ($designationNameRaw !== '')
            ? "<span class='faculty-designation-pill' style='{$designationStyleAttr}'>{$designationName}</span>"
            : "<span class='text-muted'>Not Set</span>";
        $employmentClassification = query_faculty_normalize_employment_classification((string)($row['employment_classification'] ?? ''));
        $employmentClassificationLabelRaw = query_faculty_employment_classification_label($employmentClassification);
        $employmentClassificationLabel = $employmentClassificationLabelRaw !== ''
            ? htmlspecialchars($employmentClassificationLabelRaw, ENT_QUOTES, 'UTF-8')
            : 'Not Set';
        $employmentClassificationDisplay = $employmentClassificationLabelRaw !== ''
            ? "<span class='badge bg-label-info'>{$employmentClassificationLabel}</span>"
            : "<span class='text-muted'>Not Set</span>";

        echo "
            <tr>
                <td>{$count}.</td>
                <td>" . htmlspecialchars($fullname) . "</td>
                <td>{$designationDisplay}</td>
                <td>{$employmentClassificationDisplay}</td>
                <td>{$badge}</td>
                <td class='text-end'>

                    <button class='btn btn-sm btn-warning btnEditFaculty'
                        data-id='{$row['faculty_id']}'
                        data-lname=\"" . htmlspecialchars($row['last_name']) . "\"
                        data-fname=\"" . htmlspecialchars($row['first_name']) . "\"
                        data-mname=\"" . htmlspecialchars($row['middle_name']) . "\"
                        data-ext=\"" . htmlspecialchars($row['ext_name']) . "\"
                        data-status='{$row['status']}'
                        data-designation='" . htmlspecialchars($designationIdRaw, ENT_QUOTES, 'UTF-8') . "'
                        data-designation-name='" . htmlspecialchars($designationNameRaw, ENT_QUOTES, 'UTF-8') . "'
                        data-designation-style='{$designationStyleAttr}'
                        data-employment-classification='" . htmlspecialchars($employmentClassification, ENT_QUOTES, 'UTF-8') . "'
                        data-employment-classification-label='" . htmlspecialchars($employmentClassificationLabelRaw, ENT_QUOTES, 'UTF-8') . "'>
                        <i class='bx bx-edit'></i>
                    </button>

                    <button class='btn btn-sm btn-danger btnDeleteFaculty' 
                        data-id='{$row['faculty_id']}'>
                        <i class='bx bx-trash'></i>
                    </button>

                </td>
            </tr>
        ";

        $count++;
    }

    exit;
}


// ------------------------------------------------------------
// SAVE NEW FACULTY
// ------------------------------------------------------------
if (isset($_POST['save_faculty'])) {
    $schema = query_faculty_schema_info($conn);
    $lname = trim(strtoupper($_POST['last_name']));
    $fname = trim(strtoupper($_POST['first_name']));
    $mname = trim(strtoupper($_POST['middle_name']));
    $ext   = trim(strtoupper($_POST['ext_name']));
    $designationId = (int)($_POST['designation_id'] ?? 0);
    $employmentClassificationRaw = trim((string)($_POST['employment_classification'] ?? ''));
    $employmentClassification = $employmentClassificationRaw !== ''
        ? query_faculty_normalize_employment_classification($employmentClassificationRaw)
        : '';
    $status = $_POST['status'];

    if (!is_string($status) || !isset(synk_faculty_record_statuses()[$status])) {
        echo 'invalid_status';
        exit;
    }

    if ($lname == "" || $fname == "") {
        echo "missing";
        exit;
    }

    if ($employmentClassificationRaw !== '' && $employmentClassification === '') {
        echo "invalid_classification";
        exit;
    }

    if ($employmentClassification !== '' && !$schema['has_employment_classification']) {
        echo "schema_update_required";
        exit;
    }

    $designationRecord = null;
    if ($designationId > 0) {
        if (!$schema['designation_table_exists'] || !$schema['designation_table_has_name'] || !query_faculty_persist_schema_ready($schema)) {
            echo "schema_update_required";
            exit;
        }

        $designationRecord = query_faculty_lookup_designation($conn, $schema, $designationId);
        if (!is_array($designationRecord) || $designationRecord['designation_name'] === '') {
            echo "invalid_designation";
            exit;
        }
    }

    $insertColumns = ['last_name', 'first_name'];
    $insertValues = [$lname, $fname];
    $insertTypes = "ss";
    $insertPlaceholders = ['?', '?'];

    if ($schema['has_middle_name']) {
        $insertColumns[] = 'middle_name';
        $insertValues[] = $mname;
        $insertTypes .= "s";
        $insertPlaceholders[] = '?';
    }

    if ($schema['has_ext_name']) {
        $insertColumns[] = 'ext_name';
        $insertValues[] = $ext;
        $insertTypes .= "s";
        $insertPlaceholders[] = '?';
    }

    if ($schema['has_designation_id']) {
        $insertColumns[] = 'designation_id';
        if (is_array($designationRecord)) {
            $insertValues[] = $designationRecord['designation_id'];
            $insertTypes .= "i";
            $insertPlaceholders[] = '?';
        } else {
            $insertPlaceholders[] = 'NULL';
        }
    } elseif ($schema['designation_text_column'] !== null) {
        $insertColumns[] = $schema['designation_text_column'];
        $insertValues[] = is_array($designationRecord) ? $designationRecord['designation_name'] : '';
        $insertTypes .= "s";
        $insertPlaceholders[] = '?';
    }

    if ($schema['has_employment_classification']) {
        $insertColumns[] = 'employment_classification';

        if ($employmentClassification !== '') {
            $insertValues[] = $employmentClassification;
            $insertTypes .= "s";
            $insertPlaceholders[] = '?';
        } else {
            $insertPlaceholders[] = 'NULL';
        }
    }

    if ($schema['has_status']) {
        $insertColumns[] = 'status';
        $insertValues[] = $status;
        $insertTypes .= "s";
        $insertPlaceholders[] = '?';
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_faculty (`" . implode('`, `', $insertColumns) . "`)
        VALUES (" . implode(', ', $insertPlaceholders) . ")
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        echo "save_failed";
        exit;
    }

    query_faculty_bind_params($stmt, $insertTypes, $insertValues);
    $executed = $stmt->execute();
    $stmt->close();

    echo $executed ? "success" : "save_failed";
    exit;
}


// ------------------------------------------------------------
// UPDATE FACULTY
// ------------------------------------------------------------
if (isset($_POST['update_faculty'])) {
    $schema = query_faculty_schema_info($conn);
    $hasDesignationInput = array_key_exists('designation_id', $_POST);
    $hasEmploymentClassificationInput = array_key_exists('employment_classification', $_POST);
    $id    = (int)($_POST['faculty_id'] ?? 0);
    $lname = trim(strtoupper($_POST['last_name']));
    $fname = trim(strtoupper($_POST['first_name']));
    $mname = trim(strtoupper($_POST['middle_name']));
    $ext   = trim(strtoupper($_POST['ext_name']));
    $designationId = $hasDesignationInput ? (int)($_POST['designation_id'] ?? 0) : 0;
    $employmentClassificationRaw = $hasEmploymentClassificationInput
        ? trim((string)($_POST['employment_classification'] ?? ''))
        : '';
    $employmentClassification = $employmentClassificationRaw !== ''
        ? query_faculty_normalize_employment_classification($employmentClassificationRaw)
        : '';
    $status = $_POST['status'];

    if (!is_string($status) || !isset(synk_faculty_record_statuses()[$status])) {
        echo 'invalid_status';
        exit;
    }

    if ($id <= 0 || $lname == "" || $fname == "") {
        echo "missing";
        exit;
    }

    if ($hasEmploymentClassificationInput && $employmentClassificationRaw !== '' && $employmentClassification === '') {
        echo "invalid_classification";
        exit;
    }

    if ($hasEmploymentClassificationInput && $employmentClassification !== '' && !$schema['has_employment_classification']) {
        echo "schema_update_required";
        exit;
    }

    $designationRecord = null;
    if ($hasDesignationInput && $designationId > 0) {
        if (!$schema['designation_table_exists'] || !$schema['designation_table_has_name'] || !query_faculty_persist_schema_ready($schema)) {
            echo "schema_update_required";
            exit;
        }

        $designationRecord = query_faculty_lookup_designation($conn, $schema, $designationId);
        if (!is_array($designationRecord) || $designationRecord['designation_name'] === '') {
            echo "invalid_designation";
            exit;
        }
    }

    $updateParts = [
        "last_name = ?",
        "first_name = ?",
    ];
    $updateValues = [$lname, $fname];
    $updateTypes = "ss";

    if ($schema['has_middle_name']) {
        $updateParts[] = "middle_name = ?";
        $updateValues[] = $mname;
        $updateTypes .= "s";
    }

    if ($schema['has_ext_name']) {
        $updateParts[] = "ext_name = ?";
        $updateValues[] = $ext;
        $updateTypes .= "s";
    }

    if ($hasDesignationInput) {
        if ($schema['has_designation_id']) {
            if (is_array($designationRecord)) {
                $updateParts[] = "designation_id = ?";
                $updateValues[] = $designationRecord['designation_id'];
                $updateTypes .= "i";
            } else {
                $updateParts[] = "designation_id = NULL";
            }
        } elseif ($schema['designation_text_column'] !== null) {
            $updateParts[] = "`{$schema['designation_text_column']}` = ?";
            $updateValues[] = is_array($designationRecord) ? $designationRecord['designation_name'] : '';
            $updateTypes .= "s";
        }
    }

    if ($hasEmploymentClassificationInput && $schema['has_employment_classification']) {
        if ($employmentClassification !== '') {
            $updateParts[] = "employment_classification = ?";
            $updateValues[] = $employmentClassification;
            $updateTypes .= "s";
        } else {
            $updateParts[] = "employment_classification = NULL";
        }
    }

    if ($schema['has_status']) {
        $updateParts[] = "status = ?";
        $updateValues[] = $status;
        $updateTypes .= "s";
    }

    if ($schema['has_record_revision']) $updateParts[] = 'record_revision = record_revision + 1';
    $updateValues[] = $id;
    $updateTypes .= "i";

    $stmt = $conn->prepare("
        UPDATE tbl_faculty 
        SET " . implode(", ", $updateParts) . "
        WHERE faculty_id=?
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        echo "update_failed";
        exit;
    }

    query_faculty_bind_params($stmt, $updateTypes, $updateValues);
    $executed = $stmt->execute();
    $stmt->close();

    echo $executed ? "success" : "update_failed";
    exit;
}


// ------------------------------------------------------------
// DELETE FACULTY
// ------------------------------------------------------------
if (isset($_POST['delete_faculty'])) {

    $id = $_POST['faculty_id'];

    $stmt = $conn->prepare("DELETE FROM tbl_faculty WHERE faculty_id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    echo "deleted";
    exit;
}

?>

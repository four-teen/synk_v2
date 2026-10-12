<?php

require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/student_identity_helper.php';

function synk_student_management_table_name(): string
{
    return 'tbl_student_management';
}

function synk_student_management_require_admin(): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ../../index.php');
        exit;
    }
}

function synk_student_management_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_student_management_normalize_academic_year_label(string $value): string
{
    $normalized = synk_student_management_normalize_space($value);
    if ($normalized === '') {
        return '';
    }

    return preg_replace('/\s*-\s*/', '-', $normalized) ?? $normalized;
}

function synk_student_management_normalize_space(string $value): string
{
    $trimmed = trim($value);
    $normalized = preg_replace('/\s+/u', ' ', $trimmed);

    return $normalized === null ? $trimmed : $normalized;
}

function synk_student_management_year_level_from_label(string $label): int
{
    $normalized = strtolower(synk_student_management_normalize_space($label));

    $map = [
        'first year' => 1,
        '1st year' => 1,
        'second year' => 2,
        '2nd year' => 2,
        'third year' => 3,
        '3rd year' => 3,
        'fourth year' => 4,
        '4th year' => 4,
        'fifth year' => 5,
        '5th year' => 5,
    ];

    return $map[$normalized] ?? 0;
}

function synk_student_management_year_label(int $yearLevel): string
{
    $map = [
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        4 => '4th Year',
        5 => '5th Year',
    ];

    return $map[$yearLevel] ?? 'Not Set';
}

function synk_student_management_master_semester_options(): array
{
    return [
        1 => '1st Semester',
        2 => '2nd Semester',
        3 => '3rd Semester',
    ];
}

function synk_student_management_master_semester_label(int $semester): string
{
    $options = synk_student_management_master_semester_options();
    return $options[$semester] ?? '';
}

function synk_student_management_master_semester_number($value): int
{
    if (is_int($value)) {
        return isset(synk_student_management_master_semester_options()[$value]) ? $value : 0;
    }

    $rawValue = synk_student_management_normalize_space((string)$value);
    if ($rawValue === '') {
        return 0;
    }

    if (ctype_digit($rawValue)) {
        $numericValue = (int)$rawValue;
        return isset(synk_student_management_master_semester_options()[$numericValue]) ? $numericValue : 0;
    }

    $normalized = strtolower($rawValue);
    $map = [
        '1st semester' => 1,
        'first semester' => 1,
        '1st sem' => 1,
        'sem 1' => 1,
        'semester 1' => 1,
        '2nd semester' => 2,
        'second semester' => 2,
        '2nd sem' => 2,
        'sem 2' => 2,
        'semester 2' => 2,
        '3rd semester' => 3,
        'third semester' => 3,
        '3rd sem' => 3,
        'sem 3' => 3,
        'semester 3' => 3,
        'summer' => 3,
        'midyear' => 3,
        'mid-year' => 3,
    ];

    return $map[$normalized] ?? 0;
}

function synk_student_management_fold_email_name(string $value): string
{
    // Deterministic on Windows/Linux, including servers without intl or mbstring.
    return strtr($value, [
        'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Ā'=>'A','Ă'=>'A','Ą'=>'A',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ā'=>'a','ă'=>'a','ą'=>'a',
        'Ç'=>'C','Ć'=>'C','Č'=>'C','ç'=>'c','ć'=>'c','č'=>'c','Ð'=>'D','Đ'=>'D','ð'=>'d','đ'=>'d',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ē'=>'E','Ė'=>'E','Ę'=>'E','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ē'=>'e','ė'=>'e','ę'=>'e',
        'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ī'=>'I','İ'=>'I','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ī'=>'i','ı'=>'i',
        'Ñ'=>'N','Ń'=>'N','Ň'=>'N','ñ'=>'n','ń'=>'n','ň'=>'n','Ł'=>'L','ł'=>'l',
        'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Ō'=>'O','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ō'=>'o',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ū'=>'U','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ū'=>'u',
        'Ý'=>'Y','Ÿ'=>'Y','ý'=>'y','ÿ'=>'y','Ś'=>'S','Š'=>'S','ś'=>'s','š'=>'s','Ž'=>'Z','Ź'=>'Z','Ż'=>'Z','ž'=>'z','ź'=>'z','ż'=>'z',
        'Æ'=>'AE','æ'=>'ae','Œ'=>'OE','œ'=>'oe','ß'=>'ss','Þ'=>'TH','þ'=>'th',
    ]);
}

function synk_student_management_ascii_token(string $value): string
{
    $normalized = synk_student_management_fold_email_name(synk_student_identity_utf8(synk_student_management_normalize_space($value)));
    $normalized = preg_replace('/\p{M}/u', '', $normalized) ?? $normalized;

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($converted) && $converted !== '') {
            $normalized = $converted;
        }
    }

    $normalized = strtolower($normalized);
    $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);

    return $normalized ?? '';
}

function synk_student_management_build_email(string $firstName, string $lastName): string
{
    $token = synk_student_management_ascii_token($firstName) . synk_student_management_ascii_token($lastName);
    if ($token === '') {
        return '';
    }

    return $token . '@sksu.edu.ph';
}

function synk_student_management_program_source_sql(string $programAlias = 'p'): string
{
    return "TRIM(CONCAT_WS(' ', {$programAlias}.program_name, NULLIF({$programAlias}.major, '')))";
}

function synk_student_management_fetch_academic_year_catalog(mysqli $conn): array
{
    static $cache = [];
    $cacheKey = spl_object_hash($conn);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rows = [];
    $result = $conn->query("
        SELECT ay_id, ay
        FROM tbl_academic_years
        ORDER BY ay DESC, ay_id DESC
    ");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'ay' => synk_student_management_normalize_academic_year_label((string)($row['ay'] ?? '')),
            ];
        }
        $result->close();
    }

    $cache[$cacheKey] = $rows;
    return $rows;
}

function synk_student_management_find_academic_year_id_by_label(array $catalog, string $label): int
{
    $normalizedLabel = synk_student_management_normalize_academic_year_label($label);
    if ($normalizedLabel === '') {
        return 0;
    }

    foreach ($catalog as $row) {
        if ($normalizedLabel === synk_student_management_normalize_academic_year_label((string)($row['ay'] ?? ''))) {
            return (int)($row['ay_id'] ?? 0);
        }
    }

    return 0;
}

function synk_student_management_resolve_academic_year_id(mysqli $conn, string $label): int
{
    return synk_student_management_find_academic_year_id_by_label(
        synk_student_management_fetch_academic_year_catalog($conn),
        $label
    );
}

function synk_student_management_find_program_id_by_source_name(
    array $programCatalog,
    string $sourceProgramName,
    string $collegeName = '',
    string $campusName = ''
): int {
    $normalizedProgramName = strtolower(synk_student_management_normalize_space($sourceProgramName));
    if ($normalizedProgramName === '') {
        return 0;
    }

    $normalizedCollegeName = strtolower(synk_student_management_normalize_space($collegeName));
    $normalizedCampusName = strtolower(synk_student_management_normalize_space($campusName));
    $fallbackProgramId = 0;

    foreach ($programCatalog as $program) {
        $programSourceName = strtolower(synk_student_management_normalize_space((string)($program['source_program_name'] ?? '')));
        if ($programSourceName !== $normalizedProgramName) {
            continue;
        }

        $programId = (int)($program['program_id'] ?? 0);
        if ($programId <= 0) {
            continue;
        }

        if ($fallbackProgramId === 0) {
            $fallbackProgramId = $programId;
        }

        $sameCollege = $normalizedCollegeName === ''
            || strtolower(synk_student_management_normalize_space((string)($program['college_name'] ?? ''))) === $normalizedCollegeName;
        $sameCampus = $normalizedCampusName === ''
            || strtolower(synk_student_management_normalize_space((string)($program['campus_name'] ?? ''))) === $normalizedCampusName;

        if ($sameCollege && $sameCampus) {
            return $programId;
        }
    }

    return $fallbackProgramId;
}

function synk_student_management_drop_index_if_exists(mysqli $conn, string $tableName, string $indexName): void
{
    if (!synk_table_has_index($conn, $tableName, $indexName)) {
        return;
    }

    if (!$conn->query("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`")) {
        throw new RuntimeException('Unable to update the student management table indexes.');
    }
}

function synk_student_management_has_column(mysqli $conn, string $tableName, string $columnName): bool
{
    if (!synk_table_exists($conn, $tableName)) {
        return false;
    }

    $safeColumnName = $conn->real_escape_string($columnName);
    $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$safeColumnName}'");

    return ($result instanceof mysqli_result) && $result->num_rows > 0;
}

function synk_student_management_drop_column_if_exists(mysqli $conn, string $tableName, string $columnName): void
{
    if (!synk_student_management_has_column($conn, $tableName, $columnName)) {
        return;
    }

    if (!$conn->query("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`")) {
        throw new RuntimeException('Unable to normalize the student management table columns.');
    }
}

function synk_student_management_migrate_legacy_schema(mysqli $conn): void
{
    $tableName = synk_student_management_table_name();
    $legacyColumns = [
        'academic_year_label',
        'semester_label',
        'college_name',
        'campus_name',
        'source_program_name',
    ];

    $hasLegacyColumns = false;
    foreach ($legacyColumns as $legacyColumn) {
        if (synk_student_management_has_column($conn, $tableName, $legacyColumn)) {
            $hasLegacyColumns = true;
            break;
        }
    }

    if (!$hasLegacyColumns) {
        return;
    }

    $hasAcademicYearLabel = synk_student_management_has_column($conn, $tableName, 'academic_year_label');
    $hasSemesterLabel = synk_student_management_has_column($conn, $tableName, 'semester_label');
    $hasCollegeName = synk_student_management_has_column($conn, $tableName, 'college_name');
    $hasCampusName = synk_student_management_has_column($conn, $tableName, 'campus_name');
    $hasSourceProgramName = synk_student_management_has_column($conn, $tableName, 'source_program_name');

    if ($hasAcademicYearLabel) {
        $catalog = synk_student_management_fetch_academic_year_catalog($conn);
        $result = $conn->query("SELECT student_id, academic_year_label FROM `{$tableName}` WHERE ay_id = 0 AND academic_year_label <> ''");
        if ($result instanceof mysqli_result) {
            $updateStmt = $conn->prepare("UPDATE `{$tableName}` SET ay_id = ? WHERE student_id = ? LIMIT 1");
            if (!$updateStmt) {
                $result->close();
                throw new RuntimeException('Unable to migrate student academic year references.');
            }

            while ($row = $result->fetch_assoc()) {
                $ayId = synk_student_management_find_academic_year_id_by_label(
                    $catalog,
                    (string)($row['academic_year_label'] ?? '')
                );
                $studentId = (int)($row['student_id'] ?? 0);
                if ($ayId <= 0 || $studentId <= 0) {
                    continue;
                }

                $updateStmt->bind_param('ii', $ayId, $studentId);
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    $result->close();
                    throw new RuntimeException('Unable to migrate student academic year references.');
                }
            }

            $updateStmt->close();
            $result->close();
        }
    }

    if ($hasSemesterLabel) {
        $result = $conn->query("SELECT student_id, semester_label FROM `{$tableName}` WHERE semester = 0 AND semester_label <> ''");
        if ($result instanceof mysqli_result) {
            $updateStmt = $conn->prepare("UPDATE `{$tableName}` SET semester = ? WHERE student_id = ? LIMIT 1");
            if (!$updateStmt) {
                $result->close();
                throw new RuntimeException('Unable to migrate student semester references.');
            }

            while ($row = $result->fetch_assoc()) {
                $semester = synk_student_management_master_semester_number((string)($row['semester_label'] ?? ''));
                $studentId = (int)($row['student_id'] ?? 0);
                if ($semester <= 0 || $studentId <= 0) {
                    continue;
                }

                $updateStmt->bind_param('ii', $semester, $studentId);
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    $result->close();
                    throw new RuntimeException('Unable to migrate student semester references.');
                }
            }

            $updateStmt->close();
            $result->close();
        }
    }

    if ($hasSourceProgramName) {
        $selectColumns = ['student_id', 'program_id', 'source_program_name'];
        if ($hasCollegeName) {
            $selectColumns[] = 'college_name';
        }
        if ($hasCampusName) {
            $selectColumns[] = 'campus_name';
        }

        $programCatalog = synk_student_management_fetch_program_catalog($conn);
        $selectSql = sprintf(
            'SELECT %s FROM `%s` WHERE program_id = 0 AND source_program_name <> \'\'',
            implode(', ', $selectColumns),
            $tableName
        );
        $result = $conn->query($selectSql);
        if ($result instanceof mysqli_result) {
            $updateStmt = $conn->prepare("UPDATE `{$tableName}` SET program_id = ? WHERE student_id = ? LIMIT 1");
            if (!$updateStmt) {
                $result->close();
                throw new RuntimeException('Unable to migrate student program references.');
            }

            while ($row = $result->fetch_assoc()) {
                $programId = synk_student_management_find_program_id_by_source_name(
                    $programCatalog,
                    (string)($row['source_program_name'] ?? ''),
                    (string)($row['college_name'] ?? ''),
                    (string)($row['campus_name'] ?? '')
                );
                $studentId = (int)($row['student_id'] ?? 0);
                if ($programId <= 0 || $studentId <= 0) {
                    continue;
                }

                $updateStmt->bind_param('ii', $programId, $studentId);
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    $result->close();
                    throw new RuntimeException('Unable to migrate student program references.');
                }
            }

            $updateStmt->close();
            $result->close();
        }
    }

    $legacyIndexes = [
        'idx_student_term',
        'idx_student_program_year',
        'idx_student_batch_scope',
        'idx_student_batch_program_year',
    ];

    foreach ($legacyIndexes as $legacyIndex) {
        synk_student_management_drop_index_if_exists($conn, $tableName, $legacyIndex);
    }

    foreach ($legacyColumns as $legacyColumn) {
        synk_student_management_drop_column_if_exists($conn, $tableName, $legacyColumn);
    }
}

function synk_student_management_ensure_schema(mysqli $conn): void
{
    if (synk_student_identity_schema_ready($conn)) return;
    $tableName = synk_student_management_table_name();

    $createSql = "
        CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `student_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ay_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `semester` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `source_sheet_name` VARCHAR(64) NOT NULL DEFAULT '',
            `source_file_name` VARCHAR(255) NOT NULL DEFAULT '',
            `year_level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `student_number` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            `last_name` VARCHAR(150) NOT NULL DEFAULT '',
            `first_name` VARCHAR(150) NOT NULL DEFAULT '',
            `middle_name` VARCHAR(150) NOT NULL DEFAULT '',
            `suffix_name` VARCHAR(50) NOT NULL DEFAULT '',
            `email_address` VARCHAR(255) NOT NULL DEFAULT '',
            `program_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `uploaded_by` INT UNSIGNED NULL DEFAULT NULL,
            `source_row_number` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`student_id`),
            KEY `idx_student_term` (`ay_id`, `semester`),
            KEY `idx_student_program_year` (`program_id`, `year_level`),
            KEY `idx_student_email` (`email_address`),
            KEY `idx_student_program_id` (`program_id`),
            KEY `idx_student_number` (`student_number`),
            KEY `idx_student_batch_scope` (`ay_id`, `semester`, `program_id`),
            KEY `idx_student_name_lookup` (`last_name`, `first_name`, `middle_name`, `suffix_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($createSql)) {
        throw new RuntimeException('Unable to create the student management table.');
    }

    $alterStatements = [
        'ay_id' => "ALTER TABLE `{$tableName}` ADD COLUMN `ay_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `student_id`",
        'semester' => "ALTER TABLE `{$tableName}` ADD COLUMN `semester` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `ay_id`",
        'source_sheet_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `source_sheet_name` VARCHAR(64) NOT NULL DEFAULT '' AFTER `semester`",
        'source_file_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `source_file_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `source_sheet_name`",
        'year_level' => "ALTER TABLE `{$tableName}` ADD COLUMN `year_level` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `source_file_name`",
        'student_number' => "ALTER TABLE `{$tableName}` ADD COLUMN `student_number` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `year_level`",
        'last_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `last_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `student_number`",
        'first_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `first_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `last_name`",
        'middle_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `middle_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `first_name`",
        'suffix_name' => "ALTER TABLE `{$tableName}` ADD COLUMN `suffix_name` VARCHAR(50) NOT NULL DEFAULT '' AFTER `middle_name`",
        'email_address' => "ALTER TABLE `{$tableName}` ADD COLUMN `email_address` VARCHAR(255) NOT NULL DEFAULT '' AFTER `suffix_name`",
        'program_id' => "ALTER TABLE `{$tableName}` ADD COLUMN `program_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `email_address`",
        'uploaded_by' => "ALTER TABLE `{$tableName}` ADD COLUMN `uploaded_by` INT UNSIGNED NULL DEFAULT NULL AFTER `program_id`",
        'source_row_number' => "ALTER TABLE `{$tableName}` ADD COLUMN `source_row_number` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `uploaded_by`",
        'created_at' => "ALTER TABLE `{$tableName}` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `source_row_number`",
        'updated_at' => "ALTER TABLE `{$tableName}` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($alterStatements as $columnName => $sql) {
        if (!synk_student_management_has_column($conn, $tableName, $columnName)) {
            if (!$conn->query($sql)) {
                throw new RuntimeException('Unable to update the student management table schema.');
            }
        }
    }

    synk_student_management_migrate_legacy_schema($conn);

    $indexStatements = [
        'idx_student_term' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_term` (`ay_id`, `semester`)",
        'idx_student_program_year' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_program_year` (`program_id`, `year_level`)",
        'idx_student_email' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_email` (`email_address`)",
        'idx_student_program_id' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_program_id` (`program_id`)",
        'idx_student_number' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_number` (`student_number`)",
        'idx_student_batch_scope' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_batch_scope` (`ay_id`, `semester`, `program_id`)",
        'idx_student_name_lookup' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_name_lookup` (`last_name`, `first_name`, `middle_name`, `suffix_name`)",
    ];

    foreach ($indexStatements as $indexName => $sql) {
        if (!synk_table_has_index($conn, $tableName, $indexName)) {
            if (!$conn->query($sql)) {
                throw new RuntimeException('Unable to optimize the student management table indexes.');
            }
        }
    }
}

function synk_student_management_zip_read(ZipArchive $zip, string $entryName): string
{
    $content = $zip->getFromName($entryName);
    if ($content === false || $content === '') {
        throw new RuntimeException('Invalid XLSX structure: missing ' . $entryName . '.');
    }

    return $content;
}

function synk_student_management_load_xml(string $content): SimpleXMLElement
{
    $xml = simplexml_load_string($content);
    if (!$xml instanceof SimpleXMLElement) {
        throw new RuntimeException('Unable to read spreadsheet XML content.');
    }

    return $xml;
}

function synk_student_management_load_shared_strings(ZipArchive $zip): array
{
    if ($zip->locateName('xl/sharedStrings.xml') === false) {
        return [];
    }

    $sharedStringsXml = synk_student_management_load_xml(
        synk_student_management_zip_read($zip, 'xl/sharedStrings.xml')
    );
    $sharedStringsXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $items = $sharedStringsXml->xpath('/main:sst/main:si');
    $sharedStrings = [];

    foreach ($items ?: [] as $item) {
        $item->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $textNodes = $item->xpath('.//main:t');
        $chunks = [];
        foreach ($textNodes ?: [] as $textNode) {
            $chunks[] = (string)$textNode;
        }
        $sharedStrings[] = implode('', $chunks);
    }

    return $sharedStrings;
}

function synk_student_management_resolve_sheet_entry(ZipArchive $zip, string $sheetName): string
{
    $workbookXml = synk_student_management_load_xml(
        synk_student_management_zip_read($zip, 'xl/workbook.xml')
    );
    $workbookXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $relationshipsXml = synk_student_management_load_xml(
        synk_student_management_zip_read($zip, 'xl/_rels/workbook.xml.rels')
    );
    $relationshipsXml->registerXPathNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');

    $relationshipMap = [];
    foreach ($relationshipsXml->xpath('/rel:Relationships/rel:Relationship') ?: [] as $relationship) {
        $attributes = $relationship->attributes();
        $relationshipId = (string)($attributes['Id'] ?? '');
        $target = (string)($attributes['Target'] ?? '');
        if ($relationshipId !== '' && $target !== '') {
            $relationshipMap[$relationshipId] = $target;
        }
    }

    foreach ($workbookXml->xpath('/main:workbook/main:sheets/main:sheet') ?: [] as $sheet) {
        $attributes = $sheet->attributes();
        $relationAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $currentSheetName = synk_student_management_normalize_space((string)($attributes['name'] ?? ''));
        $relationshipId = (string)($relationAttributes['id'] ?? '');

        if (strcasecmp($currentSheetName, $sheetName) !== 0) {
            continue;
        }

        if (!isset($relationshipMap[$relationshipId])) {
            break;
        }

        $target = ltrim($relationshipMap[$relationshipId], '/');
        if (strpos($target, 'xl/') !== 0) {
            $target = 'xl/' . $target;
        }

        return $target;
    }

    throw new RuntimeException('The uploaded workbook does not contain the expected ' . $sheetName . ' sheet.');
}

function synk_student_management_cell_value(SimpleXMLElement $cell, array $sharedStrings): string
{
    $attributes = $cell->attributes();
    $cellType = (string)($attributes['t'] ?? '');

    if ($cellType === 'inlineStr') {
        $cell->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $textNodes = $cell->xpath('.//main:t');
        $chunks = [];
        foreach ($textNodes ?: [] as $textNode) {
            $chunks[] = (string)$textNode;
        }

        return synk_student_management_normalize_space(implode('', $chunks));
    }

    $value = synk_student_management_normalize_space((string)($cell->v ?? ''));
    if ($cellType === 's' && $value !== '') {
        $index = (int)$value;
        return synk_student_management_normalize_space($sharedStrings[$index] ?? '');
    }

    return $value;
}

function synk_student_management_cell_column(string $cellReference): string
{
    if ($cellReference === '') {
        return '';
    }

    if (preg_match('/^[A-Z]+/i', $cellReference, $matches)) {
        return strtoupper($matches[0]);
    }

    return '';
}

function synk_student_management_parse_xlsx(string $xlsxPath): array
{
    if (!is_file($xlsxPath)) {
        throw new RuntimeException('The uploaded XLSX file could not be found.');
    }

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException('Unable to open the uploaded XLSX file.');
    }

    try {
        $sharedStrings = synk_student_management_load_shared_strings($zip);
        $sheetEntry = synk_student_management_resolve_sheet_entry($zip, 'LIST');
        $worksheetXml = synk_student_management_load_xml(
            synk_student_management_zip_read($zip, $sheetEntry)
        );
        $worksheetXml->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = $worksheetXml->xpath('/main:worksheet/main:sheetData/main:row');
        if (empty($rows)) {
            throw new RuntimeException('The LIST sheet does not contain readable rows.');
        }

        $metadata = [
            'sheet_name' => 'LIST',
            'college_name' => '',
            'campus_name' => '',
            'semester_label' => '',
            'academic_year_label' => '',
        ];

        $students = [];
        $pendingProgramBase = '';
        $currentProgram = '';
        $currentYearLevel = 0;

        foreach ($rows as $row) {
            $rowNumber = (int)($row['r'] ?? 0);
            $values = [];

            foreach ($row->c as $cell) {
                $cellAttributes = $cell->attributes();
                $column = synk_student_management_cell_column((string)($cellAttributes['r'] ?? ''));
                if ($column === '') {
                    continue;
                }

                $values[$column] = synk_student_management_cell_value($cell, $sharedStrings);
            }

            $columnA = synk_student_management_normalize_space((string)($values['A'] ?? ''));
            if ($columnA === '') {
                continue;
            }

            if ($metadata['college_name'] === '' && stripos($columnA, 'College of') === 0) {
                $metadata['college_name'] = $columnA;
                continue;
            }

            if ($metadata['campus_name'] === '' && stripos($columnA, 'Campus') !== false) {
                $metadata['campus_name'] = $columnA;
                continue;
            }

            if ($metadata['semester_label'] === '' && stripos($columnA, 'semester') !== false && stripos($columnA, 'list of') === false) {
                $metadata['semester_label'] = ucwords(strtolower($columnA));
                continue;
            }

            if ($metadata['academic_year_label'] === '' && preg_match('/^\d{4}\s*-\s*\d{4}$/', $columnA) === 1) {
                $metadata['academic_year_label'] = preg_replace('/\s*-\s*/', '-', $columnA) ?? $columnA;
                continue;
            }

            $yearLevel = synk_student_management_year_level_from_label($columnA);
            if ($yearLevel > 0) {
                if ($pendingProgramBase !== '') {
                    $currentProgram = $pendingProgramBase;
                    $pendingProgramBase = '';
                }

                $currentYearLevel = $yearLevel;
                continue;
            }

            if (preg_match('/^\d+$/', $columnA) === 1) {
                $studentNumber = synk_student_identity_number($columnA);
                $lastName = synk_student_management_normalize_space((string)($values['B'] ?? ''));
                $firstName = synk_student_management_normalize_space((string)($values['C'] ?? ''));
                $middleName = synk_student_management_normalize_space((string)($values['D'] ?? ''));
                $suffixName = synk_student_management_normalize_space((string)($values['E'] ?? ''));

                if ($middleName === '*' || $middleName === '-') {
                    $middleName = '';
                }
                if ($suffixName === '*' || $suffixName === '-') {
                    $suffixName = '';
                }

                if ($currentProgram === '' || $currentYearLevel <= 0 || $lastName === '' || $firstName === '') {
                    continue;
                }

                $students[] = [
                    'row_number' => $rowNumber,
                    'student_number' => $studentNumber,
                    'source_program_name' => $currentProgram,
                    'year_level' => $currentYearLevel,
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'suffix_name' => $suffixName,
                    'email_address' => synk_student_management_build_email($firstName, $lastName),
                ];
                continue;
            }

            if (stripos($columnA, 'Bachelor of') === 0) {
                $pendingProgramBase = synk_student_management_normalize_space($columnA);
                $currentProgram = $pendingProgramBase;
                $currentYearLevel = 0;
                continue;
            }

            if ($pendingProgramBase !== '') {
                $currentProgram = synk_student_management_normalize_space($pendingProgramBase . ' ' . $columnA);
                $pendingProgramBase = '';
                $currentYearLevel = 0;
            }
        }

        if ($metadata['semester_label'] === '') {
            $metadata['semester_label'] = 'Unknown Semester';
        }
        if ($metadata['academic_year_label'] === '') {
            $metadata['academic_year_label'] = 'Unknown Academic Year';
        }

        if (empty($students)) {
            throw new RuntimeException('No student rows were detected in the uploaded workbook.');
        }

        return [
            'metadata' => $metadata,
            'students' => $students,
        ];
    } finally {
        $zip->close();
    }
}

function synk_student_management_import_students(
    mysqli $conn,
    string $xlsxPath,
    string $originalFileName,
    int $uploadedBy = 0
): array {
    synk_student_management_ensure_schema($conn);

    $parsed = synk_student_management_parse_xlsx($xlsxPath);
    $metadata = $parsed['metadata'];
    $students = $parsed['students'];
    $tableName = synk_student_management_table_name();
    $academicYearLabel = synk_student_management_normalize_academic_year_label((string)$metadata['academic_year_label']);
    $semesterNumber = synk_student_management_master_semester_number((string)$metadata['semester_label']);
    $ayId = synk_student_management_resolve_academic_year_id($conn, $academicYearLabel);
    $programCatalog = synk_student_management_fetch_program_catalog($conn);

    $preparedStudents = [];
    $uniquePrograms = [];
    $uniqueProgramIds = [];
    $uniqueYearLevels = [];

    foreach ($students as $student) {
        $sourceProgramName = (string)($student['source_program_name'] ?? '');
        $programId = synk_student_management_find_program_id_by_source_name(
            $programCatalog,
            $sourceProgramName,
            (string)($metadata['college_name'] ?? ''),
            (string)($metadata['campus_name'] ?? '')
        );
        $preparedStudents[] = [
            'ay_id' => $ayId,
            'semester' => $semesterNumber,
            'source_sheet_name' => (string)($metadata['sheet_name'] ?? 'LIST'),
            'source_file_name' => synk_student_management_normalize_space($originalFileName),
            'year_level' => (int)($student['year_level'] ?? 0),
            'student_number' => synk_student_identity_number($student['student_number'] ?? ''),
            'last_name' => (string)($student['last_name'] ?? ''),
            'first_name' => (string)($student['first_name'] ?? ''),
            'middle_name' => (string)($student['middle_name'] ?? ''),
            'suffix_name' => (string)($student['suffix_name'] ?? ''),
            'email_address' => (string)($student['email_address'] ?? ''),
            'program_id' => $programId,
            'uploaded_by' => $uploadedBy > 0 ? $uploadedBy : 0,
            'source_row_number' => (int)($student['row_number'] ?? 0),
            'source_program_name' => $sourceProgramName,
        ];

        if ($programId > 0) {
            $uniqueProgramIds[$programId] = true;
        }
        if ($sourceProgramName !== '') {
            $uniquePrograms[$sourceProgramName] = true;
        }
        if ((int)($student['year_level'] ?? 0) > 0) {
            $uniqueYearLevels[(int)$student['year_level']] = true;
        }
    }

    $insertedRows = 0; $matchedRows = 0;
    $conn->begin_transaction();
    try {
        // The master directory is shared across subjects and terms. Never replace a term batch by deleting profiles.
        foreach ($preparedStudents as $student) {
            $identity = synk_student_identity_create_or_reuse($conn,$student);
            if ($identity['created']) $insertedRows++; else $matchedRows++;
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return [
        'deleted_rows' => 0,
        'inserted_rows' => $insertedRows,
        'matched_rows' => $matchedRows,
        'program_count' => count($uniquePrograms),
        'year_level_count' => count($uniqueYearLevels),
        'academic_year_label' => (string)$metadata['academic_year_label'],
        'semester_label' => (string)$metadata['semester_label'],
        'college_name' => (string)$metadata['college_name'],
        'campus_name' => (string)$metadata['campus_name'],
    ];
}

function synk_student_management_summary(mysqli $conn): array
{
    synk_student_management_ensure_schema($conn);

    $tableName = synk_student_management_table_name();
    $sql = "
        SELECT
            COUNT(*) AS total_students,
            COUNT(DISTINCT CASE WHEN program_id > 0 THEN program_id END) AS total_programs,
            COUNT(DISTINCT CONCAT(ay_id, '|', semester)) AS total_terms,
            SUM(CASE WHEN program_id = 0 THEN 1 ELSE 0 END) AS unassigned_students,
            MAX(created_at) AS last_uploaded_at
        FROM `{$tableName}`
    ";

    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : [];

    return [
        'total_students' => (int)($row['total_students'] ?? 0),
        'total_programs' => (int)($row['total_programs'] ?? 0),
        'total_terms' => (int)($row['total_terms'] ?? 0),
        'unassigned_students' => (int)($row['unassigned_students'] ?? 0),
        'last_uploaded_at' => (string)($row['last_uploaded_at'] ?? ''),
    ];
}

function synk_student_management_distinct_programs(mysqli $conn): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $sql = "
        SELECT DISTINCT
            sm.program_id,
            " . synk_student_management_program_source_sql('p') . " AS source_program_name
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        WHERE sm.program_id > 0
        ORDER BY source_program_name ASC
    ";

    $result = $conn->query($sql);
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'program_id' => (int)($row['program_id'] ?? 0),
                'source_program_name' => (string)($row['source_program_name'] ?? ''),
            ];
        }
        $result->close();
    }

    return $rows;
}

function synk_student_management_distinct_terms(mysqli $conn): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $sql = "
        SELECT DISTINCT
            sm.ay_id,
            sm.semester,
            COALESCE(ay.ay, '') AS academic_year_label
        FROM `{$tableName}` sm
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        ORDER BY sm.ay_id DESC, sm.semester DESC
    ";

    $result = $conn->query($sql);
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'semester' => (int)($row['semester'] ?? 0),
                'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
                'semester_label' => synk_student_management_master_semester_label((int)($row['semester'] ?? 0)),
            ];
        }
        $result->close();
    }

    return $rows;
}

function synk_student_management_build_filter_where(array $filters, string &$types, array &$params): string
{
    $clauses = [];
    $programSourceSql = synk_student_management_program_source_sql('p');

    $search = synk_student_management_normalize_space((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $clauses[] = "(sm.last_name LIKE ? OR sm.first_name LIKE ? OR sm.middle_name LIKE ? OR sm.email_address LIKE ? OR {$programSourceSql} LIKE ? OR p.program_code LIKE ?)";
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $yearLevel = (int)($filters['year_level'] ?? 0);
    if ($yearLevel > 0) {
        $clauses[] = 'sm.year_level = ?';
        $types .= 'i';
        $params[] = $yearLevel;
    }

    $programId = max(0, (int)($filters['program_id'] ?? 0));
    if ($programId > 0) {
        $clauses[] = 'sm.program_id = ?';
        $types .= 'i';
        $params[] = $programId;
    }

    $ayId = max(0, (int)($filters['ay_id'] ?? 0));
    if ($ayId > 0) {
        $clauses[] = 'sm.ay_id = ?';
        $types .= 'i';
        $params[] = $ayId;
    }

    $semester = max(0, (int)($filters['semester'] ?? 0));
    if ($semester > 0) {
        $clauses[] = 'sm.semester = ?';
        $types .= 'i';
        $params[] = $semester;
    }

    return empty($clauses) ? '' : (' WHERE ' . implode(' AND ', $clauses));
}

function synk_student_management_count_records(mysqli $conn, array $filters): int
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $types = '';
    $params = [];
    $whereSql = synk_student_management_build_filter_where($filters, $types, $params);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_rows
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        {$whereSql}
    ");
    if (!$stmt) {
        return 0;
    }

    if ($types !== '') {
        synk_bind_dynamic_params($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return (int)($row['total_rows'] ?? 0);
}

function synk_student_management_directory_order_sql(array $sort = []): string
{
    $column = trim((string)($sort['column'] ?? ''));
    $direction = strtolower(trim((string)($sort['direction'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';

    switch ($column) {
        case 'student_number':
            return "sm.student_number {$direction}, sm.last_name ASC, sm.first_name ASC";

        case 'full_name':
            return "sm.last_name {$direction}, sm.first_name {$direction}, sm.middle_name {$direction}, sm.suffix_name {$direction}";

        case 'email_address':
            return "sm.email_address {$direction}, sm.last_name ASC, sm.first_name ASC";

        case 'subject_count':
            return "subject_count {$direction}, sm.last_name ASC, sm.first_name ASC";

        default:
            return "
                sm.ay_id DESC,
                sm.semester DESC,
                source_program_name ASC,
                sm.year_level ASC,
                sm.student_number ASC,
                sm.last_name ASC,
                sm.first_name ASC
            ";
    }
}

function synk_student_management_fetch_records(mysqli $conn, array $filters, int $limit = 100, int $offset = 0, array $sort = []): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $types = '';
    $params = [];
    $whereSql = synk_student_management_build_filter_where($filters, $types, $params);

    $safeLimit = max(1, min(250, $limit));
    $safeOffset = max(0, $offset);
    $programSourceSql = synk_student_management_program_source_sql('p');
    $enrollmentTableName = 'tbl_student_management_enrolled_subjects';
    $subjectCountJoinSql = '';
    $subjectCountSelectSql = '0 AS subject_count';
    $countAyId = max(0, (int)($filters['count_ay_id'] ?? 0));
    $countSemester = max(0, (int)($filters['count_semester'] ?? 0));

    if (synk_table_exists($conn, $enrollmentTableName)) {
        $subjectCountWhereParts = ['es.is_active = 1'];

        if ($countAyId > 0) {
            $subjectCountWhereParts[] = 'es.ay_id = ' . $countAyId;
        }

        if ($countSemester > 0) {
            $subjectCountWhereParts[] = 'es.semester = ' . $countSemester;
        }

        $subjectCountJoinSql = "
        LEFT JOIN (
            SELECT
                es.student_id,
                COUNT(*) AS subject_count
            FROM `{$enrollmentTableName}` es
            WHERE " . implode(' AND ', $subjectCountWhereParts) . "
            GROUP BY es.student_id
        ) esc
            ON esc.student_id = sm.student_id";
        $subjectCountSelectSql = 'COALESCE(esc.subject_count, 0) AS subject_count';
    }

    $orderSql = synk_student_management_directory_order_sql($sort);

    $sql = "
        SELECT
            sm.student_id,
            sm.ay_id,
            sm.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            {$programSourceSql} AS source_program_name,
            sm.year_level,
            sm.student_number,
            sm.last_name,
            sm.first_name,
            sm.middle_name,
            sm.suffix_name,
            sm.email_address,
            sm.program_id,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(cam.campus_name, '') AS campus_name,
            {$subjectCountSelectSql},
            sm.created_at
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        {$subjectCountJoinSql}
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT ?
        OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $types .= 'ii';
    $params[] = $safeLimit;
    $params[] = $safeOffset;
    synk_bind_dynamic_params($stmt, $types, $params);

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_management_latest_batch(mysqli $conn): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $sql = "
        SELECT
            sm.ay_id,
            sm.semester,
            c.college_id,
            c.college_name,
            cam.campus_id,
            cam.campus_name,
            COALESCE(ay.ay, '') AS academic_year_label,
            COUNT(*) AS student_count,
            COUNT(DISTINCT CASE WHEN sm.program_id > 0 THEN sm.program_id END) AS program_count,
            COUNT(DISTINCT sm.year_level) AS year_level_count,
            MAX(sm.created_at) AS last_uploaded_at
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        GROUP BY sm.ay_id, sm.semester, c.college_id, c.college_name, cam.campus_id, cam.campus_name, ay.ay
        ORDER BY MAX(sm.created_at) DESC, sm.ay_id DESC, sm.semester DESC, cam.campus_name ASC
        LIMIT 1
    ";

    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return [
            'ay_id' => 0,
            'semester' => 0,
            'college_id' => 0,
            'campus_id' => 0,
            'academic_year_label' => '',
            'semester_label' => '',
            'college_name' => '',
            'campus_name' => '',
            'student_count' => 0,
            'program_count' => 0,
            'year_level_count' => 0,
            'last_uploaded_at' => '',
        ];
    }

    $row = $result->fetch_assoc();
    $semester = (int)($row['semester'] ?? 0);
    $result->close();

    return [
        'ay_id' => (int)($row['ay_id'] ?? 0),
        'semester' => $semester,
        'college_id' => (int)($row['college_id'] ?? 0),
        'campus_id' => (int)($row['campus_id'] ?? 0),
        'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
        'semester_label' => synk_student_management_master_semester_label($semester),
        'college_name' => (string)($row['college_name'] ?? ''),
        'campus_name' => (string)($row['campus_name'] ?? ''),
        'student_count' => (int)($row['student_count'] ?? 0),
        'program_count' => (int)($row['program_count'] ?? 0),
        'year_level_count' => (int)($row['year_level_count'] ?? 0),
        'last_uploaded_at' => (string)($row['last_uploaded_at'] ?? ''),
    ];
}

function synk_student_management_program_year_level_chart(mysqli $conn, array $batch = []): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $resolvedBatch = array_merge(
        synk_student_management_latest_batch($conn),
        array_filter($batch, static function ($value) {
            return $value !== null && $value !== '';
        })
    );

    $ayId = max(0, (int)($resolvedBatch['ay_id'] ?? 0));
    $semester = max(0, (int)($resolvedBatch['semester'] ?? 0));
    $collegeId = max(0, (int)($resolvedBatch['college_id'] ?? 0));
    $campusId = max(0, (int)($resolvedBatch['campus_id'] ?? 0));

    if ($ayId <= 0 || $semester <= 0 || $collegeId <= 0 || $campusId <= 0) {
        return [
            'categories' => [],
            'series' => [],
            'max_value' => 0,
        ];
    }

    $sql = "
        SELECT
            " . synk_student_management_program_source_sql('p') . " AS source_program_name,
            sm.year_level,
            COUNT(*) AS student_total
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        WHERE sm.ay_id = ?
          AND sm.semester = ?
          AND c.college_id = ?
          AND cam.campus_id = ?
        GROUP BY source_program_name, sm.year_level
        ORDER BY source_program_name ASC, sm.year_level ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'categories' => [],
            'series' => [],
            'max_value' => 0,
        ];
    }

    $stmt->bind_param('iiii', $ayId, $semester, $collegeId, $campusId);
    $stmt->execute();
    $result = $stmt->get_result();

    $programMatrix = [];
    $maxValue = 0;

    while ($row = $result->fetch_assoc()) {
        $programName = (string)($row['source_program_name'] ?? '');
        $yearLevel = (int)($row['year_level'] ?? 0);
        $studentTotal = (int)($row['student_total'] ?? 0);

        if ($programName === '' || $yearLevel <= 0) {
            continue;
        }

        if (!isset($programMatrix[$programName])) {
            $programMatrix[$programName] = [
                1 => 0,
                2 => 0,
                3 => 0,
                4 => 0,
                5 => 0,
            ];
        }

        $programMatrix[$programName][$yearLevel] = $studentTotal;
        $maxValue = max($maxValue, $studentTotal);
    }

    $stmt->close();

    if (empty($programMatrix)) {
        return [
            'categories' => [],
            'series' => [],
            'max_value' => 0,
        ];
    }

    $categories = array_keys($programMatrix);
    $series = [];

    foreach ([1, 2, 3, 4, 5] as $yearLevel) {
        $data = [];
        $hasData = false;

        foreach ($categories as $programName) {
            $value = (int)($programMatrix[$programName][$yearLevel] ?? 0);
            $data[] = $value;
            if ($value > 0) {
                $hasData = true;
            }
        }

        if ($hasData) {
            $series[] = [
                'name' => synk_student_management_year_label($yearLevel),
                'data' => $data,
            ];
        }
    }

    return [
        'categories' => $categories,
        'series' => $series,
        'max_value' => $maxValue,
    ];
}

function synk_student_management_fetch_active_campuses(mysqli $conn): array
{
    $rows = [];
    $result = $conn->query("
        SELECT campus_id, campus_code, campus_name
        FROM tbl_campus
        WHERE status = 'active'
        ORDER BY campus_name ASC
    ");

    if (!($result instanceof mysqli_result)) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'campus_id' => (int)($row['campus_id'] ?? 0),
            'campus_code' => (string)($row['campus_code'] ?? ''),
            'campus_name' => (string)($row['campus_name'] ?? ''),
        ];
    }

    return $rows;
}

function synk_student_management_distinct_batches(mysqli $conn): array
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();

    $sql = "
        SELECT DISTINCT
            sm.ay_id,
            sm.semester,
            c.college_id,
            c.college_name,
            cam.campus_id,
            cam.campus_name,
            COALESCE(ay.ay, '') AS academic_year_label
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        ORDER BY sm.ay_id DESC, sm.semester DESC, cam.campus_name ASC, c.college_name ASC
    ";

    $result = $conn->query($sql);
    $rows = [];
    if (!($result instanceof mysqli_result)) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $semester = (int)($row['semester'] ?? 0);
        $rows[] = [
            'ay_id' => (int)($row['ay_id'] ?? 0),
            'semester' => $semester,
            'college_id' => (int)($row['college_id'] ?? 0),
            'campus_id' => (int)($row['campus_id'] ?? 0),
            'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
            'semester_label' => synk_student_management_master_semester_label($semester),
            'college_name' => (string)($row['college_name'] ?? ''),
            'campus_name' => (string)($row['campus_name'] ?? ''),
        ];
    }

    $result->close();

    return $rows;
}

function synk_student_management_program_source_name(string $programName, string $major = ''): string
{
    $parts = [synk_student_management_normalize_space($programName)];
    $normalizedMajor = synk_student_management_normalize_space($major);
    if ($normalizedMajor !== '') {
        $parts[] = $normalizedMajor;
    }

    return synk_student_management_normalize_space(implode(' ', array_filter($parts)));
}

function synk_student_management_fetch_program_catalog(mysqli $conn): array
{
    $rows = [];
    $sql = "
        SELECT
            p.program_id,
            p.college_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(cam.campus_name, '') AS campus_name
        FROM tbl_program p
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        WHERE p.status = 'active'
        ORDER BY cam.campus_name ASC, c.college_name ASC, p.program_name ASC, p.major ASC, p.program_code ASC
    ";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $programName = (string)($row['program_name'] ?? '');
        $major = (string)($row['major'] ?? '');
        $rows[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'college_id' => (int)($row['college_id'] ?? 0),
            'program_code' => (string)($row['program_code'] ?? ''),
            'program_name' => $programName,
            'major' => $major,
            'college_name' => (string)($row['college_name'] ?? ''),
            'campus_name' => (string)($row['campus_name'] ?? ''),
            'source_program_name' => synk_student_management_program_source_name($programName, $major),
        ];
    }

    return $rows;
}

function synk_student_management_fetch_program_by_id(mysqli $conn, int $programId): ?array
{
    if ($programId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(cam.campus_name, '') AS campus_name
        FROM tbl_program p
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        WHERE p.program_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $programName = (string)($row['program_name'] ?? '');
    $major = (string)($row['major'] ?? '');

    return [
        'program_id' => (int)($row['program_id'] ?? 0),
        'program_code' => (string)($row['program_code'] ?? ''),
        'program_name' => $programName,
        'major' => $major,
        'college_name' => (string)($row['college_name'] ?? ''),
        'campus_name' => (string)($row['campus_name'] ?? ''),
        'source_program_name' => synk_student_management_program_source_name($programName, $major),
    ];
}

function synk_student_management_fetch_record_by_id(mysqli $conn, int $studentId): ?array
{
    synk_student_management_ensure_schema($conn);
    if ($studentId <= 0) {
        return null;
    }

    $tableName = synk_student_management_table_name();
    $programSourceSql = synk_student_management_program_source_sql('p');
    $stmt = $conn->prepare("
        SELECT
            sm.student_id,
            sm.ay_id,
            sm.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(cam.campus_name, '') AS campus_name,
            {$programSourceSql} AS source_program_name,
            sm.year_level,
            sm.student_number,
            sm.last_name,
            sm.first_name,
            sm.middle_name,
            sm.suffix_name,
            sm.email_address,
            sm.program_id,
            sm.source_sheet_name,
            sm.source_file_name,
            sm.source_row_number
        FROM `{$tableName}` sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        WHERE sm.student_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['semester_label'] = synk_student_management_master_semester_label((int)($row['semester'] ?? 0));
    return $row;
}

function synk_student_management_fetch_record_by_student_number(
    mysqli $conn,
    string $studentNumber,
    int $excludeStudentId = 0
): ?array {
    synk_student_management_ensure_schema($conn);
    if ($studentNumber === '') {
        return null;
    }

    $tableName = synk_student_management_table_name();
    $programSourceSql = synk_student_management_program_source_sql('p');

    if ($excludeStudentId > 0) {
        $stmt = $conn->prepare("
            SELECT
                sm.student_id,
                sm.ay_id,
                sm.semester,
                COALESCE(ay.ay, '') AS academic_year_label,
                COALESCE(c.college_name, '') AS college_name,
                COALESCE(cam.campus_name, '') AS campus_name,
                {$programSourceSql} AS source_program_name,
                sm.year_level,
                sm.student_number,
                sm.last_name,
                sm.first_name,
                sm.middle_name,
                sm.suffix_name,
                sm.email_address,
                sm.program_id,
                sm.source_sheet_name,
                sm.source_file_name,
                sm.source_row_number
            FROM `{$tableName}` sm
            LEFT JOIN tbl_program p
                ON p.program_id = sm.program_id
            LEFT JOIN tbl_college c
                ON c.college_id = p.college_id
            LEFT JOIN tbl_campus cam
                ON cam.campus_id = c.campus_id
            LEFT JOIN tbl_academic_years ay
                ON ay.ay_id = sm.ay_id
            WHERE sm.student_number = ?
              AND sm.student_id <> ?
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('si', $studentNumber, $excludeStudentId);
    } else {
        $stmt = $conn->prepare("
            SELECT
                sm.student_id,
                sm.ay_id,
                sm.semester,
                COALESCE(ay.ay, '') AS academic_year_label,
                COALESCE(c.college_name, '') AS college_name,
                COALESCE(cam.campus_name, '') AS campus_name,
                {$programSourceSql} AS source_program_name,
                sm.year_level,
                sm.student_number,
                sm.last_name,
                sm.first_name,
                sm.middle_name,
                sm.suffix_name,
                sm.email_address,
                sm.program_id,
                sm.source_sheet_name,
                sm.source_file_name,
                sm.source_row_number
            FROM `{$tableName}` sm
            LEFT JOIN tbl_program p
                ON p.program_id = sm.program_id
            LEFT JOIN tbl_college c
                ON c.college_id = p.college_id
            LEFT JOIN tbl_campus cam
                ON cam.campus_id = c.campus_id
            LEFT JOIN tbl_academic_years ay
                ON ay.ay_id = sm.ay_id
            WHERE sm.student_number = ?
            LIMIT 1
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $studentNumber);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['semester_label'] = synk_student_management_master_semester_label((int)($row['semester'] ?? 0));
    return $row;
}

function synk_student_management_prepare_email_address(string $emailAddress, string $firstName, string $lastName): string
{
    $normalized = strtolower(trim($emailAddress));
    if ($normalized === '') {
        $normalized = synk_student_management_build_email($firstName, $lastName);
    }

    if ($normalized === '') {
        throw new RuntimeException('Email address could not be generated. Provide a valid first and last name.');
    }

    if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Provide a valid email address.');
    }

    if (!preg_match('/@sksu\.edu\.ph$/i', $normalized)) {
        throw new RuntimeException('Student email addresses must end with @sksu.edu.ph.');
    }

    return $normalized;
}

function synk_student_management_normalize_record_payload(array $payload): array
{
    $ayId = max(0, (int)($payload['ay_id'] ?? 0));
    $semester = max(0, (int)($payload['semester'] ?? 0));
    $yearLevel = (int)($payload['year_level'] ?? 0);
    $studentNumber = synk_student_identity_number($payload['student_number'] ?? '');
    $lastName = synk_student_management_normalize_space((string)($payload['last_name'] ?? ''));
    $firstName = synk_student_management_normalize_space((string)($payload['first_name'] ?? ''));
    $middleName = synk_student_management_normalize_space((string)($payload['middle_name'] ?? ''));
    $suffixName = synk_student_management_normalize_space((string)($payload['suffix_name'] ?? ''));
    $programId = max(0, (int)($payload['program_id'] ?? 0));

    if ($ayId <= 0 || $semester <= 0 || $programId <= 0) {
        throw new RuntimeException('Select a valid academic year, semester, and program before saving.');
    }

    if ($yearLevel <= 0) {
        throw new RuntimeException('Select a valid year level.');
    }

    if ($lastName === '' || $firstName === '') {
        throw new RuntimeException('Last name and first name are required.');
    }

    return [
        'ay_id' => $ayId,
        'semester' => $semester,
        'year_level' => $yearLevel,
        'student_number' => $studentNumber,
        'last_name' => $lastName,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'suffix_name' => $suffixName,
        'email_address' => synk_student_management_prepare_email_address(
            (string)($payload['email_address'] ?? ''),
            $firstName,
            $lastName
        ),
        'program_id' => $programId,
    ];
}

function synk_student_management_prepare_record_payload(mysqli $conn, array $payload): array
{
    $payload['ay_id'] = max(
        0,
        (int)($payload['ay_id'] ?? synk_student_management_resolve_academic_year_id(
            $conn,
            (string)($payload['academic_year_label'] ?? '')
        ))
    );
    $payload['semester'] = max(
        0,
        synk_student_management_master_semester_number(
            $payload['semester'] ?? ($payload['semester_label'] ?? '')
        )
    );

    $programId = max(0, (int)($payload['program_id'] ?? 0));
    if ($programId > 0) {
        $program = synk_student_management_fetch_program_by_id($conn, $programId);
        if (!$program) {
            throw new RuntimeException('Select a valid program from the program table.');
        }

        $payload['program_id'] = $programId;
    }

    return synk_student_management_normalize_record_payload($payload);
}

function synk_student_management_create_record(mysqli $conn, array $payload, int $userId = 0): int
{
    synk_student_management_ensure_schema($conn);
    $tableName = synk_student_management_table_name();
    $record = synk_student_management_prepare_record_payload($conn, $payload);

    $existingRecord = synk_student_management_fetch_record_by_student_number(
        $conn,
        (string)$record['student_number']
    );
    if ($existingRecord) {
        throw new RuntimeException('The student number already exists in the student directory.');
    }

    $sheetName = 'MANUAL';
    $fileName = 'Manual Entry';
    $uploadedBy = $userId > 0 ? $userId : 0;
    $sourceRowNumber = 0;

    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            ay_id,
            semester,
            source_sheet_name,
            source_file_name,
            year_level,
            student_number,
            last_name,
            first_name,
            middle_name,
            suffix_name,
            email_address,
            program_id,
            uploaded_by,
            source_row_number
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the student create query.');
    }

    $stmt->bind_param(
        'iississssssiii',
        $record['ay_id'],
        $record['semester'],
        $sheetName,
        $fileName,
        $record['year_level'],
        $record['student_number'],
        $record['last_name'],
        $record['first_name'],
        $record['middle_name'],
        $record['suffix_name'],
        $record['email_address'],
        $record['program_id'],
        $uploadedBy,
        $sourceRowNumber
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to add the student record.');
    }

    $insertId = (int)$stmt->insert_id;
    $stmt->close();

    return $insertId;
}

function synk_student_management_update_record(mysqli $conn, int $studentId, array $payload): void
{
    synk_student_management_ensure_schema($conn);
    if ($studentId <= 0) {
        throw new RuntimeException('Select a valid student record.');
    }

    $tableName = synk_student_management_table_name();
    $record = synk_student_management_prepare_record_payload($conn, $payload);

    $existingRecord = synk_student_management_fetch_record_by_student_number(
        $conn,
        (string)$record['student_number'],
        $studentId
    );
    if ($existingRecord) {
        throw new RuntimeException('The student number already exists in another student record.');
    }

    $stmt = $conn->prepare("
        UPDATE `{$tableName}`
        SET
            ay_id = ?,
            semester = ?,
            year_level = ?,
            student_number = ?,
            last_name = ?,
            first_name = ?,
            middle_name = ?,
            suffix_name = ?,
            email_address = ?,
            program_id = ?
        WHERE student_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the student update query.');
    }

    $stmt->bind_param(
        'iiissssssii',
        $record['ay_id'],
        $record['semester'],
        $record['year_level'],
        $record['student_number'],
        $record['last_name'],
        $record['first_name'],
        $record['middle_name'],
        $record['suffix_name'],
        $record['email_address'],
        $record['program_id'],
        $studentId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to update the student record.');
    }

    $stmt->close();
}

function synk_student_management_delete_record(mysqli $conn, int $studentId): void
{
    synk_student_management_ensure_schema($conn);
    if ($studentId <= 0) {
        throw new RuntimeException('Select a valid student record.');
    }

    $tableName = synk_student_management_table_name();
    $stmt = $conn->prepare("DELETE FROM `{$tableName}` WHERE student_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the student delete query.');
    }

    $stmt->bind_param('i', $studentId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to delete the student record.');
    }

    $stmt->close();
}

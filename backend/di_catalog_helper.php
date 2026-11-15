<?php

function synk_di_catalog_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_di_catalog_text(mysqli $conn, string $table, string $field, bool $required = true): string
{
    $value = strtoupper(trim($_POST[$field] ?? ''));
    if ($required && $value === '') throw new DomainException('Complete all required fields.', 422);
    $column = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$field}'")->fetch_assoc();
    if (preg_match('/\((\d+)\)/', $column['Type'] ?? '', $match) && mb_strlen($value, 'UTF-8') > (int)$match[1]) throw new DomainException('The ' . str_replace('_', ' ', $field) . ' is too long.', 422);
    return $value;
}

// Include both declared foreign keys and the application's older links without FK constraints.
function synk_di_catalog_referenced(mysqli $conn, string $kind, int $id): bool
{
    $own = $kind === 'program' ? 'tbl_program' : 'tbl_subject_masterlist';
    $columns = $kind === 'program' ? ['program_id'] : ['sub_id','subject_id'];
    foreach ($columns as $column) {
        $tables = synk_di_curriculum_query($conn, "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=? AND TABLE_NAME<>?", 'ss', [$column,$own]);
        foreach ($tables as $table) {
            $name = str_replace('`', '``', $table['TABLE_NAME']);
            if (synk_di_curriculum_query($conn, "SELECT `{$column}` FROM `{$name}` WHERE `{$column}`=? LIMIT 1 FOR UPDATE", 'i', [$id])) return true;
        }
    }
    return false;
}

function synk_di_catalog_handle(mysqli $conn, string $catalog): void
{
    $program = $catalog === 'program';
    $table = $program ? 'tbl_program' : 'tbl_subject_masterlist';
    $idField = $program ? 'program_id' : 'sub_id';
    $loading = isset($_POST[$program ? 'load_programs' : 'load_subjects']);
    if ($program) {
        require_once __DIR__ . '/di_program_helper.php';
        if ($loading) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(synk_di_program_list($conn, $_POST));
            exit;
        }
    }
    if ($loading) {
        $sql = "SELECT s.*,COUNT(ps.ps_id) AS usage_count,GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') AS used_programs FROM tbl_subject_masterlist s LEFT JOIN tbl_prospectus_subjects ps ON ps.sub_id=s.sub_id LEFT JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id LEFT JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id LEFT JOIN tbl_program p ON p.program_id=h.program_id GROUP BY s.sub_id ORDER BY s.sub_code";
        foreach (synk_di_curriculum_query($conn, $sql) as $index => $row) {
            $id = (int)$row[$idField];
            $h = array_map('synk_di_catalog_h', $row);
            $badge = $row['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            echo '<tr><td>' . ($index + 1) . '</td>';
            echo '<td>' . $h['sub_code'] . '</td><td>' . $h['sub_description'] . '</td><td>' . ($h['used_programs'] ?: '—') . '</td>';
            $attrs = 'data-code="' . $h['sub_code'] . '" data-desc="' . $h['sub_description'] . '"';
            echo '<td>' . $badge . '</td><td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-warning btnEdit" aria-label="Edit" data-id="' . $id . '" data-status="' . $h['status'] . '" ' . $attrs . '><i class="bx bx-edit-alt"></i></button> <button type="button" class="btn btn-sm btn-danger btnDelete" aria-label="Delete" data-id="' . $id . '"' . ((int)$row['usage_count'] > 0 ? ' disabled title="Subject is already used in a prospectus"' : '') . '><i class="bx bx-trash"></i></button></td></tr>';
        }
        exit;
    }
    $creating = isset($_POST[$program ? 'save_program' : 'save_subject']);
    $deleting = isset($_POST[$program ? 'delete_program' : 'delete_subject']);
    $id = $creating ? 0 : synk_di_curriculum_id($_POST[$idField] ?? null);
    $existing = $id ? synk_di_curriculum_query($conn, "SELECT * FROM {$table} WHERE {$idField}=? FOR UPDATE", 'i', [$id]) : [];
    if (!$creating && !$existing) throw new DomainException('This catalog record is unavailable.', 422);
    if ($deleting) {
        if ($program) {
            $scope = synk_di_program_scope($conn, $_POST);
            $owner = synk_di_curriculum_query($conn, 'SELECT campus_id FROM tbl_college WHERE college_id=?', 'i', [(int)$existing[0]['college_id']]);
            if (!$owner || (int)$owner[0]['campus_id'] !== $scope['campus_id'] || ($scope['college_id'] && $scope['college_id'] !== (int)$existing[0]['college_id'])) {
                throw new DomainException('This program does not belong to the selected campus and college.', 422);
            }
            // Deactivation preserves curriculum, sections, offerings, schedules, and workloads.
            synk_di_curriculum_query($conn, "UPDATE tbl_program SET status='inactive' WHERE program_id=?", 'i', [$id]);
            exit('updated');
        }
        if (synk_di_catalog_referenced($conn, $catalog, $id)) throw new DomainException('This record is already in use and cannot be deleted.', 409);
        synk_di_curriculum_query($conn, "DELETE FROM {$table} WHERE {$idField}=?", 'i', [$id]);
        exit('deleted');
    }
    $status = $creating ? 'active' : ($_POST['status'] ?? '');
    if (!in_array($status, ['active','inactive'], true)) throw new DomainException('Choose a valid status.', 422);
    if ($program) {
        $collegeId = synk_di_program_scope($conn, $_POST, true)['college_id'];
        if (($creating || (int)$existing[0]['college_id'] !== $collegeId) && !synk_di_curriculum_query($conn, "SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE", 'i', [$collegeId])) throw new DomainException('Choose an active college.', 422);
        $code = synk_di_catalog_text($conn, $table, 'program_code');
        $name = synk_di_catalog_text($conn, $table, 'program_name');
        $major = synk_di_catalog_text($conn, $table, 'major', false);
        if (synk_di_curriculum_query($conn, 'SELECT program_id FROM tbl_program WHERE college_id=? AND program_code=? AND major=? AND program_id<>? FOR UPDATE', 'issi', [$collegeId,$code,$major,$id])) throw new DomainException('This program and major already exist in that college.', 409);
        if (!$creating && (int)$existing[0]['college_id'] !== $collegeId && synk_di_catalog_referenced($conn, $catalog, $id)) throw new DomainException('This program is in use. Keep its college to preserve existing academic records.', 409);
        if ($creating) synk_di_curriculum_query($conn, 'INSERT INTO tbl_program(college_id,program_code,program_name,major,status) VALUES(?,?,?,?,?)', 'issss', [$collegeId,$code,$name,$major,$status]);
        else synk_di_curriculum_query($conn, 'UPDATE tbl_program SET college_id=?,program_code=?,program_name=?,major=?,status=? WHERE program_id=?', 'issssi', [$collegeId,$code,$name,$major,$status,$id]);
    } else {
        $code = synk_di_catalog_text($conn, $table, 'sub_code');
        $description = synk_di_catalog_text($conn, $table, 'sub_description');
        if (synk_di_curriculum_query($conn, 'SELECT sub_id FROM tbl_subject_masterlist WHERE sub_code=? AND sub_description=? AND sub_id<>? FOR UPDATE', 'ssi', [$code,$description,$id])) throw new DomainException('This subject code and description already exist.', 409);
        if (!$creating && $existing[0]['status'] !== $status && synk_di_catalog_referenced($conn, $catalog, $id)) throw new DomainException('This subject is in use. Keep its status to preserve existing academic records.', 409);
        if ($creating) synk_di_curriculum_query($conn, 'INSERT INTO tbl_subject_masterlist(sub_code,sub_description,status) VALUES(?,?,?)', 'sss', [$code,$description,$status]);
        else synk_di_curriculum_query($conn, 'UPDATE tbl_subject_masterlist SET sub_code=?,sub_description=?,status=? WHERE sub_id=?', 'sssi', [$code,$description,$status,$id]);
    }
    exit($creating ? 'success' : 'updated');
}

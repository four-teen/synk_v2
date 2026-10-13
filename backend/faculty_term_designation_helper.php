<?php
require_once __DIR__ . '/schema_helper.php';

// The derived relation keeps existing report queries (d.name, d.units, d.status)
// consistent, including an explicit empty appointment and saved catalog values.
function synk_faculty_designation_join_sql(mysqli $conn, int $ayId, int $semester, string $facultyAlias = 'f', string $alias = 'd'): string
{
    foreach ([$facultyAlias, $alias] as $identifier) {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) throw new InvalidArgumentException('Invalid designation alias.');
    }
    if (!synk_table_exists($conn, 'tbl_faculty_term_designations') || $ayId <= 0 || $semester <= 0) {
        return " LEFT JOIN tbl_designation {$alias} ON {$alias}.designation_id = {$facultyAlias}.designation_id ";
    }
    return " LEFT JOIN (
        SELECT df.faculty_id,
            CASE WHEN td.faculty_id IS NOT NULL THEN td.designation_id ELSE df.designation_id END AS designation_id,
            CONVERT(CASE WHEN td.faculty_id IS NOT NULL THEN td.designation_name ELSE COALESCE(dc.designation_name,'') END USING utf8mb4) COLLATE utf8mb4_general_ci AS designation_name,
            CASE WHEN td.faculty_id IS NOT NULL THEN td.designation_units ELSE COALESCE(dc.designation_units,0) END AS designation_units,
            CONVERT(CASE WHEN td.faculty_id IS NOT NULL THEN 'active' ELSE dc.status END USING utf8mb4) COLLATE utf8mb4_general_ci AS status
        FROM tbl_faculty df LEFT JOIN tbl_designation dc ON dc.designation_id=df.designation_id
        LEFT JOIN tbl_faculty_term_designations td ON td.faculty_id=df.faculty_id AND td.ay_id={$ayId} AND td.semester={$semester}
    ) {$alias} ON {$alias}.faculty_id={$facultyAlias}.faculty_id ";
}

function synk_faculty_term_designation_record(mysqli $conn, int $facultyId, int $ayId, int $semester, array $designation, int $actorId, string $source = 'selected', bool $replace = true): void
{
    $id = (int)($designation['designation_id'] ?? 0) ?: null;
    $name = (string)($designation['designation_name'] ?? '');
    $units = (float)($designation['designation_units'] ?? 0);
    $sql = 'INSERT INTO tbl_faculty_term_designations
        (faculty_id,ay_id,semester,designation_id,designation_name,designation_units,updated_by,record_source)
        VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ' . ($replace
            ? 'designation_id=VALUES(designation_id),designation_name=VALUES(designation_name),designation_units=VALUES(designation_units),updated_by=VALUES(updated_by),record_source=VALUES(record_source),updated_at=CURRENT_TIMESTAMP'
            : "designation_id=IF(record_source='legacy',VALUES(designation_id),designation_id),
               designation_name=IF(record_source='legacy',VALUES(designation_name),designation_name),
               designation_units=IF(record_source='legacy',VALUES(designation_units),designation_units),
               updated_by=IF(record_source='legacy',VALUES(updated_by),updated_by),
               updated_at=IF(record_source='legacy',CURRENT_TIMESTAMP,updated_at),
               record_source=IF(record_source='legacy',VALUES(record_source),record_source)");
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to save the designation.');
    $stmt->bind_param('iiiisdis', $facultyId, $ayId, $semester, $id, $name, $units, $actorId, $source);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to save the designation.'); }
    $stmt->close();
}

function synk_faculty_term_designation_copy(mysqli $conn, int $facultyId, array $source, array $target, int $actorId): void
{
    if (!synk_table_exists($conn, 'tbl_faculty_term_designations')) throw new RuntimeException('Term designation storage is not ready.');
    $join = synk_faculty_designation_join_sql($conn, (int)$source['ay_id'], (int)$source['semester']);
    $stmt = $conn->prepare("SELECT d.designation_id,d.designation_name,d.designation_units FROM tbl_faculty f {$join} WHERE f.faculty_id=?");
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException('The source designation is no longer available.');
    synk_faculty_term_designation_record($conn, $facultyId, (int)$target['ay_id'], (int)$target['semester'], $row, $actorId, 'copied', false);
}

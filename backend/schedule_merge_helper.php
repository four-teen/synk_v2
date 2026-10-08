<?php

require_once __DIR__ . '/schema_helper.php';

function synk_schedule_merge_table_name(): string
{
    return 'tbl_class_schedule_merge';
}

function synk_schedule_merge_scope_values(): array
{
    return ['FULL', 'LEC', 'LAB'];
}

function synk_schedule_merge_schedule_types(): array
{
    return ['LEC', 'LAB'];
}

function synk_schedule_merge_normalize_scope($scope): string
{
    $value = strtoupper(trim((string)$scope));
    return in_array($value, ['LEC', 'LAB'], true) ? $value : 'FULL';
}

function synk_schedule_merge_scope_match_sql(
    string $scheduleTypeExpr,
    string $mergeScopeExpr,
    string $collation = 'utf8mb4_unicode_ci'
): string {
    return "
                    CAST({$scheduleTypeExpr} AS CHAR CHARACTER SET utf8mb4) COLLATE {$collation} =
                    CONVERT({$mergeScopeExpr} USING utf8mb4) COLLATE {$collation}
               ";
}

function synk_schedule_merge_normalize_offering_ids(array $offeringIds): array
{
    $normalized = [];

    foreach ($offeringIds as $offeringId) {
        $value = (int)$offeringId;
        if ($value > 0) {
            $normalized[$value] = $value;
        }
    }

    return array_values($normalized);
}

function synk_schedule_merge_array_first_key(array $values)
{
    foreach ($values as $key => $_unused) {
        return $key;
    }

    return null;
}

function synk_schedule_merge_table_exists(mysqli $conn): bool
{
    synk_schedule_merge_ensure_table($conn);
    return synk_table_exists($conn, synk_schedule_merge_table_name());
}

function synk_schedule_merge_ensure_table(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $tableName = synk_schedule_merge_table_name();

    if (!synk_table_exists($conn, $tableName)) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `schedule_merge_id` INT NOT NULL AUTO_INCREMENT,
                `owner_offering_id` INT NOT NULL,
                `member_offering_id` INT NOT NULL,
                `merge_scope` VARCHAR(8) NOT NULL DEFAULT 'FULL',
                `created_by` INT NULL,
                `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`schedule_merge_id`),
                UNIQUE KEY `uniq_schedule_merge_member_scope` (`member_offering_id`, `merge_scope`),
                KEY `idx_schedule_merge_owner_scope` (`owner_offering_id`, `merge_scope`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $columns = synk_describe_columns($conn, $tableName);
    $clauses = [];

    if (!isset($columns['merge_scope'])) {
        $clauses[] = "ADD COLUMN merge_scope VARCHAR(8) NOT NULL DEFAULT 'FULL' AFTER member_offering_id";
    }

    if (!isset($columns['created_by'])) {
        $clauses[] = "ADD COLUMN created_by INT NULL";
    }

    if (!isset($columns['date_created'])) {
        $clauses[] = "ADD COLUMN date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
    }

    if (!empty($clauses)) {
        $conn->query("ALTER TABLE `{$tableName}` " . implode(', ', $clauses));
    }

    if (isset($columns['merge_scope']) || !empty($clauses)) {
        $conn->query("
            UPDATE `{$tableName}`
            SET merge_scope = 'FULL'
            WHERE merge_scope IS NULL
               OR TRIM(merge_scope) = ''
        ");
    }

    if (synk_table_has_index($conn, $tableName, 'uniq_schedule_merge_member')) {
        $conn->query("ALTER TABLE `{$tableName}` DROP INDEX `uniq_schedule_merge_member`");
    }

    if (!synk_table_has_index($conn, $tableName, 'uniq_schedule_merge_member_scope')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_schedule_merge_member_scope` (`member_offering_id`, `merge_scope`)");
    }

    if (!synk_table_has_index($conn, $tableName, 'idx_schedule_merge_owner_scope')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_schedule_merge_owner_scope` (`owner_offering_id`, `merge_scope`)");
    }

    if (!synk_table_has_index($conn, $tableName, 'idx_schedule_merge_owner')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_schedule_merge_owner` (`owner_offering_id`)");
    }

    $ensured = true;
}

function synk_schedule_merge_scheduled_offering_join_sql(
    mysqli $conn,
    string $scheduleAlias = 'sched',
    string $offeringAlias = 'o'
): string {
    synk_schedule_merge_ensure_table($conn);
    $tableName = synk_schedule_merge_table_name();

    if (!synk_table_exists($conn, $tableName)) {
        return "
            LEFT JOIN (
                SELECT DISTINCT offering_id
                FROM tbl_class_schedule
            ) {$scheduleAlias}
                ON {$scheduleAlias}.offering_id = {$offeringAlias}.offering_id
        ";
    }

    $scopeMatchSql = synk_schedule_merge_scope_match_sql(
        'owner_sched.schedule_type',
        'merge_map.merge_scope'
    );

    return "
        LEFT JOIN (
            SELECT DISTINCT offering_id
            FROM tbl_class_schedule
            UNION
            SELECT DISTINCT merge_map.member_offering_id AS offering_id
            FROM `{$tableName}` merge_map
            INNER JOIN tbl_class_schedule owner_sched
                ON owner_sched.offering_id = merge_map.owner_offering_id
               AND (
                    merge_map.merge_scope = 'FULL'
                    OR {$scopeMatchSql}
               )
        ) {$scheduleAlias}
            ON {$scheduleAlias}.offering_id = {$offeringAlias}.offering_id
    ";
}

function synk_schedule_merge_load_all_member_to_owner_scope_map(mysqli $conn): array
{
    synk_schedule_merge_ensure_table($conn);

    if (!synk_table_exists($conn, synk_schedule_merge_table_name())) {
        return [];
    }

    $sql = "
        SELECT owner_offering_id, member_offering_id, merge_scope
        FROM `" . synk_schedule_merge_table_name() . "`
    ";

    $rows = [];
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $memberId = (int)($row['member_offering_id'] ?? 0);
            $ownerId = (int)($row['owner_offering_id'] ?? 0);
            if ($memberId <= 0 || $ownerId <= 0) {
                continue;
            }

            $scope = synk_schedule_merge_normalize_scope($row['merge_scope'] ?? 'FULL');
            if (!isset($rows[$memberId])) {
                $rows[$memberId] = [];
            }

            $rows[$memberId][$scope] = $ownerId;
        }
    }

    return $rows;
}

function synk_schedule_merge_load_member_to_owner_scope_map(mysqli $conn, array $offeringIds): array
{
    synk_schedule_merge_ensure_table($conn);

    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds) || !synk_table_exists($conn, synk_schedule_merge_table_name())) {
        return [];
    }

    $idList = implode(',', array_map('intval', $normalizedIds));
    $sql = "
        SELECT owner_offering_id, member_offering_id, merge_scope
        FROM `" . synk_schedule_merge_table_name() . "`
        WHERE member_offering_id IN ({$idList})
    ";

    $rows = [];
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $memberId = (int)($row['member_offering_id'] ?? 0);
            $ownerId = (int)($row['owner_offering_id'] ?? 0);
            if ($memberId <= 0 || $ownerId <= 0) {
                continue;
            }

            $scope = synk_schedule_merge_normalize_scope($row['merge_scope'] ?? 'FULL');
            if (!isset($rows[$memberId])) {
                $rows[$memberId] = [];
            }

            $rows[$memberId][$scope] = $ownerId;
        }
    }

    return $rows;
}

function synk_schedule_merge_load_member_to_owner_map(mysqli $conn, array $offeringIds): array
{
    $scopeMap = synk_schedule_merge_load_member_to_owner_scope_map($conn, $offeringIds);
    $rows = [];

    foreach ($scopeMap as $memberId => $item) {
        $ownerId = (int)($item['FULL'] ?? 0);
        if ($ownerId > 0) {
            $rows[(int)$memberId] = $ownerId;
        }
    }

    return $rows;
}

function synk_schedule_merge_resolve_owner_id(int $offeringId, array $memberToOwner): int
{
    $resolved = $offeringId;
    $visited = [];

    while (isset($memberToOwner[$resolved]) && !isset($visited[$resolved])) {
        $visited[$resolved] = true;
        $candidate = (int)$memberToOwner[$resolved];
        if ($candidate <= 0 || $candidate === $resolved) {
            break;
        }
        $resolved = $candidate;
    }

    return $resolved;
}

function synk_schedule_merge_load_owner_to_members_scope_map(mysqli $conn, array $ownerIds): array
{
    synk_schedule_merge_ensure_table($conn);

    $normalizedIds = synk_schedule_merge_normalize_offering_ids($ownerIds);
    if (empty($normalizedIds) || !synk_table_exists($conn, synk_schedule_merge_table_name())) {
        return [];
    }

    $idList = implode(',', array_map('intval', $normalizedIds));
    $sql = "
        SELECT owner_offering_id, member_offering_id, merge_scope
        FROM `" . synk_schedule_merge_table_name() . "`
        WHERE owner_offering_id IN ({$idList})
        ORDER BY owner_offering_id ASC, merge_scope ASC, member_offering_id ASC
    ";

    $map = [];
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $ownerId = (int)($row['owner_offering_id'] ?? 0);
            $memberId = (int)($row['member_offering_id'] ?? 0);
            if ($ownerId <= 0 || $memberId <= 0) {
                continue;
            }

            $scope = synk_schedule_merge_normalize_scope($row['merge_scope'] ?? 'FULL');
            if (!isset($map[$ownerId])) {
                $map[$ownerId] = [];
            }
            if (!isset($map[$ownerId][$scope])) {
                $map[$ownerId][$scope] = [];
            }

            $map[$ownerId][$scope][] = $memberId;
        }
    }

    foreach ($map as $ownerId => $scopes) {
        foreach (synk_schedule_merge_scope_values() as $scope) {
            $map[$ownerId][$scope] = synk_schedule_merge_normalize_offering_ids((array)($scopes[$scope] ?? []));
        }
    }

    return $map;
}

function synk_schedule_merge_load_owner_to_members_map(mysqli $conn, array $ownerIds): array
{
    $scopeMap = synk_schedule_merge_load_owner_to_members_scope_map($conn, $ownerIds);
    $rows = [];

    foreach ($scopeMap as $ownerId => $item) {
        $rows[(int)$ownerId] = synk_schedule_merge_normalize_offering_ids((array)($item['FULL'] ?? []));
    }

    return $rows;
}

function synk_schedule_merge_resolve_effective_owner_for_type(
    int $offeringId,
    string $scheduleType,
    array $memberScopeMap,
    array &$cache,
    array $path = []
): int {
    $type = strtoupper(trim($scheduleType)) === 'LAB' ? 'LAB' : 'LEC';
    $cacheKey = $offeringId . ':' . $type;
    if (isset($cache[$cacheKey])) {
        return (int)$cache[$cacheKey];
    }

    if (isset($path[$cacheKey])) {
        $cache[$cacheKey] = $offeringId;
        return $offeringId;
    }

    $path[$cacheKey] = true;
    $scopeInfo = (array)($memberScopeMap[$offeringId] ?? []);
    $directOwnerId = (int)($scopeInfo['FULL'] ?? 0);

    if ($directOwnerId <= 0) {
        $directOwnerId = (int)($scopeInfo[$type] ?? 0);
    }

    if ($directOwnerId <= 0 || $directOwnerId === $offeringId) {
        $cache[$cacheKey] = $offeringId;
        return $offeringId;
    }

    $cache[$cacheKey] = synk_schedule_merge_resolve_effective_owner_for_type(
        $directOwnerId,
        $type,
        $memberScopeMap,
        $cache,
        $path
    );

    return (int)$cache[$cacheKey];
}

function synk_schedule_merge_load_effective_owner_map(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    $rows = [];

    if (empty($normalizedIds)) {
        return $rows;
    }

    $memberScopeMap = synk_schedule_merge_load_all_member_to_owner_scope_map($conn);
    $cache = [];

    foreach ($normalizedIds as $offeringId) {
        $rows[$offeringId] = [];
        foreach (synk_schedule_merge_schedule_types() as $type) {
            $rows[$offeringId][$type] = synk_schedule_merge_resolve_effective_owner_for_type(
                $offeringId,
                $type,
                $memberScopeMap,
                $cache
            );
        }
    }

    return $rows;
}

function synk_schedule_merge_collect_effective_owner_ids(array $effectiveOwnerMap): array
{
    $ownerIds = [];

    foreach ($effectiveOwnerMap as $types) {
        foreach (synk_schedule_merge_schedule_types() as $type) {
            $ownerId = (int)($types[$type] ?? 0);
            if ($ownerId > 0) {
                $ownerIds[] = $ownerId;
            }
        }
    }

    return synk_schedule_merge_normalize_offering_ids($ownerIds);
}

function synk_schedule_merge_load_section_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return [];
    }

    $idList = implode(',', array_map('intval', $normalizedIds));
    $sql = "
        SELECT
            o.offering_id,
            COALESCE(NULLIF(TRIM(sec.section_name), ''), CONCAT('Section ', o.section_id)) AS section_name,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.major), ''), '') AS major,
            COALESCE(
                NULLIF(TRIM(sec.full_section), ''),
                CONCAT(
                    COALESCE(NULLIF(TRIM(p.program_code), ''), 'Program'),
                    ' ',
                    COALESCE(NULLIF(TRIM(sec.section_name), ''), CONCAT('Section ', o.section_id))
                )
            ) AS full_section
        FROM tbl_prospectus_offering o
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        LEFT JOIN tbl_sections sec
            ON sec.section_id = o.section_id
        WHERE o.offering_id IN ({$idList})
    ";

    $rows = [];
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $offeringId = (int)($row['offering_id'] ?? 0);
            if ($offeringId <= 0) {
                continue;
            }

            $rows[$offeringId] = [
                'offering_id' => $offeringId,
                'section_name' => (string)($row['section_name'] ?? ''),
                'program_code' => (string)($row['program_code'] ?? ''),
                'major' => (string)($row['major'] ?? ''),
                'full_section' => (string)($row['full_section'] ?? '')
            ];
        }
    }

    return $rows;
}

function synk_schedule_merge_compose_base_section_label(array $row): string
{
    $fullSection = trim((string)($row['full_section'] ?? ''));
    if ($fullSection !== '') {
        return $fullSection;
    }

    $programCode = trim((string)($row['program_code'] ?? ''));
    $sectionName = trim((string)($row['section_name'] ?? ''));
    if ($programCode !== '' && $sectionName !== '') {
        return trim($programCode . ' ' . $sectionName);
    }

    return $sectionName;
}

function synk_schedule_merge_compose_section_major_label(array $row): string
{
    $baseLabel = synk_schedule_merge_compose_base_section_label($row);
    if ($baseLabel === '') {
        return '';
    }

    $major = strtoupper(trim((string)($row['major'] ?? '')));
    if ($major === '') {
        return $baseLabel;
    }

    return $baseLabel . '-' . $major;
}

function synk_schedule_merge_compose_group_label(array $groupRows): string
{
    if (empty($groupRows)) {
        return '';
    }

    $rows = array_values($groupRows);
    usort($rows, static function (array $left, array $right): int {
        $sectionCompare = strnatcasecmp(
            synk_schedule_merge_compose_base_section_label($left),
            synk_schedule_merge_compose_base_section_label($right)
        );
        if ($sectionCompare !== 0) {
            return $sectionCompare;
        }

        return strnatcasecmp(
            strtoupper(trim((string)($left['major'] ?? ''))),
            strtoupper(trim((string)($right['major'] ?? '')))
        );
    });

    $programCodes = [];
    $sectionNames = [];
    $fullSections = [];
    $majorLabelsBySection = [];

    foreach ($rows as $row) {
        $programCode = trim((string)($row['program_code'] ?? ''));
        $sectionName = trim((string)($row['section_name'] ?? ''));
        $fullSection = synk_schedule_merge_compose_base_section_label($row);
        $majorLabel = trim((string)(strtoupper((string)($row['major'] ?? ''))));

        if ($programCode !== '') {
            $programCodes[$programCode] = true;
        }

        if ($sectionName !== '') {
            $sectionNames[$sectionName] = true;
        }

        if ($fullSection !== '') {
            $fullSections[$fullSection] = true;

            if (!isset($majorLabelsBySection[$fullSection])) {
                $majorLabelsBySection[$fullSection] = [];
            }

            $majorLabelsBySection[$fullSection][$majorLabel] = true;
        }
    }

    $needsMajorDisambiguation = false;
    foreach ($majorLabelsBySection as $majorLabels) {
        $normalizedMajorLabels = array_keys($majorLabels);
        if (count($normalizedMajorLabels) > 1) {
            $needsMajorDisambiguation = true;
            break;
        }
    }

    if ($needsMajorDisambiguation) {
        $labels = [];
        foreach ($rows as $row) {
            $label = synk_schedule_merge_compose_section_major_label($row);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        $labelValues = array_keys($labels);
        natcasesort($labelValues);

        return implode('/', $labelValues);
    }

    if (count($fullSections) <= 1) {
        $singleFullSection = synk_schedule_merge_array_first_key($fullSections);
        return $singleFullSection !== null ? (string)$singleFullSection : '';
    }

    if (count($programCodes) === 1 && count($sectionNames) === count($rows)) {
        $programCode = (string)synk_schedule_merge_array_first_key($programCodes);
        $sections = array_keys($sectionNames);
        natcasesort($sections);
        return trim($programCode . ' ' . implode('/', $sections));
    }

    $sections = array_keys($fullSections);
    natcasesort($sections);
    return implode('/', $sections);
}

function synk_schedule_merge_scope_display_context(
    array $mergeInfo,
    string $scope,
    int $offeringId,
    array $sectionRowsByOffering
): array {
    $normalizedScope = synk_schedule_merge_normalize_scope($scope);
    $groupOfferingIds = [];
    $memberOfferingIds = [];
    $mode = 'local';

    $fullGroupOfferingIds = synk_schedule_merge_normalize_offering_ids((array)($mergeInfo['group_offering_ids'] ?? []));
    if (count($fullGroupOfferingIds) > 1) {
        $groupOfferingIds = $fullGroupOfferingIds;
        $mode = 'full';
    } else {
        $ownedMemberIdsByScope = (array)($mergeInfo['owned_member_ids_by_scope'] ?? []);
        $scopeMemberOfferingIds = synk_schedule_merge_normalize_offering_ids((array)($ownedMemberIdsByScope[$normalizedScope] ?? []));

        if (!empty($scopeMemberOfferingIds)) {
            $groupOfferingIds = synk_schedule_merge_normalize_offering_ids(array_merge([$offeringId], $scopeMemberOfferingIds));
            $mode = 'scope';
        }
    }

    if (empty($groupOfferingIds)) {
        $groupOfferingIds = synk_schedule_merge_normalize_offering_ids([$offeringId]);
    }

    foreach ($groupOfferingIds as $groupOfferingId) {
        if ((int)$groupOfferingId !== $offeringId) {
            $memberOfferingIds[] = (int)$groupOfferingId;
        }
    }
    $memberOfferingIds = synk_schedule_merge_normalize_offering_ids($memberOfferingIds);

    $groupRows = [];
    foreach ($groupOfferingIds as $groupOfferingId) {
        if (isset($sectionRowsByOffering[$groupOfferingId]) && is_array($sectionRowsByOffering[$groupOfferingId])) {
            $groupRows[$groupOfferingId] = $sectionRowsByOffering[$groupOfferingId];
        }
    }

    $memberRows = [];
    foreach ($memberOfferingIds as $memberOfferingId) {
        if (isset($sectionRowsByOffering[$memberOfferingId]) && is_array($sectionRowsByOffering[$memberOfferingId])) {
            $memberRows[$memberOfferingId] = $sectionRowsByOffering[$memberOfferingId];
        }
    }

    $groupLabel = synk_schedule_merge_compose_group_label($groupRows);
    if ($groupLabel === '') {
        $groupLabel = trim((string)($mergeInfo['group_course_label'] ?? ''));
    }

    $memberLabel = synk_schedule_merge_compose_group_label($memberRows);

    return [
        'scope' => $normalizedScope,
        'mode' => $mode,
        'is_merged' => count($groupOfferingIds) > 1,
        'group_offering_ids' => $groupOfferingIds,
        'member_offering_ids' => $memberOfferingIds,
        'group_label' => $groupLabel,
        'member_label' => $memberLabel
    ];
}

function synk_schedule_merge_load_display_context(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    $context = [];

    if (empty($normalizedIds)) {
        return $context;
    }

    $memberScopeMap = synk_schedule_merge_load_member_to_owner_scope_map($conn, $normalizedIds);
    $memberToOwner = [];
    foreach ($memberScopeMap as $memberId => $scopes) {
        $ownerId = (int)($scopes['FULL'] ?? 0);
        if ($ownerId > 0) {
            $memberToOwner[(int)$memberId] = $ownerId;
        }
    }

    $resolvedOwnerIds = [];
    foreach ($normalizedIds as $offeringId) {
        $resolvedOwnerIds[] = synk_schedule_merge_resolve_owner_id($offeringId, $memberToOwner);
    }
    $resolvedOwnerIds = synk_schedule_merge_normalize_offering_ids($resolvedOwnerIds);

    $ownerToMembers = synk_schedule_merge_load_owner_to_members_map($conn, $resolvedOwnerIds);
    $allOwnerScopeMap = synk_schedule_merge_load_owner_to_members_scope_map($conn, $normalizedIds);
    $effectiveOwnerByType = synk_schedule_merge_load_effective_owner_map($conn, $normalizedIds);

    $groupOfferingIds = $normalizedIds;
    foreach ($resolvedOwnerIds as $ownerId) {
        $groupOfferingIds[] = $ownerId;
        foreach (($ownerToMembers[$ownerId] ?? []) as $memberId) {
            $groupOfferingIds[] = $memberId;
        }
    }
    $groupOfferingIds = synk_schedule_merge_normalize_offering_ids($groupOfferingIds);

    $sectionRows = synk_schedule_merge_load_section_rows_by_offering($conn, $groupOfferingIds);
    $groupLabelsByOwner = [];

    foreach ($resolvedOwnerIds as $ownerId) {
        $groupIds = [$ownerId];
        foreach (($ownerToMembers[$ownerId] ?? []) as $memberId) {
            $groupIds[] = $memberId;
        }
        $groupIds = synk_schedule_merge_normalize_offering_ids($groupIds);

        $groupRows = [];
        foreach ($groupIds as $groupOfferingId) {
            if (isset($sectionRows[$groupOfferingId])) {
                $groupRows[$groupOfferingId] = $sectionRows[$groupOfferingId];
            }
        }

        $groupLabelsByOwner[$ownerId] = synk_schedule_merge_compose_group_label($groupRows);
    }

    foreach ($normalizedIds as $offeringId) {
        $ownerId = synk_schedule_merge_resolve_owner_id($offeringId, $memberToOwner);
        $memberIds = $ownerToMembers[$ownerId] ?? [];
        $groupIds = synk_schedule_merge_normalize_offering_ids(array_merge([$ownerId], $memberIds));
        $ownRow = $sectionRows[$offeringId] ?? [
            'offering_id' => $offeringId,
            'section_name' => '',
            'program_code' => '',
            'full_section' => ''
        ];

        $incomingScopeOwners = [
            'FULL' => (int)($memberScopeMap[$offeringId]['FULL'] ?? 0),
            'LEC' => (int)($memberScopeMap[$offeringId]['LEC'] ?? 0),
            'LAB' => (int)($memberScopeMap[$offeringId]['LAB'] ?? 0)
        ];
        $resolvedTypes = (array)($effectiveOwnerByType[$offeringId] ?? []);
        $inheritedTypes = [];

        foreach (synk_schedule_merge_schedule_types() as $type) {
            if ((int)($resolvedTypes[$type] ?? $offeringId) !== $offeringId) {
                $inheritedTypes[] = $type;
            }
        }

        $ownedScopes = $allOwnerScopeMap[$offeringId] ?? [];

        $context[$offeringId] = [
            'offering_id' => $offeringId,
            'owner_offering_id' => $ownerId,
            'is_merged_member' => isset($memberToOwner[$offeringId]) && $ownerId !== $offeringId,
            'has_merged_members' => $ownerId === $offeringId && !empty($memberIds),
            'member_offering_ids' => $memberIds,
            'group_offering_ids' => $groupIds,
            'group_size' => count($groupIds),
            'group_course_label' => (string)($groupLabelsByOwner[$ownerId] ?? ($ownRow['full_section'] ?? '')),
            'full_section' => (string)($ownRow['full_section'] ?? ''),
            'section_name' => (string)($ownRow['section_name'] ?? ''),
            'program_code' => (string)($ownRow['program_code'] ?? ''),
            'incoming_scope_owner_ids' => $incomingScopeOwners,
            'effective_owner_by_type' => [
                'LEC' => (int)($resolvedTypes['LEC'] ?? $offeringId),
                'LAB' => (int)($resolvedTypes['LAB'] ?? $offeringId)
            ],
            'inherited_types' => $inheritedTypes,
            'has_any_inherited_types' => !empty($inheritedTypes),
            'owned_member_ids_by_scope' => [
                'FULL' => synk_schedule_merge_normalize_offering_ids((array)($ownedScopes['FULL'] ?? [])),
                'LEC' => synk_schedule_merge_normalize_offering_ids((array)($ownedScopes['LEC'] ?? [])),
                'LAB' => synk_schedule_merge_normalize_offering_ids((array)($ownedScopes['LAB'] ?? []))
            ],
            'has_outgoing_scope_merges' => !empty($ownedScopes['LEC']) || !empty($ownedScopes['LAB'])
        ];
    }

    return $context;
}

function synk_schedule_merge_term_has_rows(mysqli $conn, int $collegeId, int $ayId, int $semester): bool
{
    synk_schedule_merge_ensure_table($conn);

    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0 || !synk_table_exists($conn, synk_schedule_merge_table_name())) {
        return false;
    }

    $sql = "
        SELECT merge_map.schedule_merge_id
        FROM `" . synk_schedule_merge_table_name() . "` merge_map
        INNER JOIN tbl_prospectus_offering owner_offer
            ON owner_offer.offering_id = merge_map.owner_offering_id
        INNER JOIN tbl_program p
            ON p.program_id = owner_offer.program_id
        WHERE owner_offer.ay_id = ?
          AND owner_offer.semester = ?
          AND p.college_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iii', $ayId, $semester, $collegeId);
    $stmt->execute();
    $exists = ($stmt->get_result()->num_rows ?? 0) > 0;
    $stmt->close();

    return $exists;
}

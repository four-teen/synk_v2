<?php

require_once __DIR__ . '/schema_helper.php';

function synk_signatory_settings_table_name(): string
{
    return 'tbl_signatory_settings';
}

function synk_signatory_settings_scope_slot_definitions(string $scopeType): array
{
    $scope = strtolower(trim($scopeType));

    if ($scope === 'college') {
        return [
            'prepared_by' => [
                'label' => 'Prepared by',
                'sort_order' => 10
            ]
        ];
    }

    return [
        'checked_by_left' => [
            'label' => 'Checked by (Left)',
            'sort_order' => 10
        ],
        'checked_by_right' => [
            'label' => 'Checked by (Right)',
            'sort_order' => 20
        ],
        'recommending_approval' => [
            'label' => 'Recommending Approval',
            'sort_order' => 30
        ],
        'approved_by' => [
            'label' => 'Approved by',
            'sort_order' => 40
        ]
    ];
}

function synk_signatory_settings_normalize_scope(string $scopeType, int $scopeId): array
{
    $scope = strtolower(trim($scopeType));
    if ($scope !== 'college') {
        return [
            'scope_type' => 'global',
            'scope_id' => 0
        ];
    }

    return [
        'scope_type' => 'college',
        'scope_id' => max(0, $scopeId)
    ];
}

function synk_signatory_settings_ensure_table(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $tableName = synk_signatory_settings_table_name();

    if (!synk_table_exists($conn, $tableName)) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `signatory_setting_id` INT NOT NULL AUTO_INCREMENT,
                `scope_type` VARCHAR(20) NOT NULL DEFAULT 'global',
                `scope_id` INT NOT NULL DEFAULT 0,
                `slot_code` VARCHAR(50) NOT NULL,
                `faculty_id` INT NULL DEFAULT NULL,
                `signatory_name` VARCHAR(150) NOT NULL DEFAULT '',
                `signatory_title` VARCHAR(150) NOT NULL DEFAULT '',
                `sort_order` SMALLINT NOT NULL DEFAULT 0,
                `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                `updated_by` INT NULL DEFAULT NULL,
                `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_updated` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`signatory_setting_id`),
                UNIQUE KEY `uniq_signatory_scope_slot` (`scope_type`, `scope_id`, `slot_code`),
                KEY `idx_signatory_scope` (`scope_type`, `scope_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
        return;
    }

    $columns = synk_describe_columns($conn, $tableName);
    $clauses = [];

    if (!isset($columns['scope_type'])) {
        $clauses[] = "ADD COLUMN scope_type VARCHAR(20) NOT NULL DEFAULT 'global' AFTER signatory_setting_id";
    }

    if (!isset($columns['scope_id'])) {
        $clauses[] = "ADD COLUMN scope_id INT NOT NULL DEFAULT 0 AFTER scope_type";
    }

    if (!isset($columns['slot_code'])) {
        $clauses[] = "ADD COLUMN slot_code VARCHAR(50) NOT NULL AFTER scope_id";
    }

    if (!isset($columns['faculty_id'])) {
        $clauses[] = "ADD COLUMN faculty_id INT NULL DEFAULT NULL AFTER slot_code";
    }

    if (!isset($columns['signatory_name'])) {
        $clauses[] = "ADD COLUMN signatory_name VARCHAR(150) NOT NULL DEFAULT '' AFTER faculty_id";
    }

    if (!isset($columns['signatory_title'])) {
        $clauses[] = "ADD COLUMN signatory_title VARCHAR(150) NOT NULL DEFAULT '' AFTER signatory_name";
    }

    if (!isset($columns['sort_order'])) {
        $clauses[] = "ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0 AFTER signatory_title";
    }

    if (!isset($columns['status'])) {
        $clauses[] = "ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER sort_order";
    }

    if (!isset($columns['updated_by'])) {
        $clauses[] = "ADD COLUMN updated_by INT NULL DEFAULT NULL AFTER status";
    }

    if (!isset($columns['date_created'])) {
        $clauses[] = "ADD COLUMN date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by";
    }

    if (!isset($columns['date_updated'])) {
        $clauses[] = "ADD COLUMN date_updated DATETIME NULL DEFAULT NULL AFTER date_created";
    }

    if (!empty($clauses)) {
        $conn->query("ALTER TABLE `{$tableName}` " . implode(', ', $clauses));
    }

    if (!synk_table_has_index($conn, $tableName, 'uniq_signatory_scope_slot')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_signatory_scope_slot` (`scope_type`, `scope_id`, `slot_code`)");
    }

    if (!synk_table_has_index($conn, $tableName, 'idx_signatory_scope')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_signatory_scope` (`scope_type`, `scope_id`, `status`)");
    }

    $ensured = true;
}

function synk_fetch_signatory_settings(mysqli $conn, string $scopeType = 'global', int $scopeId = 0): array
{
    synk_signatory_settings_ensure_table($conn);

    $scope = synk_signatory_settings_normalize_scope($scopeType, $scopeId);
    $definitions = synk_signatory_settings_scope_slot_definitions($scope['scope_type']);
    $settings = [];

    foreach ($definitions as $slotCode => $meta) {
        $settings[$slotCode] = [
            'slot_code' => $slotCode,
            'label' => (string)($meta['label'] ?? $slotCode),
            'faculty_id' => 0,
            'signatory_name' => '',
            'signatory_title' => '',
            'sort_order' => (int)($meta['sort_order'] ?? 0)
        ];
    }

    $stmt = $conn->prepare("
        SELECT
            slot_code,
            faculty_id,
            signatory_name,
            signatory_title,
            sort_order
        FROM `" . synk_signatory_settings_table_name() . "`
        WHERE scope_type = ?
          AND scope_id = ?
          AND status = 'active'
        ORDER BY sort_order ASC, signatory_setting_id ASC
    ");

    if ($stmt) {
        $stmt->bind_param('si', $scope['scope_type'], $scope['scope_id']);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($row = $res->fetch_assoc())) {
            $slotCode = trim((string)($row['slot_code'] ?? ''));
            if ($slotCode === '' || !isset($settings[$slotCode])) {
                continue;
            }

            $settings[$slotCode]['signatory_name'] = trim((string)($row['signatory_name'] ?? ''));
            $settings[$slotCode]['signatory_title'] = trim((string)($row['signatory_title'] ?? ''));
            $settings[$slotCode]['faculty_id'] = (int)($row['faculty_id'] ?? 0);
            $settings[$slotCode]['sort_order'] = (int)($row['sort_order'] ?? $settings[$slotCode]['sort_order']);
        }

        $stmt->close();
    }

    uasort($settings, function (array $left, array $right): int {
        $orderCompare = (int)($left['sort_order'] ?? 0) <=> (int)($right['sort_order'] ?? 0);
        if ($orderCompare !== 0) {
            return $orderCompare;
        }

        return strcmp((string)($left['slot_code'] ?? ''), (string)($right['slot_code'] ?? ''));
    });

    return $settings;
}

function synk_save_signatory_settings(
    mysqli $conn,
    string $scopeType,
    int $scopeId,
    array $payload,
    int $userId = 0
): bool {
    synk_signatory_settings_ensure_table($conn);

    $scope = synk_signatory_settings_normalize_scope($scopeType, $scopeId);
    $definitions = synk_signatory_settings_scope_slot_definitions($scope['scope_type']);
    $tableName = synk_signatory_settings_table_name();

    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            scope_type,
            scope_id,
            slot_code,
            faculty_id,
            signatory_name,
            signatory_title,
            sort_order,
            status,
            updated_by,
            date_updated
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, NOW())
        ON DUPLICATE KEY UPDATE
            faculty_id = VALUES(faculty_id),
            signatory_name = VALUES(signatory_name),
            signatory_title = VALUES(signatory_title),
            sort_order = VALUES(sort_order),
            status = 'active',
            updated_by = VALUES(updated_by),
            date_updated = NOW()
    ");

    if (!$stmt) {
        return false;
    }

    foreach ($definitions as $slotCode => $meta) {
        $row = is_array($payload[$slotCode] ?? null) ? $payload[$slotCode] : [];
        $facultyId = (int)($row['faculty_id'] ?? 0);
        if ($facultyId < 0) {
            $facultyId = 0;
        }
        $signatoryName = trim((string)($row['signatory_name'] ?? ''));
        $signatoryTitle = trim((string)($row['signatory_title'] ?? ''));
        $sortOrder = (int)($meta['sort_order'] ?? 0);

        $stmt->bind_param(
            'sisissii',
            $scope['scope_type'],
            $scope['scope_id'],
            $slotCode,
            $facultyId,
            $signatoryName,
            $signatoryTitle,
            $sortOrder,
            $userId
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
    }

    $stmt->close();
    return true;
}

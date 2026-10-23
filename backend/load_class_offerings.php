<?php
session_start();
include 'db.php';
require_once __DIR__ . '/scheduler_request_guard.php';
synk_scheduler_guard_request($conn, 'load_class_offerings.php');
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'scheduler') {
    echo "<div class='text-center text-danger py-4'>Unauthorized access.</div>";
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo "<div class='text-center text-danger py-4'>CSRF validation failed.</div>";
    exit;
}

$program_id = $_POST['program_id'] ?? '';
$ay_id = $_POST['ay_id'] ?? '';
$semester = $_POST['semester'] ?? '';
$sortBy = trim((string)($_POST['sort_by'] ?? 'year_level'));
$sortBy = in_array($sortBy, ['year_level', 'subject'], true) ? $sortBy : 'year_level';

if ($program_id === '' || $ay_id === '' || $semester === '') {
    echo "<div class='text-center text-danger py-4'>Missing filters.</div>";
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
if ($college_id <= 0) {
    echo "<div class='text-center text-danger py-4'>Missing college context.</div>";
    exit;
}

function year_label($year) {
    $year = (string)$year;
    if ($year === '1') return '1st Year';
    if ($year === '2') return '2nd Year';
    if ($year === '3') return '3rd Year';
    if ($year === '4') return '4th Year';
    if ($year === '5') return '5th Year';
    if ($year === '6') return '6th Year';
    return 'Year ' . $year;
}

function section_curriculum_table_exists(mysqli $conn): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $q = $conn->query("SHOW TABLES LIKE 'tbl_section_curriculum'");
    $exists = $q && $q->num_rows > 0;
    return $exists;
}

function curriculum_label(array $row): string
{
    $effectiveSy = trim((string)($row['effective_sy'] ?? ''));
    if ($effectiveSy === '') {
        return 'Curriculum';
    }

    return 'SY ' . $effectiveSy;
}

function schedule_group_count_label(string $sortBy): string
{
    return $sortBy === 'subject' ? 'section(s)' : 'class(es)';
}

function schedule_group_title(string $sortBy, array $row): string
{
    if ($sortBy === 'subject') {
        return strtoupper(trim((string)$row['sub_code'])) . ' - ' . strtoupper(trim((string)$row['sub_description']));
    }

    return year_label((string)($row['year_level'] ?? ''));
}

function offering_required_schedule_types(array $row): array
{
    return ((float)($row['lab_units'] ?? 0) > 0) ? ['LEC', 'LAB'] : ['LEC'];
}

function schedule_merge_offering_label_from_lookup(int $offeringId, array $sectionRowsByOffering): string
{
    $row = $sectionRowsByOffering[$offeringId] ?? [];
    $label = trim((string)($row['full_section'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $sectionName = trim((string)($row['section_name'] ?? ''));
    if ($sectionName !== '') {
        $programCode = trim((string)($row['program_code'] ?? ''));
        return trim($programCode . ' ' . $sectionName);
    }

    return $offeringId > 0 ? ('Offering ' . $offeringId) : '';
}

function schedule_merge_group_label_from_lookup(array $offeringIds, array $sectionRowsByOffering): string
{
    $groupRows = [];
    foreach (synk_schedule_merge_normalize_offering_ids($offeringIds) as $offeringId) {
        if (isset($sectionRowsByOffering[$offeringId]) && is_array($sectionRowsByOffering[$offeringId])) {
            $groupRows[$offeringId] = $sectionRowsByOffering[$offeringId];
        }
    }

    return synk_schedule_merge_compose_group_label($groupRows);
}

function build_offering_scope_notes(int $offeringId, array $row, array $mergeInfo, array $sectionRowsByOffering): array
{
    $sectionNotes = [];
    $entryNotesByType = ['LEC' => '', 'LAB' => ''];
    $pendingNotesByType = ['LEC' => '', 'LAB' => ''];
    $touchedTypes = [];

    foreach (offering_required_schedule_types($row) as $type) {
        $scopeLabel = $type === 'LAB' ? 'Laboratory' : 'Lecture';
        $incomingOwnerId = (int)($mergeInfo['incoming_scope_owner_ids'][$type] ?? 0);
        if ($incomingOwnerId > 0 && $incomingOwnerId !== $offeringId) {
            $ownerLabel = schedule_merge_offering_label_from_lookup($incomingOwnerId, $sectionRowsByOffering);
            $sectionNotes[] = $ownerLabel !== ''
                ? ($scopeLabel . ' from ' . $ownerLabel)
                : ($scopeLabel . ' inherited');
            $entryNotesByType[$type] = 'View only';
            $pendingNotesByType[$type] = $scopeLabel . ' inherited';
            $touchedTypes[] = $type;
            continue;
        }

        $memberIds = synk_schedule_merge_normalize_offering_ids((array)($mergeInfo['owned_member_ids_by_scope'][$type] ?? []));
        if (!empty($memberIds)) {
            $memberLabel = schedule_merge_group_label_from_lookup($memberIds, $sectionRowsByOffering);
            $sectionNotes[] = $memberLabel !== ''
                ? ($scopeLabel . ' shared with ' . $memberLabel)
                : ($scopeLabel . ' shared');
            $entryNotesByType[$type] = 'Shared';
            $pendingNotesByType[$type] = $scopeLabel . ' shared';
            $touchedTypes[] = $type;
        }
    }

    if (!empty($touchedTypes)) {
        foreach (offering_required_schedule_types($row) as $type) {
            if (in_array($type, $touchedTypes, true)) {
                continue;
            }

            $scopeLabel = $type === 'LAB' ? 'Laboratory' : 'Lecture';
            $sectionNotes[] = $scopeLabel . ' stays local';
            $pendingNotesByType[$type] = 'Pending local ' . strtolower($scopeLabel);
        }
    }

    return [
        'section_notes' => array_values(array_unique(array_filter($sectionNotes))),
        'entry_notes_by_type' => $entryNotesByType,
        'pending_notes_by_type' => $pendingNotesByType
    ];
}

function schedule_section_cell_html(array $row, string $sortBy): string
{
    $sectionLabel = trim((string)($row['section_name'] ?? ''));
    if ($sectionLabel === '') {
        $sectionLabel = trim((string)($row['full_section'] ?? ''));
    }

    $html = "<span class='schedule-section-name'>" . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . "</span>";

    if ($sortBy === 'subject') {
        $yearMeta = htmlspecialchars(year_label((string)($row['year_level'] ?? '')), ENT_QUOTES, 'UTF-8');
        $html .= "<span class='schedule-section-meta'>{$yearMeta}</span>";
    }

    $curriculumMeta = trim((string)($row['curriculum_label'] ?? ''));
    if ($curriculumMeta !== '') {
        $html .= "<span class='schedule-section-meta'>" . htmlspecialchars($curriculumMeta, ENT_QUOTES, 'UTF-8') . "</span>";
    }

    $merge = is_array($row['merge'] ?? null) ? $row['merge'] : [];
    $groupSize = (int)($merge['group_size'] ?? 1);
    $groupLabel = trim((string)($merge['group_course_label'] ?? ''));
    if ($groupSize > 1 && $groupLabel !== '') {
        $prefix = !empty($merge['is_merged_member']) ? 'Inherited group: ' : 'Merged group: ';
        $html .= "<span class='schedule-section-merge'>" .
            htmlspecialchars($prefix . $groupLabel, ENT_QUOTES, 'UTF-8') .
            "</span>";
    }

    $scopeNotes = array_values(array_filter((array)($row['merge_scope_notes'] ?? []), static function ($note): bool {
        return trim((string)$note) !== '';
    }));
    if (!empty($scopeNotes)) {
        foreach ($scopeNotes as $note) {
            $html .= "<span class='schedule-section-merge'>" .
                htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8') .
                "</span>";
        }
    } else {
        $inheritedTypes = array_values(array_filter(array_map(static function ($type): string {
            return synk_normalize_schedule_type((string)$type);
        }, (array)($merge['inherited_types'] ?? []))));
        if (!empty($inheritedTypes) && empty($merge['is_merged_member'])) {
            $html .= "<span class='schedule-section-meta'>" .
                htmlspecialchars('Inherited: ' . implode(', ', $inheritedTypes), ENT_QUOTES, 'UTF-8') .
                "</span>";
        }
    }

    return $html;
}

function block_badge_html($type, $sequence) {
    $type = synk_normalize_schedule_type((string)$type);
    $badgeClass = $type === 'LAB'
        ? 'bg-label-success text-success'
        : 'bg-label-primary text-primary';
    $prefix = $type === 'LAB' ? 'LAB' : 'LEC';
    return "<span class='badge {$badgeClass} me-1'>{$prefix} {$sequence}</span>";
}

function contact_hours_value($hours) {
    $value = (float)$hours;
    return floor($value) == $value
        ? number_format($value, 0)
        : number_format($value, 1);
}

function normalize_prospectus_contact_hours(float $lecHours, float $labValue, ?float $storedTotalUnits = null): array
{
    $safeLecHours = max(0.0, $lecHours);
    $safeLabValue = max(0.0, $labValue);
    $safeTotalUnits = (float)($storedTotalUnits ?? 0.0);
    $labContactHours = synk_lab_contact_hours($safeLecHours, $safeLabValue, $safeTotalUnits);

    return [
        'lec_units' => round($safeLecHours, 2),
        'lab_units' => round($labContactHours, 2),
        'total_units' => round(synk_subject_units_total($safeLecHours, $labContactHours, 0.0), 2)
    ];
}

function schedule_stack_item_html($badgeHtml, $value, $valueClass = '') {
    $className = 'schedule-stack-value';
    if ($valueClass !== '') {
        $className .= ' ' . trim($valueClass);
    }

    return
        "<div class='schedule-stack-item'>" .
            "<div class='schedule-stack-badge'>{$badgeHtml}</div>" .
            "<div class='{$className}'>" . htmlspecialchars((string)$value) . "</div>" .
        "</div>";
}

function schedule_room_stack_item_html($badgeHtml, $value, $roomId, $scheduleType, $effectiveOfferingId) {
    $safeRoomId = (int)$roomId;
    $safeType = htmlspecialchars((string)$scheduleType, ENT_QUOTES, 'UTF-8');
    $safeOfferingId = (int)$effectiveOfferingId;

    return
        "<div class='schedule-stack-item schedule-room-entry' data-room-id='{$safeRoomId}' data-schedule-type='{$safeType}' data-offering-id='{$safeOfferingId}'>" .
            "<div class='schedule-stack-badge'>{$badgeHtml}</div>" .
            "<div class='schedule-stack-value is-room'>" . htmlspecialchars((string)$value) . "</div>" .
        "</div>";
}

function schedule_stack_empty_html() {
    return "<div class='schedule-stack-item empty'><div class='schedule-stack-value text-muted'>-</div></div>";
}

function offering_schedule_status_badge(array $row): array
{
    $required = synk_required_minutes_by_type(
        (float)$row['lec_units'],
        (float)$row['lab_units'],
        (float)$row['total_units']
    );
    $scheduled = synk_sum_scheduled_minutes_by_type($row['entries']);
    $requiredTypes = ((float)$row['lab_units'] > 0) ? ['LEC', 'LAB'] : ['LEC'];
    $hasAny = false;
    $isComplete = true;

    foreach ($requiredTypes as $type) {
        $requiredMinutes = (int)($required[$type] ?? 0);
        $scheduledMinutes = (int)($scheduled[$type] ?? 0);

        if ($scheduledMinutes > 0) {
            $hasAny = true;
        }

        if ($requiredMinutes > 0 && $scheduledMinutes < $requiredMinutes) {
            $isComplete = false;
        }
    }

    $hasTba = false;
    foreach ($row['entries'] as $entry) { if (synk_schedule_status($entry) !== 'scheduled') { $hasTba = true; break; } }
    if ($hasTba) {
        return ['status_key'=>'tba','badge'=>"<span class='badge bg-warning text-dark'>TBA — Schedule Pending</span>",
            'button_label'=>'Arrange TBA','button_class'=>'btn-warning'];
    }
    if (!$hasAny) {
        return [
            'status_key' => 'not_scheduled',
            'badge' => "<span class='badge bg-secondary'>Not Scheduled</span>",
            'button_label' => 'Schedule',
            'button_class' => 'btn-primary'
        ];
    }

    if ($isComplete) {
        return [
            'status_key' => 'complete',
            'badge' => "<span class='badge bg-success'>Scheduled</span>",
            'button_label' => 'Edit',
            'button_class' => 'btn-warning'
        ];
    }

    return [
        'status_key' => 'partial',
        'badge' => "<span class='badge bg-warning text-dark'>Incomplete</span>",
        'button_label' => 'Edit',
        'button_class' => 'btn-warning'
    ];
}

function render_offering_status_cell_html(array $row, array $status): string
{
    $merge = is_array($row['merge'] ?? null) ? $row['merge'] : [];
    $badges = [];
    $scheduledByType = synk_sum_scheduled_minutes_by_type((array)($row['entries'] ?? []));
    $requiredTypes = offering_required_schedule_types($row);
    $inheritedTypes = array_values(array_filter(array_map(static function ($type): string {
        return synk_normalize_schedule_type((string)$type);
    }, (array)($merge['inherited_types'] ?? []))));

    if (!empty($merge['is_merged_member'])) {
        $badges[] = "<span class='badge bg-label-info text-info'>Merged</span>";

        if (($status['status_key'] ?? '') === 'complete') {
            $badges[] = "<span class='badge bg-success'>Inherited</span>";
        } elseif (($status['status_key'] ?? '') === 'partial') {
            $badges[] = "<span class='badge bg-warning text-dark'>Inherited Incomplete</span>";
        } else {
            $badges[] = "<span class='badge bg-secondary'>Awaiting Owner</span>";
        }
    } elseif (!empty($merge['has_merged_members'])) {
        $badges[] = $status['badge'];
        $badges[] = "<span class='badge bg-label-info text-info'>Merged x" . (int)($merge['group_size'] ?? 1) . "</span>";
    } else {
        $badges[] = $status['badge'];

        foreach ($inheritedTypes as $type) {
            $tone = $type === 'LAB' ? 'success' : 'primary';
            $label = $type === 'LAB' ? 'LAB Inherited' : 'LEC Inherited';
            $badges[] = "<span class='badge bg-label-{$tone} text-{$tone}'>{$label}</span>";
        }

        if (($status['status_key'] ?? '') === 'partial') {
            foreach ($requiredTypes as $type) {
                if ((int)($scheduledByType[$type] ?? 0) > 0) {
                    continue;
                }

                $label = $type === 'LAB' ? 'LAB Pending' : 'LEC Pending';
                $badges[] = "<span class='badge bg-label-warning text-warning'>{$label}</span>";
            }
        }
    }

    $html = [];
    foreach ($badges as $badge) {
        $html[] = "<div class='schedule-status-item'>{$badge}</div>";
    }

    foreach ((array)($row['merge_scope_notes'] ?? []) as $note) {
        if (trim((string)$note) === '') {
            continue;
        }

        $html[] = "<div class='schedule-status-note'>" . htmlspecialchars((string)$note, ENT_QUOTES, 'UTF-8') . "</div>";
    }

    $facultyAssignments = array_values(array_filter(
        (array)($row['workload_faculty_assignments'] ?? []),
        static function ($assignment): bool {
            return is_array($assignment)
                && (int)($assignment['faculty_id'] ?? 0) > 0
                && trim((string)($assignment['faculty_name'] ?? '')) !== '';
        }
    ));

    if (!empty($facultyAssignments)) {
        $contextParts = array_filter([
            trim((string)($row['sub_code'] ?? '')),
            trim((string)($row['section_name'] ?? ''))
        ]);
        $contextLabel = implode(' | ', $contextParts);
        $ownerOfferingId = (int)($row['schedule_owner_offering_id'] ?? ($row['offering_id'] ?? 0));
        $facultyButtons = array_map(static function (array $assignment) use ($ownerOfferingId, $contextLabel): string {
            return
                "<button type='button' class='schedule-status-faculty-link'" .
                " data-faculty-id='" . (int)($assignment['faculty_id'] ?? 0) . "'" .
                " data-faculty-name='" . htmlspecialchars((string)($assignment['faculty_name'] ?? ''), ENT_QUOTES, 'UTF-8') . "'" .
                " data-offering-id='" . $ownerOfferingId . "'" .
                " data-context-label='" . htmlspecialchars($contextLabel, ENT_QUOTES, 'UTF-8') . "'>" .
                htmlspecialchars((string)($assignment['faculty_name'] ?? ''), ENT_QUOTES, 'UTF-8') .
                "</button>";
        }, $facultyAssignments);

        $html[] = "<div class='schedule-status-faculty-list'>" . implode('', $facultyButtons) . "</div>";
    }

    return "<div class='schedule-status-stack'>" . implode('', $html) . "</div>";
}

function render_schedule_action_cell_html(array $row, array $status): string
{
    $merge = is_array($row['merge'] ?? null) ? $row['merge'] : [];
    $isMergedMember = !empty($merge['is_merged_member']);
    $groupSize = (int)($merge['group_size'] ?? 1);
    $groupLabel = trim((string)($merge['group_course_label'] ?? ''));
    $inheritedTypes = array_values(array_filter(array_map(static function ($type): string {
        return synk_normalize_schedule_type((string)$type);
    }, (array)($merge['inherited_types'] ?? []))));
    $requiredTypes = ((float)($row['lab_units'] ?? 0) > 0) ? ['LEC', 'LAB'] : ['LEC'];
    $allRequiredInherited = !empty($requiredTypes) && count(array_intersect($requiredTypes, $inheritedTypes)) === count($requiredTypes);
    $hasOutgoingPartialMerges = !empty((array)($merge['owned_member_ids_by_scope']['LEC'] ?? [])) || !empty((array)($merge['owned_member_ids_by_scope']['LAB'] ?? []));
    $mergeButtonLabel = ($groupSize > 1 || !empty($inheritedTypes) || $hasOutgoingPartialMerges) ? 'Manage' : 'Merge';

    $scheduleButtonDisabled = ($isMergedMember || $allRequiredInherited) ? ' disabled' : '';
    $scheduleButtonClass = ($isMergedMember || $allRequiredInherited) ? 'btn-outline-secondary' : (string)$status['button_class'];
    $scheduleButtonLabel = ($isMergedMember || $allRequiredInherited) ? 'Inherited' : (string)$status['button_label'];

    $sectionName = (string)($row['section_name'] ?? '');
    $sectionLabel = (string)($row['schedule_modal_section_label'] ?? ('Section: ' . $sectionName));

    $html = [];
    $html[] =
        "<button type='button' class='btn {$scheduleButtonClass} btn-sm btn-schedule'" .
        " data-offering-id='" . (int)$row['offering_id'] . "'" .
        " data-sub-code='" . htmlspecialchars((string)$row['sub_code'], ENT_QUOTES, 'UTF-8') . "'" .
        " data-sub-desc='" . htmlspecialchars((string)$row['sub_description'], ENT_QUOTES, 'UTF-8') . "'" .
        " data-section='" . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') . "'" .
        " data-section-label='" . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . "'" .
        " data-lab-units='" . (float)$row['lab_units'] . "'" .
        " data-lec-units='" . (float)$row['lec_units'] . "'" .
        " data-total-units='" . (float)$row['total_units'] . "'" .
        $scheduleButtonDisabled . ">" .
        htmlspecialchars($scheduleButtonLabel, ENT_QUOTES, 'UTF-8') .
        "</button>";

    $html[] =
        "<button type='button' class='btn btn-outline-info btn-sm btn-merge-schedule'" .
        " data-offering-id='" . (int)$row['offering_id'] . "'" .
        " data-owner-offering-id='" . (int)($merge['owner_offering_id'] ?? $row['offering_id']) . "'" .
        " data-sub-code='" . htmlspecialchars((string)$row['sub_code'], ENT_QUOTES, 'UTF-8') . "'" .
        " data-sub-desc='" . htmlspecialchars((string)$row['sub_description'], ENT_QUOTES, 'UTF-8') . "'" .
        " data-section='" . htmlspecialchars($sectionName, ENT_QUOTES, 'UTF-8') . "'" .
        " data-group-label='" . htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') . "'>" .
        htmlspecialchars($mergeButtonLabel, ENT_QUOTES, 'UTF-8') .
        "</button>";

    return "<div class='schedule-action-stack'>" . implode('', $html) . "</div>";
}

function default_merge_context_for_offering(int $offeringId, array $row): array
{
    return [
        'offering_id' => $offeringId,
        'owner_offering_id' => $offeringId,
        'is_merged_member' => false,
        'has_merged_members' => false,
        'member_offering_ids' => [],
        'group_offering_ids' => [$offeringId],
        'group_size' => 1,
        'group_course_label' => (string)($row['full_section'] ?? ''),
        'full_section' => (string)($row['full_section'] ?? ''),
        'section_name' => (string)($row['section_name'] ?? ''),
        'program_code' => (string)($row['program_code'] ?? ''),
        'incoming_scope_owner_ids' => ['FULL' => 0, 'LEC' => 0, 'LAB' => 0],
        'effective_owner_by_type' => ['LEC' => $offeringId, 'LAB' => $offeringId],
        'inherited_types' => [],
        'owned_member_ids_by_scope' => ['FULL' => [], 'LEC' => [], 'LAB' => []]
    ];
}

function load_schedule_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $rowsByOffering = [];
    $safeIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($safeIds)) {
        return $rowsByOffering;
    }

    $sql = "
        SELECT
            cs.offering_id,
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            r.room_name,
            r.room_code
        FROM tbl_class_schedule cs
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $safeIds)) . ")
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY cs.offering_id ASC, FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.schedule_id ASC
    ";

    $res = $conn->query($sql);
    if (!($res instanceof mysqli_result)) {
        return $rowsByOffering;
    }

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($rowsByOffering[$offeringId])) {
            $rowsByOffering[$offeringId] = [];
        }

        $scheduleType = strtoupper(trim((string)($row['schedule_type'] ?? '')));
        if (!in_array($scheduleType, ['LEC', 'LAB'], true)) {
            continue;
        }

        $roomLabel = trim((string)($row['room_code'] ?? ''));
        if ($roomLabel === '') {
            $roomLabel = trim((string)($row['room_name'] ?? ''));
        }

        $days = synk_normalize_schedule_days(json_decode((string)($row['days_json'] ?? ''), true));

        $rowsByOffering[$offeringId][] = [
            'schedule_id' => (int)$row['schedule_id'],
            'schedule_type' => synk_normalize_schedule_type($scheduleType),
            'schedule_status' => synk_schedule_status($row),
            'room_id' => (int)($row['room_id'] ?? 0),
            'days_json' => json_encode($days),
            'days' => $days,
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'room_label' => $roomLabel
        ];
    }

    return $rowsByOffering;
}

function load_effective_schedule_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $rowsByOffering = [];
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return $rowsByOffering;
    }

    $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $normalizedIds);
    $sourceIds = synk_schedule_merge_collect_effective_owner_ids($effectiveOwnerMap);
    $sourceRowsByOffering = load_schedule_rows_by_offering($conn, $sourceIds);
    $sectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering($conn, array_merge($normalizedIds, $sourceIds));

    foreach ($normalizedIds as $offeringId) {
        $rowsByOffering[$offeringId] = [];

        foreach (synk_schedule_merge_schedule_types() as $type) {
            $sourceOfferingId = (int)($effectiveOwnerMap[$offeringId][$type] ?? $offeringId);
            foreach ((array)($sourceRowsByOffering[$sourceOfferingId] ?? []) as $row) {
                if (synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')) !== $type) {
                    continue;
                }

                $sourceLabel = schedule_merge_offering_label_from_lookup($sourceOfferingId, $sectionRowsByOffering);
                $rowsByOffering[$offeringId][] = array_merge($row, [
                    'source_offering_id' => $sourceOfferingId,
                    'is_inherited' => $sourceOfferingId !== $offeringId,
                    'source_label' => $sourceLabel
                ]);
            }
        }
    }

    return $rowsByOffering;
}

function workload_faculty_short_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    if ($lastName === '' && $firstName === '') {
        return '';
    }

    $initial = '';
    if ($firstName !== '') {
        $initial = function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($firstName, 0, 1))
            : strtoupper(substr($firstName, 0, 1));
    }

    if ($lastName !== '' && $initial !== '') {
        return "{$lastName}, {$initial}";
    }

    return $lastName !== '' ? $lastName : $firstName;
}

function load_workload_faculty_assignments_for_offerings(mysqli $conn, array $offeringIds, int $ay_id, int $semester): array
{
    $map = [];
    $safeIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($safeIds)) {
        return $map;
    }

    $sql = "
        SELECT
            cs.offering_id,
            fw.faculty_id,
            f.last_name,
            f.first_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $safeIds)) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
        GROUP BY cs.offering_id, fw.faculty_id, f.last_name, f.first_name
        ORDER BY f.last_name ASC, f.first_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $map;
    }

    $stmt->bind_param("ii", $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        $facultyId = (int)($row['faculty_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $name = workload_faculty_short_name($row);
        if ($facultyId <= 0 || $name === '') {
            continue;
        }

        if (!isset($map[$offeringId])) {
            $map[$offeringId] = [];
        }

        $alreadyAdded = false;
        foreach ($map[$offeringId] as $existingAssignment) {
            if ((int)($existingAssignment['faculty_id'] ?? 0) === $facultyId) {
                $alreadyAdded = true;
                break;
            }
        }

        if (!$alreadyAdded) {
            $map[$offeringId][] = [
                'faculty_id' => $facultyId,
                'faculty_name' => $name
            ];
        }
    }

    $stmt->close();
    return $map;
}

if (!section_curriculum_table_exists($conn)) {
    echo "<div class='text-center text-danger py-4'>Create tbl_section_curriculum first before loading the scheduling list.</div>";
    exit;
}

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$orderByClause = $sortBy === 'subject'
    ? "
ORDER BY
    sm.sub_code ASC,
    sm.sub_description ASC,
    sec.year_level ASC,
    sec.section_name ASC,
    o.offering_id ASC
"
    : "
ORDER BY
    sec.year_level ASC,
    sec.section_name ASC,
    sm.sub_code ASC,
    o.offering_id ASC
";

$sql = "
SELECT
    o.offering_id,
    o.status AS offering_status,
    sec.section_name,
    sec.full_section,
    sec.year_level,
    p.program_code,
    ph.effective_sy,
    sm.sub_code,
    sm.sub_description,
    ps.lab_units,
    ps.lec_units,
    ps.total_units
FROM tbl_prospectus_offering o
{$liveOfferingJoins}
INNER JOIN tbl_program p ON p.program_id = o.program_id
INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
WHERE o.program_id = ?
  AND o.ay_id = ?
  AND o.semester = ?
  AND p.college_id = ?
" . $orderByClause . "
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiii", $program_id, $ay_id, $semester, $college_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo "<div class='text-center text-muted py-4'>No class offerings found.</div>";
    exit;
}

$offeringsById = [];
$offeringOrder = [];
while ($row = $res->fetch_assoc()) {
    $offeringId = (int)$row['offering_id'];
    $displayHours = normalize_prospectus_contact_hours(
        (float)$row['lec_units'],
        (float)$row['lab_units'],
        isset($row['total_units']) ? (float)$row['total_units'] : null
    );

    $offeringsById[$offeringId] = [
        'offering_id' => $offeringId,
        'offering_status' => strtolower(trim((string)($row['offering_status'] ?? 'pending'))),
        'year_level' => (int)$row['year_level'],
        'section_name' => (string)($row['section_name'] ?? ''),
        'full_section' => (string)($row['full_section'] ?? ''),
        'program_code' => (string)($row['program_code'] ?? ''),
        'curriculum_label' => curriculum_label($row),
        'sub_code' => (string)($row['sub_code'] ?? ''),
        'sub_description' => (string)($row['sub_description'] ?? ''),
        'lab_units' => (float)$row['lab_units'],
        'lec_units' => (float)$row['lec_units'],
        'total_units' => (float)$row['total_units'],
        'display_lab_units' => (float)$displayHours['lab_units'],
        'display_lec_units' => (float)$displayHours['lec_units'],
        'entries' => []
    ];
    $offeringOrder[] = $offeringId;
}
$stmt->close();

$mergeContext = synk_schedule_merge_load_display_context($conn, $offeringOrder);
$effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $offeringOrder);
$effectiveSourceIds = synk_schedule_merge_collect_effective_owner_ids($effectiveOwnerMap);
$scheduleRowsByOffering = load_effective_schedule_rows_by_offering($conn, $offeringOrder);
$workloadFacultyMap = load_workload_faculty_assignments_for_offerings($conn, $effectiveSourceIds, (int)$ay_id, (int)$semester);
$mergeSectionLookupIds = $offeringOrder;
foreach ($mergeContext as $mergeInfo) {
    foreach ((array)($mergeInfo['group_offering_ids'] ?? []) as $groupOfferingId) {
        $mergeSectionLookupIds[] = (int)$groupOfferingId;
    }

    foreach ((array)($mergeInfo['incoming_scope_owner_ids'] ?? []) as $ownerOfferingId) {
        $mergeSectionLookupIds[] = (int)$ownerOfferingId;
    }

    foreach (['FULL', 'LEC', 'LAB'] as $scope) {
        foreach ((array)(($mergeInfo['owned_member_ids_by_scope'] ?? [])[$scope] ?? []) as $memberOfferingId) {
            $mergeSectionLookupIds[] = (int)$memberOfferingId;
        }
    }
}
$mergeSectionLookupIds = synk_schedule_merge_normalize_offering_ids($mergeSectionLookupIds);
$sectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering($conn, $mergeSectionLookupIds);

foreach ($offeringOrder as $offeringId) {
    $row = $offeringsById[$offeringId];
    $mergeInfo = $mergeContext[$offeringId] ?? default_merge_context_for_offering($offeringId, $row);
    $ownerOfferingId = (int)($mergeInfo['owner_offering_id'] ?? $offeringId);
    $effectiveOwnersByType = (array)($effectiveOwnerMap[$offeringId] ?? []);
    $effectiveSourceIdsForRow = synk_schedule_merge_normalize_offering_ids(array_values($effectiveOwnersByType));
    $primarySourceOfferingId = !empty($effectiveSourceIdsForRow) ? (int)$effectiveSourceIdsForRow[0] : $offeringId;
    $ownerSection = $sectionRowsByOffering[$primarySourceOfferingId] ?? [
        'full_section' => (string)($row['full_section'] ?? ''),
        'section_name' => (string)($row['section_name'] ?? '')
    ];
    $groupLabel = trim((string)($mergeInfo['group_course_label'] ?? ''));
    $scopeNotes = build_offering_scope_notes($offeringId, $row, (array)$mergeInfo, $sectionRowsByOffering);

    $row['entries'] = $scheduleRowsByOffering[$offeringId] ?? [];
    $row['merge_scope_notes'] = (array)($scopeNotes['section_notes'] ?? []);
    $row['entry_notes_by_type'] = (array)($scopeNotes['entry_notes_by_type'] ?? []);
    $row['pending_notes_by_type'] = (array)($scopeNotes['pending_notes_by_type'] ?? []);
    $facultyAssignments = [];
    foreach ($effectiveSourceIdsForRow as $sourceOfferingId) {
        foreach ((array)($workloadFacultyMap[$sourceOfferingId] ?? []) as $assignment) {
            $facultyId = (int)($assignment['faculty_id'] ?? 0);
            if ($facultyId <= 0) {
                continue;
            }
            $facultyAssignments[$facultyId] = $assignment;
        }
    }
    $row['workload_faculty_assignments'] = array_values($facultyAssignments);
    $row['workload_faculty_names'] = array_values(array_filter(array_map(
        static function ($assignment): string {
            return trim((string)($assignment['faculty_name'] ?? ''));
        },
        (array)($row['workload_faculty_assignments'] ?? [])
    )));
    $row['schedule_owner_offering_id'] = $primarySourceOfferingId;
    $row['schedule_modal_section_label'] = ((int)($mergeInfo['group_size'] ?? 1) > 1 && $groupLabel !== '')
        ? ('Merged Group: ' . $groupLabel)
        : ('Section: ' . (string)($row['section_name'] ?? ''));
    $row['merge'] = [
        'owner_offering_id' => $ownerOfferingId,
        'owner_full_section' => (string)($ownerSection['full_section'] ?? ''),
        'is_merged_member' => !empty($mergeInfo['is_merged_member']),
        'has_merged_members' => !empty($mergeInfo['has_merged_members']),
        'group_size' => (int)($mergeInfo['group_size'] ?? 1),
        'group_course_label' => $groupLabel,
        'incoming_scope_owner_ids' => (array)($mergeInfo['incoming_scope_owner_ids'] ?? []),
        'inherited_types' => array_values((array)($mergeInfo['inherited_types'] ?? [])),
        'owned_member_ids_by_scope' => (array)($mergeInfo['owned_member_ids_by_scope'] ?? []),
        'effective_owner_by_type' => (array)($mergeInfo['effective_owner_by_type'] ?? ['LEC' => $offeringId, 'LAB' => $offeringId])
    ];

    $offeringsById[$offeringId] = $row;
}

$grouped = [];
foreach ($offeringOrder as $offeringId) {
    $offering = $offeringsById[$offeringId];
    $groupKey = $sortBy === 'subject'
        ? strtoupper((string)$offering['sub_code']) . '|' . strtoupper((string)$offering['sub_description'])
        : (string)(int)$offering['year_level'];

    if (!isset($grouped[$groupKey])) {
        $grouped[$groupKey] = [];
    }

    $grouped[$groupKey][] = $offering;
}

foreach ($grouped as $offerings) {
    $groupTitle = schedule_group_title($sortBy, $offerings[0]);
    $countLabel = schedule_group_count_label($sortBy);

    echo "<div class='schedule-group-card mb-3'>";
    echo "  <div class='schedule-group-header px-3 py-2 d-flex justify-content-between align-items-center flex-wrap gap-2'>";
    echo "    <span>" . htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') . "</span>";
    echo "    <span class='badge bg-label-primary schedule-group-count' data-total-count='" . count($offerings) . "' data-count-label='" . htmlspecialchars($countLabel, ENT_QUOTES, 'UTF-8') . "'>" . count($offerings) . " " . htmlspecialchars($countLabel, ENT_QUOTES, 'UTF-8') . "</span>";
    echo "  </div>";
    echo "  <div class='table-responsive schedule-pan-shell'>";
    echo "    <table class='table table-bordered table-hover mb-0 schedule-offerings-table'>";
    echo "      <thead>";
    echo "        <tr>";
    echo "          <th class='schedule-section-col'>Section</th>";
    echo "          <th class='schedule-subject-col'>Subject</th>";
    echo "          <th class='schedule-description-col'>Description</th>";
    echo "          <th class='text-center text-nowrap schedule-hours-col'>LEC Hrs</th>";
    echo "          <th class='text-center text-nowrap schedule-hours-col'>LAB Hrs</th>";
    echo "          <th class='text-center schedule-days-col'>Days</th>";
    echo "          <th class='text-center schedule-time-col'>Time</th>";
    echo "          <th class='text-center schedule-room-col'>Room</th>";
    echo "          <th class='text-center schedule-status-col'>Status</th>";
    echo "          <th class='text-center schedule-action-col'>Action</th>";
    echo "        </tr>";
    echo "      </thead>";
    echo "      <tbody>";

    foreach ($offerings as $row) {
        $status = offering_schedule_status_badge($row);
        $daysParts = [];
        $timeParts = [];
        $roomParts = [];
        $sequenceByType = ['LEC' => 0, 'LAB' => 0];
        $requiredTypes = offering_required_schedule_types($row);
        $entriesByType = ['LEC' => [], 'LAB' => []];

        foreach ((array)($row['entries'] ?? []) as $entry) {
            $type = synk_normalize_schedule_type((string)($entry['schedule_type'] ?? 'LEC'));
            if (!isset($entriesByType[$type])) {
                $entriesByType[$type] = [];
            }
            $entriesByType[$type][] = $entry;
        }

        foreach ($requiredTypes as $type) {
            $typeEntries = array_values((array)($entriesByType[$type] ?? []));
            if (empty($typeEntries)) {
                if (!empty($row['entries'])) {
                    $label = block_badge_html($type, 1);
                    $daysParts[] = schedule_stack_item_html($label, 'Pending', 'is-days text-muted');
                    $timeParts[] = schedule_stack_item_html($label, 'Pending', 'is-time text-muted');
                    $roomParts[] = schedule_stack_item_html($label, 'Pending', 'is-room text-muted');
                }
                continue;
            }

            foreach ($typeEntries as $entry) {
                $sequenceByType[$type]++;
                $label = block_badge_html($type, $sequenceByType[$type]);
                $daysText = !empty($entry['days']) ? implode('', $entry['days']) : 'TBA';
                $timeText = synk_schedule_time_label($entry['time_start'], $entry['time_end'], 'h:i A', ' - ');
                $roomText = $entry['room_label'] !== ''
                    ? $entry['room_label']
                    : 'TBA';

                $daysParts[] = schedule_stack_item_html($label, $daysText, 'is-days');
                $timeParts[] = schedule_stack_item_html($label, $timeText, 'is-time');
                $roomParts[] = schedule_room_stack_item_html(
                    $label,
                    $roomText,
                    (int)$entry['room_id'],
                    $type,
                    (int)($entry['source_offering_id'] ?? $row['schedule_owner_offering_id'])
                );
            }
        }

        if (empty($daysParts)) {
            $daysParts[] = schedule_stack_empty_html();
            $timeParts[] = schedule_stack_empty_html();
            $roomParts[] = schedule_stack_empty_html();
        }

        $merge = is_array($row['merge'] ?? null) ? $row['merge'] : [];
        $searchText = strtolower(trim(
            (string)$row['section_name'] . ' ' .
            (string)$row['full_section'] . ' ' .
            (string)$row['sub_code'] . ' ' .
            (string)$row['sub_description'] . ' ' .
            (string)($merge['group_course_label'] ?? '') . ' ' .
            (string)($merge['owner_full_section'] ?? '') . ' ' .
            year_label((string)$row['year_level'])
        ));

        echo "<tr class='schedule-offering-row' data-search-text='" . htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') . "'>";
        echo "  <td class='schedule-section-col'>" . schedule_section_cell_html($row, $sortBy) . "</td>";
        echo "  <td class='text-nowrap schedule-subject-col'>" . htmlspecialchars(strtoupper((string)$row['sub_code']), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "  <td class='schedule-description-col'>" . htmlspecialchars(strtoupper((string)$row['sub_description']), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "  <td class='text-center fw-semibold schedule-hours-col'>" . htmlspecialchars(contact_hours_value($row['display_lec_units']), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "  <td class='text-center fw-semibold schedule-hours-col'>" . htmlspecialchars(contact_hours_value($row['display_lab_units']), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "  <td class='text-center schedule-days-col'>" . implode('', $daysParts) . "</td>";
        echo "  <td class='text-center schedule-time-col'>" . implode('', $timeParts) . "</td>";
        echo "  <td class='text-center schedule-room-col'>" . implode('', $roomParts) . "</td>";
        echo "  <td class='text-center schedule-status-col'>" . render_offering_status_cell_html($row, $status) . "</td>";
        echo "  <td class='text-center schedule-action-col'>" . render_schedule_action_cell_html($row, $status) . "</td>";
        echo "</tr>";
    }

    echo "      </tbody>";
    echo "    </table>";
    echo "  </div>";
    echo "</div>";
}
?>

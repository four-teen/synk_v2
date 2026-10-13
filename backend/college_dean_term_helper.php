<?php
require_once __DIR__ . '/academic_term_helper.php';

function synk_dean_academic_years(mysqli $conn): array
{
    $rows = $conn->query('SELECT ay_id,ay FROM tbl_academic_years ORDER BY ay DESC,ay_id DESC')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['ay_id'] = (int)$row['ay_id'];
    return $rows;
}

function synk_dean_term_catalog(mysqli $conn): array
{
    $terms = [];
    foreach (synk_dean_academic_years($conn) as $year) {
        foreach ([1, 2, 3] as $semester) $terms[] = ['ay_id' => $year['ay_id'], 'semester' => $semester,
            'ay_label' => $year['ay'], 'key' => $year['ay_id'] . ':' . $semester,
            'label' => $year['ay'] . ' - ' . synk_semester_label($semester)];
    }
    return $terms;
}

function synk_dean_term_from_ids(mysqli $conn, $ayId, $semester): array
{
    $id = filter_var($ayId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $sem = filter_var($semester, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
    if ($id === false || $sem === false || !is_scalar($ayId) || !is_scalar($semester)) throw new DomainException('Choose a valid academic year and semester.');
    $year = null;
    foreach (synk_dean_academic_years($conn) as $candidate) if ($candidate['ay_id'] === $id) $year = $candidate;
    if (!$year) throw new DomainException('Choose an academic year available in the system.');
    $current = synk_fetch_current_academic_term($conn);
    $label = synk_semester_label($sem);
    return ['ay_id' => $id, 'semester' => $sem, 'ay_label' => $year['ay'], 'semester_label' => $label,
        'term_text' => $year['ay'] . ' - ' . $label, 'key' => $id . ':' . $sem,
        'is_current' => $id === (int)$current['ay_id'] && $sem === (int)$current['semester']];
}

function synk_dean_select_view_term(mysqli $conn, $ayId, $semester): array
{
    $term = synk_dean_term_from_ids($conn, $ayId, $semester);
    $_SESSION['college_dean_view_term'] = ['user_id' => (int)($_SESSION['user_id'] ?? 0),
        'ay_id' => $term['ay_id'], 'semester' => $term['semester']];
    return $term;
}

function synk_dean_view_term(mysqli $conn): array
{
    $stored = $_SESSION['college_dean_view_term'] ?? null;
    if (is_array($stored) && ($stored['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)) {
        try { return synk_dean_term_from_ids($conn, $stored['ay_id'] ?? null, $stored['semester'] ?? null); }
        catch (DomainException $exception) { unset($_SESSION['college_dean_view_term']); }
    }
    $current = synk_fetch_current_academic_term($conn);
    if ($current['ay_id'] > 0 && $current['semester'] > 0) return synk_dean_term_from_ids($conn, $current['ay_id'], $current['semester']);
    $years = synk_dean_academic_years($conn);
    if ($years) return synk_dean_term_from_ids($conn, $years[0]['ay_id'], 1);
    return $current + ['key' => '', 'is_current' => false];
}

function synk_dean_apply_view_term_request(mysqli $conn, array $input): void
{
    if (isset($input['dean_term'])) {
        if ($input['dean_term'] !== 'current') throw new DomainException('Choose a valid viewing term.');
        unset($_SESSION['college_dean_view_term']);
    } elseif (isset($input['dean_ay_id']) || isset($input['dean_semester'])) {
        synk_dean_select_view_term($conn, $input['dean_ay_id'] ?? null, $input['dean_semester'] ?? null);
    }
}

// Read requests carry their page's term so two tabs can review different terms.
function synk_dean_read_term(mysqli $conn, array $input): array
{
    if (isset($input['ay_id']) || isset($input['semester'])) return synk_dean_term_from_ids($conn, $input['ay_id'] ?? null, $input['semester'] ?? null);
    return synk_dean_view_term($conn);
}

function synk_dean_require_current_term_for_write(mysqli $conn, array $input = []): void
{
    if (isset($input['term']) && is_string($input['term']) && ($input['term'] === 'all' || strpos($input['term'], 'year:') === 0)) {
        throw new DomainException('Choose the current academic year and semester to make changes.');
    }
    $term = synk_dean_read_term($conn, $input);
    if (isset($input['term']) && is_string($input['term']) && preg_match('/^([1-9][0-9]*):([1-3])$/D', $input['term'], $parts)) {
        $term = synk_dean_term_from_ids($conn, $parts[1], $parts[2]);
    }
    if (isset($input['record_term']) && is_string($input['record_term']) && preg_match('/^([1-9][0-9]*):([1-3])$/D', $input['record_term'], $parts)) {
        $recordTerm = synk_dean_term_from_ids($conn, $parts[1], $parts[2]);
        if (empty($recordTerm['is_current'])) throw new DomainException('This term is for viewing only. Choose the current term to add students.');
    }
    if (empty($term['is_current'])) throw new DomainException('This term is for viewing only. Switch to the current term to make changes.');
}

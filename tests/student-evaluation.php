<?php
// php tests/student-evaluation.php -- scoring, persistence and reports; fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/student_portal_helper.php';

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$snapshot = static function (mysqli $db): string {
    return hash('sha256', json_encode([
        $db->query('SELECT evaluation_id, student_id, faculty_id, question_count, total_score, average_rating, comment_text, submission_status, final_submission_token FROM tbl_student_faculty_evaluations ORDER BY evaluation_id')->fetch_all(MYSQLI_ASSOC),
        $db->query('SELECT * FROM tbl_student_faculty_evaluation_answers ORDER BY answer_id')->fetch_all(MYSQLI_ASSOC),
    ]));
};
$before = $snapshot($conn);
synk_student_faculty_evaluation_ensure_schema($conn);
$check($before === $snapshot($conn), 'Preparing the version column must not alter existing answers, scores or draft/submission status.');

class StudentEvaluationQAConnection extends mysqli
{
    public $requestMode = false;
    #[\ReturnTypeWillChange]
    public function query($sql, $mode = MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS `(tbl_student_faculty_evaluations|tbl_student_faculty_evaluation_answers)`/i', $sql, $match)) {
            if (!synk_table_exists($this, $match[1])) throw new RuntimeException('Prepare the evaluation schema before rollback-only tests.');
            return true;
        }
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql)) throw new RuntimeException('Fixtures cannot change the schema.');
        return parent::query($sql, $mode);
    }
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags = 0, $name = null) { return $this->requestMode ? $this->query('SAVEPOINT evaluation_request') : parent::begin_transaction($flags); }
    #[\ReturnTypeWillChange]
    public function commit($flags = 0, $name = null) { return $this->requestMode ? $this->query('RELEASE SAVEPOINT evaluation_request') : parent::commit($flags); }
    #[\ReturnTypeWillChange]
    public function rollback($flags = 0, $name = null) { return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT evaluation_request') : parent::rollback($flags); }
}
$conn->close();
$conn = new StudentEvaluationQAConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
$conn->begin_transaction();
$conn->requestMode = true;
$_SESSION = [];
try {
    $bank = synk_student_faculty_evaluation_question_bank();
    $questions = synk_student_faculty_evaluation_flat_questions();
    $check(array_map(static function ($category) { return count($category['items']); }, $bank) === [6, 5, 4], 'The revised instrument has exactly 6, 5 and 4 statements.');
    $check(count($questions) === 15 && $questions['MTL1']['question_order'] === 1 && $questions['CT4']['question_order'] === 15, 'The revised questions retain document order.');
    $check(count(synk_student_faculty_evaluation_flat_questions('legacy-20')) === 20, 'Historical questions remain available.');
    $scale = synk_student_faculty_evaluation_scale();
    $check($scale[5]['label'] === 'Always manifested' && $scale[1]['label'] === 'Never/Rarely manifested' && strpos($scale[4]['description'], '61-90%') !== false, 'The frequency labels and anchors match the attachment.');
    $check(synk_student_faculty_evaluation_scale('legacy-20')[5]['label'] === 'Outstanding', 'Historical scales are not relabelled.');
    $answers = array_fill_keys(array_keys($questions), 4);
    $answers['MTL1'] = 5;
    $check(synk_student_faculty_evaluation_score(61, 15) === ['mean' => 4.07, 'percentage' => 81.33, 'student_component_percentage' => 48.8], 'Percentages and components use raw totals, not the rounded 4.07 mean.');
    $check(synk_student_faculty_evaluation_score(15, 15)['percentage'] === 20.0 && synk_student_faculty_evaluation_score(75, 15)['percentage'] === 100.0, 'The normalized score follows the retained points/max-points formula at both endpoints.');
    $check(synk_student_faculty_evaluation_score(0, 0)['percentage'] === 0.0, 'An empty result cannot divide by zero.');
    foreach ([null, 0, 6, '3.5', ['4']] as $invalid) {
        $bad = $answers;
        $bad['MTL1'] = $invalid;
        $rejected = false;
        try { synk_student_faculty_evaluation_normalize_answers($bad); } catch (RuntimeException $error) { $rejected = true; }
        $check($rejected, 'Missing, out-of-range, fractional and array answers are rejected.');
    }

    // Isolated IDs and term, with no real students or faculty modified.
    do { $ayId = random_int(1000000000, 2000000000); } while ((int)$conn->query('SELECT COUNT(*) AS n FROM tbl_student_faculty_evaluations WHERE ay_id=' . $ayId)->fetch_assoc()['n'] > 0);
    $facultyId = random_int(1000000000, 2000000000);
    $studentId = random_int(1000000000, 1999999995);
    $payload = ['student_id' => $studentId, 'faculty_id' => $facultyId, 'ay_id' => $ayId, 'semester' => 1, 'faculty_name' => 'SET rollback QA', 'comment_text' => 'Preserved draft comment'];
    $first = synk_student_save_faculty_evaluation($conn, $payload, $answers);
    $check($first['question_count'] === 15 && $first['total_score'] === 61 && $first['instrument_version'] === synk_student_faculty_evaluation_current_version(), 'Saving persists the revised instrument and all 15 answers.');
    $check(count(synk_student_fetch_faculty_evaluation_individual_report_rows($conn, $ayId, 1)) === 0, 'Drafts do not enter faculty reports.');
    synk_student_finalize_faculty_evaluations_for_term($conn, $studentId, $ayId, 1, [$facultyId]);
    $payload['student_id'] = $studentId + 1;
    synk_student_save_faculty_evaluation($conn, $payload, array_fill_keys(array_keys($questions), 3));
    synk_student_finalize_faculty_evaluations_for_term($conn, $studentId + 1, $ayId, 1, [$facultyId]);

    $seedLegacy = static function (int $id, string $status) use ($conn, $facultyId, $ayId): int {
        $token = 'QA-' . bin2hex(random_bytes(12));
        $stmt = $conn->prepare("INSERT INTO tbl_student_faculty_evaluations (student_id, faculty_id, ay_id, semester, faculty_name, comment_text, question_count, total_score, average_rating, evaluation_token, submission_status, final_submission_token) VALUES (?, ?, ?, 1, 'SET rollback QA', 'Preserved draft comment', 20, 80, 4, ?, ?, ?)");
        $finalToken = $status === 'submitted' ? $token : '';
        $stmt->bind_param('iiisss', $id, $facultyId, $ayId, $token, $status, $finalToken);
        $stmt->execute();
        $evaluationId = (int)$stmt->insert_id;
        $stmt->close();
        foreach (synk_student_faculty_evaluation_flat_questions('legacy-20') as $question) {
            $rating = 4;
            $stmt = $conn->prepare('INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id, category_key, category_title, question_key, question_order, question_text, rating) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('isssisi', $evaluationId, $question['category_key'], $question['category_title'], $question['question_key'], $question['question_order'], $question['question_text'], $rating);
            $stmt->execute();
            $stmt->close();
        }
        return $evaluationId;
    };
    $legacyId = $seedLegacy($studentId + 2, 'submitted');
    $oldDraftId = $seedLegacy($studentId + 3, 'draft');
    $rows = synk_student_fetch_faculty_evaluation_individual_report_rows($conn, $ayId, 1);
    $check(count($rows) === 1 && $rows[0]['evaluations_count'] === 2 && $rows[0]['overall_mean'] === 3.53 && $rows[0]['overall_percentage'] === 70.67 && $rows[0]['student_component_percentage'] === 42.4, 'Faculty aggregation pools unrounded points and excludes historical responses.');
    $check($rows[0]['categories']['teaching_learning']['percentage'] === 71.67 && $rows[0]['categories']['knowledge_pedagogy_technology']['percentage'] === 70.0, 'Category percentages use 6 and 5 questions per student independently.');
    $summary = synk_student_fetch_faculty_evaluation_report_summary($conn, $ayId, 1);
    $check($summary['evaluation_count'] === 2 && $summary['overall_percentage'] === 70.67, 'The summary agrees with raw faculty totals.');
    $terms = array_values(array_filter(synk_student_fetch_faculty_evaluation_report_term_options($conn), static function ($row) use ($ayId) { return $row['ay_id'] === $ayId; }));
    $check(count($terms) === 1 && $terms[0]['evaluation_count'] === 2, 'Academic-term counts include only the selected instrument.');
    $legacyTerms = array_values(array_filter(synk_student_fetch_faculty_evaluation_report_term_options($conn, 'legacy-20'), static function ($row) use ($ayId) { return $row['ay_id'] === $ayId; }));
    $check(count($legacyTerms) === 1 && $legacyTerms[0]['evaluation_count'] === 1, 'Historical academic-term counts stay separate.');
    $sheet = synk_student_fetch_faculty_evaluation_rating_sheet_rows($conn, $ayId, 1);
    $check(count($sheet) === 2 && in_array(81.33, array_column($sheet, 'average_percentage'), true), 'Individual rating sheets do not re-round the stored mean.');
    $legacy = synk_student_fetch_faculty_evaluation_individual_report_rows($conn, $ayId, 1, 'legacy-20');
    $check(count($legacy) === 1 && $legacy[0]['evaluations_count'] === 1 && count($legacy[0]['categories']) === 4 && $legacy[0]['overall_percentage'] === 80.0, 'Historical reports preserve the original four categories separately.');
    $rejected = false;
    try { synk_student_finalize_faculty_evaluations_for_term($conn, $studentId + 3, $ayId, 1, [$facultyId]); } catch (RuntimeException $error) { $rejected = strpos($error->getMessage(), '15 questions') !== false; }
    $check($rejected, 'The server refuses to finalize an old draft.');
    $payload['student_id'] = $studentId + 3;
    $updated = synk_student_save_faculty_evaluation($conn, $payload, $answers);
    $check($updated['evaluation_id'] === $oldDraftId && (int)$conn->query('SELECT COUNT(*) AS n FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id=' . $oldDraftId)->fetch_assoc()['n'] === 15, 'Saving an old draft replaces its answer snapshot with 15 revised statements.');
    synk_student_finalize_faculty_evaluations_for_term($conn, $studentId + 3, $ayId, 1, [$facultyId]);
    $rejected = false;
    try { synk_student_save_faculty_evaluation($conn, $payload, $answers); } catch (RuntimeException $error) { $rejected = strpos($error->getMessage(), 'already been finalized') !== false; }
    $check($rejected, 'Finalized evaluations cannot be overwritten.');
    $check((int)$conn->query('SELECT question_count FROM tbl_student_faculty_evaluations WHERE evaluation_id=' . $legacyId)->fetch_assoc()['question_count'] === 20, 'Submitted historical evaluations remain intact.');
} finally {
    $conn->requestMode = false;
    $conn->rollback();
}
$check($before === $snapshot($conn), 'All fixture evaluation records rolled back; real responses are unchanged.');
echo 'Student evaluation: ', $checks, ' checks passed. All fixture records rolled back.', PHP_EOL;

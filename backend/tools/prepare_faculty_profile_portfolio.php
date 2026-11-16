<?php
if (PHP_SAPI !== 'cli') {http_response_code(404); exit;}
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../faculty_profile_research_helper.php';
require_once __DIR__.'/../faculty_profile_training_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Compare only the original columns so the additive migration can verify existing data.
$originalColumns = 'research_id,owner_type,owner_id,research_title,research_scope,funding_type,conference_name,conference_date,publisher,publisher_link,scopus_link,revision,created_at,updated_at';
$exists = $conn->query("SHOW TABLES LIKE 'tbl_faculty_profile_researches'")->num_rows > 0;
$snapshot = static function() use ($conn, $originalColumns): string {
    return hash('sha256', json_encode($conn->query('SELECT '.$originalColumns.' FROM tbl_faculty_profile_researches ORDER BY research_id')->fetch_all(MYSQLI_ASSOC)));
};
$before = $exists ? $snapshot() : null;
synk_faculty_profile_research_ensure($conn);
synk_faculty_profile_training_ensure($conn);
if ($exists && $before !== $snapshot()) throw new RuntimeException('Existing research data changed during schema preparation.');
echo "Faculty profile training storage and research publication columns are ready. Existing research data preserved.", PHP_EOL;

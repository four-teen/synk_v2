<?php
// Run: php tests/faculty-profile.php [--database]
// Database checks write only inside a transaction that is always rolled back.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/faculty_profile_helper.php';

$checks = 0;
function profile_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}

$today = new DateTimeImmutable('2026-10-01');
$rankFixtures = [
    'Instructor I' => ['salary_grade' => 12],
    'Assistant Professor IV' => ['salary_grade' => 18],
    'Professor VI' => ['salary_grade' => 29],
];
[$valid, $errors] = synk_faculty_profile_validate([
    'full_name' => 'Test Faculty', 'date_of_birth' => '1990-02-28',
    'service_start_date' => '2023-01-01', 'length_of_service' => 'forged',
    'user_id' => '99999', 'faculty_id' => '99999',
    'faculty_id_number' => ' 00123-A ',
    'employment_classification' => 'Part Time', 'work_status' => 'Contract of Service',
    'faculty_rank' => 'Instructor I', 'salary_grade' => 'SG 99',
    'bachelor_status' => 'Completed', 'bachelor_degree' => 'BS Example',
    'master_status' => 'Ongoing', 'master_degree' => 'MS Example',
    'doctoral_status' => 'Not applicable',
], $today, $rankFixtures);
profile_check(!$errors, 'A template-shaped faculty profile should validate.');
profile_check($valid['length_of_service'] === '3 years and 9 months', 'Calculate service on the server.');
profile_check(!isset($valid['user_id']) && !isset($valid['faculty_id']), 'Never accept posted identity fields.');
profile_check($valid['faculty_id_number'] === '00123-A', 'Faculty ID is editable text and preserves leading zeros.');
foreach (['FAC00123', 'abc0007', '00123-A', '000123', 'FACULTY'] as $facultyId) {
    [$idProfile, $idErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'faculty_id_number' => $facultyId], $today);
    profile_check(!$idErrors && $idProfile['faculty_id_number'] === $facultyId, 'Accept alphabetic, numeric, and alphanumeric faculty IDs without conversion.');
}
foreach ([['malformed'], str_repeat('x', 51), "ID\n123", "ID\0number"] as $invalidFacultyId) {
    [, $idErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'faculty_id_number' => $invalidFacultyId], $today);
    profile_check(isset($idErrors['faculty_id_number']), 'Reject malformed, oversized, or multiline faculty IDs.');
}
profile_check(!isset($valid['work_status']), 'Work status is no longer editable, including forged submissions.');
profile_check($valid['salary_grade'] === 'SG 12', 'Ignore a posted salary and use the selected rank.');
foreach ($rankFixtures as $rankName => $rankData) {
    [$rankProfile, $rankErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'faculty_rank' => $rankName], $today, $rankFixtures);
    profile_check(!$rankErrors && $rankProfile['salary_grade'] === 'SG ' . $rankData['salary_grade'], 'Map ' . $rankName . ' to its fixed salary grade.');
}
[, $rankErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'faculty_rank' => 'Made-up rank'], $today, $rankFixtures);
profile_check(isset($rankErrors['faculty_rank']), 'Reject a rank outside the database catalog.');
$legacy = ['faculty_rank' => 'Previously entered rank', 'salary_grade' => 'Previous pay value'];
[$legacyProfile, $rankErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'faculty_rank' => $legacy['faculty_rank'], 'salary_grade' => 'forged'], $today, $rankFixtures, $legacy);
profile_check(!$rankErrors && $legacyProfile['salary_grade'] === $legacy['salary_grade'], 'Preserve existing unlisted rank and pay until the owner changes rank.');
[$emptyRank, $rankErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'salary_grade' => ['forged']], $today, $rankFixtures);
profile_check(!$rankErrors && $emptyRank['salary_grade'] === '', 'No rank has no salary grade; ignore even malformed salary input.');
profile_check(synk_faculty_profile_service('2026-09-30', $today) === 'Less than a month', 'Incomplete service month.');
profile_check(synk_faculty_profile_service('2025-10-01', $today) === '1 year', 'Service anniversary.');
profile_check(synk_faculty_profile_service('2024-02-29', new DateTimeImmutable('2025-02-28')) === '11 months', 'Leap day service before anniversary.');
profile_check(synk_faculty_profile_service('2024-01-31', new DateTimeImmutable('2024-03-01')) === '1 month', 'Service uses completed calendar months consistently with the browser.');
profile_check(synk_faculty_profile_service('2027-01-01', $today) === '', 'Future service dates are rejected.');
profile_check(synk_faculty_profile_date('2026-02-30') === null, 'Invalid calendar date.');
profile_check(synk_faculty_profile_date('2024-02-29') !== null, 'Valid leap date.');
foreach (['2026-02-30', '2026-10-02', '1899-01-01', 'not-a-date'] as $date) {
    [, $invalid] = synk_faculty_profile_validate(['full_name' => 'Test', 'date_of_birth' => $date], $today);
    profile_check(isset($invalid['date_of_birth']), 'Reject invalid birth date ' . $date);
}
[, $invalid] = synk_faculty_profile_validate(['full_name' => [], 'gender' => 'invalid', 'civil_status' => [], 'faculty_rank' => str_repeat('x', 151)], $today);
profile_check(count($invalid) === 4, 'Reject malformed fields, unknown options, and oversized text.');
[, $invalid] = synk_faculty_profile_validate(['full_name' => ' ', 'master_status' => 'Ongoing'], $today);
profile_check(isset($invalid['full_name'], $invalid['master_degree']), 'Name and degree are required when applicable.');
[, $invalid] = synk_faculty_profile_validate(['full_name' => 'Test', 'date_of_birth' => '2000-01-01', 'service_start_date' => '1999-01-01'], $today);
profile_check(isset($invalid['service_start_date']), 'Service cannot precede birth.');
[$partial, $invalid] = synk_faculty_profile_validate(['full_name' => 'Test', 'length_of_service' => '3 years with a break in service'], $today);
profile_check(!$invalid && $partial['length_of_service'] === '3 years with a break in service', 'Support partial profiles and manual credited service.');
[$unicode, $invalid] = synk_faculty_profile_validate(['full_name' => str_repeat('ñ', 250)], $today);
profile_check(!$invalid, 'Validate Unicode text by characters.');
$defaults = synk_faculty_profile_defaults(['username' => 'Account name', 'campus_name' => 'Test campus'], null);
profile_check($defaults['full_name'] === 'Account name' && $defaults['revision'] === 0, 'Unlinked professors can create their own profile.');

$locationFixtures = [
    'North Campus' => ['College of Engineering', 'College of Education'],
    'South Campus' => ['College of Arts', 'College of Education'],
    'New Campus' => [],
];
foreach ([['North Campus', 'College of Engineering'], ['South Campus', 'College of Education'], ['New Campus', ''], ['', '']] as [$campus, $college]) {
    [$locationProfile, $locationErrors] = synk_faculty_profile_validate([
        'full_name' => 'Test', 'campus_name' => $campus, 'college_name' => $college,
    ], $today, [], [], [], $locationFixtures);
    profile_check(!$locationErrors && $locationProfile['campus_name'] === $campus && $locationProfile['college_name'] === $college, 'Accept valid campus/college pairs and optional empty selections.');
}
foreach ([
    ['Unknown campus', 'College of Engineering', 'campus_name'],
    ['North Campus', 'Unknown college', 'college_name'],
    ['North Campus', 'College of Arts', 'college_name'],
    ['', 'College of Arts', 'college_name'],
    [['North Campus'], 'College of Engineering', 'campus_name'],
    ['North Campus', ['College of Engineering'], 'college_name'],
] as [$campus, $college, $errorKey]) {
    [, $locationErrors] = synk_faculty_profile_validate([
        'full_name' => 'Test', 'campus_name' => $campus, 'college_name' => $college,
    ], $today, [], [], [], $locationFixtures);
    profile_check(isset($locationErrors[$errorKey]), 'Reject unknown, malformed, or mismatched location selections.');
}
[$trimmedLocation, $locationErrors] = synk_faculty_profile_validate([
    'full_name' => 'Test', 'campus_name' => ' North Campus ', 'college_name' => 'College of Engineering ',
], $today, [], [], [], $locationFixtures);
profile_check(!$locationErrors && $trimmedLocation['college_name'] === 'College of Engineering', 'Trim database and submitted location labels consistently.');

$eligibilityFixture = [1 => ['name' => 'Career Service Professional Eligibility'], 2 => ['name' => 'Licensed Professional Teacher']];
[$jobOrder, $jobErrors] = synk_faculty_profile_validate([
    'full_name' => 'Test', 'employment_classification' => 'Job Order',
    'eligibility_ids' => ['2', '1', '2'], 'eligibility' => 'Previously entered certification',
], $today, [], [], $eligibilityFixture);
profile_check(!$jobErrors && $jobOrder['employment_classification'] === 'Job Order', 'Job Order is accepted.');
profile_check($jobOrder['eligibility_ids'] === [1, 2], 'Multiple selections are normalized and duplicate IDs are removed.');
profile_check($jobOrder['eligibility'] === 'Previously entered certification', 'Previous eligibility text is preserved independently.');
$jobDefaults = synk_faculty_profile_defaults(['username' => 'Test'], null, 'job_order');
profile_check($jobDefaults['employment_classification'] === 'Job Order', 'Job Order defaults are supported.');
foreach (['1', [['2']], ['999999'], ['1 OR 1=1'], ['-1'], ['1.0'], [true], ['01'], array_fill(0, 201, '1')] as $badIds) {
    [$selected, $selectionError] = synk_faculty_profile_validate_eligibilities($badIds, $eligibilityFixture);
    profile_check($selectionError !== '', 'Reject malformed, excessive, or unknown eligibility selections.');
}
[$mixedSelection, $mixedError] = synk_faculty_profile_validate_eligibilities(['1', '999'], $eligibilityFixture);
profile_check($mixedSelection === [1] && $mixedError !== '', 'Keep valid choices visible when another submitted choice is invalid.');
[$cleared, $clearError] = synk_faculty_profile_validate_eligibilities([], $eligibilityFixture);
profile_check($cleared === [] && $clearError === '', 'Clearing all credentials is supported.');
[, $invalidSelections] = synk_faculty_profile_validate(['full_name' => 'Test', 'eligibility_ids' => ['999']], $today, [], [], $eligibilityFixture);
profile_check(isset($invalidSelections['eligibility_ids']), 'Validation exposes a field error for unknown credentials.');

[$educationInput, $educationErrors] = synk_faculty_profile_validate([
    'full_name' => 'Test', 'bachelor_degree' => "  Bachelor  of Science\n in Computing ",
    'master_institution' => "Sample\t University", 'doctoral_address' => 'City, Province',
    'master_specialization' => 'Data Science & AI',
], $today);
profile_check(!$educationErrors && $educationInput['bachelor_degree'] === 'Bachelor of Science in Computing', 'Normalize whitespace in degree names.');
profile_check($educationInput['master_institution'] === 'Sample University', 'Normalize institution whitespace.');
profile_check($educationInput['doctoral_address'] === 'City, Province' && $educationInput['master_specialization'] === 'Data Science & AI', 'Preserve meaningful punctuation.');
foreach (['N/A', 'none', '', 'Not applicable'] as $placeholder) {
    profile_check(synk_faculty_education_placeholder($placeholder), 'Do not publish placeholder suggestions.');
}
[, $educationErrors] = synk_faculty_profile_validate(['full_name' => 'Test', 'master_institution' => str_repeat('x', 301), 'bachelor_degree' => ['malformed']], $today);
profile_check(isset($educationErrors['master_institution'], $educationErrors['bachelor_degree']), 'Validate educational text length and type.');

$completionRules = synk_faculty_profile_completion_rules(synk_faculty_profile_fields($rankFixtures, [], $locationFixtures));
$emptyCompletion = synk_faculty_profile_completion([], $completionRules);
profile_check($emptyCompletion['percent'] === 0 && $emptyCompletion['total'] === 24, 'An empty profile starts at zero with every education level awaiting an answer.');
$completeProfile = [
    'full_name' => 'Sample Faculty', 'gender' => 'Prefer not to say', 'civil_status' => 'Single',
    'date_of_birth' => '1990-02-28', 'campus_name' => 'North Campus', 'college_name' => 'College of Engineering',
    'faculty_rank' => 'Instructor I', 'employment_classification' => 'Permanent', 'length_of_service' => '3 years',
    'bachelor_status' => 'Not applicable', 'master_status' => 'Not applicable', 'doctoral_status' => 'Not applicable',
];
[$completionValues, $completionErrors] = synk_faculty_profile_validate($completeProfile, $today, $rankFixtures, [], [], $locationFixtures);
$fullCompletion = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
profile_check($fullCompletion['percent'] === 100 && $fullCompletion['total'] === 12 && !$fullCompletion['missing'], 'Not-applicable education and blank optional credentials do not prevent completion.');
profile_check($fullCompletion['sections']['personal']['completed'] === 6 && $fullCompletion['sections']['employment']['completed'] === 3, 'Personal and employment counts reflect their applicable details.');
foreach (['full_name', 'gender', 'date_of_birth', 'campus_name', 'faculty_rank', 'length_of_service', 'bachelor_status'] as $missingKey) {
    $incompleteProfile = $completionValues;
    $incompleteProfile[$missingKey] = '   ';
    $incomplete = synk_faculty_profile_completion($incompleteProfile, $completionRules);
    profile_check($incomplete['percent'] < 100 && in_array($missingKey, $incomplete['missing'], true), 'Clearing ' . $missingKey . ' restores its attention item.');
}
$educationCompletion = $completeProfile;
$educationCompletion['bachelor_status'] = 'Completed';
[$completionValues, $completionErrors] = synk_faculty_profile_validate($educationCompletion, $today, $rankFixtures, [], [], $locationFixtures);
$missingEducation = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
profile_check($missingEducation['total'] === 16 && $missingEducation['percent'] === 75 && count($missingEducation['missing']) === 4, 'Applicable education needs its four details, without penalizing other inapplicable levels.');
$educationCompletion += [
    'bachelor_degree' => 'BS Computing', 'bachelor_institution' => 'Sample University',
    'bachelor_address' => 'Sample City', 'bachelor_specialization' => 'Computer Science',
];
[$completionValues, $completionErrors] = synk_faculty_profile_validate($educationCompletion, $today, $rankFixtures, [], [], $locationFixtures);
$fullEducation = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
profile_check($fullEducation['percent'] === 100 && $fullEducation['total'] === 16, 'An applicable degree can reach full completion.');
$invalidCompletionProfile = $educationCompletion;
$invalidCompletionProfile['college_name'] = 'College of Arts';
$invalidCompletionProfile['service_start_date'] = '2027-01-01';
[$completionValues, $completionErrors] = synk_faculty_profile_validate($invalidCompletionProfile, $today, $rankFixtures, [], [], $locationFixtures);
$invalidCompletion = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
profile_check(in_array('college_name', $invalidCompletion['missing'], true) && in_array('length_of_service', $invalidCompletion['missing'], true), 'Invalid location pairs and service dates cannot count as completed details.');

if (in_array('--database', $argv, true)) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../backend/db.php';
    synk_faculty_profile_ensure_table($conn);
    synk_faculty_profile_ensure_ranks($conn);
    synk_faculty_profile_ensure_eligibilities($conn);
    synk_faculty_profile_ensure_education($conn);
    $catalog = synk_faculty_profile_eligibility_catalog($conn);
    $credentialIds = array_column($catalog, 'eligibility_id', 'code');
    profile_check(count($catalog) === 117, 'All 117 catalog options are seeded.');
    synk_faculty_profile_ensure_eligibilities($conn);
    profile_check(synk_faculty_profile_eligibility_catalog($conn) === $catalog, 'Repeated initialization preserves the catalog IDs.');
    foreach (['csc_professional', 'csc_subprofessional', 'prc_teacher', 'prc_accountant', 'prc_civil', 'prc_plumber', 'tesda_driving', 'tesda_tm1', 'sc_lawyer', 'ces_eligibility'] as $code) {
        profile_check(isset($credentialIds[$code]), 'Catalog contains ' . $code);
    }
    $databaseRanks = synk_faculty_profile_ranks($conn);
    $databaseLocations = synk_faculty_profile_locations($conn);
    $locationRow = $conn->query("SELECT cp.campus_name, c.college_name FROM tbl_college c
        JOIN tbl_campus cp ON cp.campus_id = c.campus_id
        WHERE cp.status = 'active' AND c.status = 'active' ORDER BY c.college_id LIMIT 1")->fetch_assoc();
    profile_check($locationRow !== null, 'Database checks need an active campus and college.');
    $valid['campus_name'] = trim($locationRow['campus_name']);
    $valid['college_name'] = trim($locationRow['college_name']);
    profile_check(!synk_faculty_profile_location_errors($valid, $databaseLocations), 'Load matching selections from the campus and college tables.');
    $expectedRanks = [
        'Instructor I', 'Instructor II', 'Instructor III',
        'Assistant Professor I', 'Assistant Professor II', 'Assistant Professor III', 'Assistant Professor IV',
        'Associate Professor I', 'Associate Professor II', 'Associate Professor III', 'Associate Professor IV', 'Associate Professor V',
        'Professor I', 'Professor II', 'Professor III', 'Professor IV', 'Professor V', 'Professor VI',
    ];
    profile_check(array_keys($databaseRanks) === $expectedRanks, 'Database contains all 18 ranks in overall-level order.');
    foreach ($expectedRanks as $offset => $name) {
        profile_check($databaseRanks[$name]['overall_level'] === $offset + 1 && $databaseRanks[$name]['salary_grade'] === $offset + 12, 'Correct database mapping for ' . $name);
    }
    $ids = array_map('intval', array_column($conn->query("SELECT user_id FROM tbl_useraccount WHERE status = 'active' ORDER BY user_id LIMIT 2")->fetch_all(MYSQLI_ASSOC), 'user_id'));
    profile_check(count($ids) === 2, 'Database checks need two existing accounts.');
    $before = [];
    $educationCountBefore = (int)$conn->query('SELECT COUNT(*) AS total FROM tbl_faculty_education_options')->fetch_assoc()['total'];
    foreach ($ids as $id) {
        $before[$id] = synk_faculty_profile_fetch($conn, $id);
    }
    $conn->begin_transaction();
    try {
        $id = $ids[0];
        $revision = (int)($before[$id]['revision'] ?? 0);
        $valid['full_name'] = 'Profile validation fixture';
        $valid['eligibility'] = "One license\nAnother license";
        $valid['salary_grade'] = 'SG 99';
        $valid['employment_classification'] = 'Job Order';
        $valid['eligibility_ids'] = [$credentialIds['csc_professional'], $credentialIds['prc_teacher'], $credentialIds['tesda_driving']];
        sort($valid['eligibility_ids']);
        profile_check(synk_faculty_profile_save($conn, $id, $valid, $revision), 'Save a profile for its account.');
        $saved = synk_faculty_profile_fetch($conn, $id);
        profile_check($saved['full_name'] === $valid['full_name'] && $saved['eligibility'] === $valid['eligibility'], 'Profile fields round-trip.');
        profile_check((int)$saved['user_id'] === $id, 'Profile primary key is the login account ID.');
        profile_check($saved['faculty_id_number'] === '00123-A', 'University-issued faculty ID survives save and reload.');
        profile_check((int)$saved['revision'] === $revision + 1, 'Increment revision on save.');
        profile_check($saved['employment_classification'] === 'Job Order', 'Job Order persists.');
        profile_check($saved['eligibility_ids'] === $valid['eligibility_ids'], 'Multiple selected credentials survive save and reload.');
        profile_check($saved['salary_grade'] === 'SG 12', 'Persistence independently enforces the database salary mapping.');
        profile_check($saved['campus_name'] === $valid['campus_name'] && $saved['college_name'] === $valid['college_name'], 'Campus and college selections survive save and reload.');
        $unknownLocation = $valid;
        $unknownLocation['college_name'] = 'Unknown college ' . bin2hex(random_bytes(8));
        try {
            synk_faculty_profile_save($conn, $id, $unknownLocation, (int)$saved['revision']);
            profile_check(false, 'Reject an unknown college at save.');
        } catch (InvalidArgumentException $exception) {
            profile_check(synk_faculty_profile_fetch($conn, $id) === $saved, 'Invalid location choices cannot change a saved profile.');
        }
        $conn->query("UPDATE tbl_faculty_profiles SET work_status = 'Legacy status' WHERE user_id = " . $id);
        profile_check(!synk_faculty_profile_save($conn, $id, $partial, $revision), 'Reject stale saves or concurrent first inserts.');
        profile_check(synk_faculty_profile_fetch($conn, $id)['eligibility_ids'] === $valid['eligibility_ids'], 'Stale save cannot clear selected credentials.');
        profile_check(synk_faculty_profile_fetch($conn, $ids[1]) === $before[$ids[1]], 'Saving one account must not change another account.');
        $partial['full_name'] = 'Changed profile fixture';
        $partial['faculty_id_number'] = 'FAC00987B';
        $partial['faculty_rank'] = 'Professor VI';
        $partial['salary_grade'] = 'SG 1';
        $partial['work_status'] = 'forged';
        $partial['eligibility_ids'] = [$credentialIds['prc_civil'], $credentialIds['prc_plumber']];
        sort($partial['eligibility_ids']);
        $partial['eligibility'] = $valid['eligibility'];
        profile_check(synk_faculty_profile_save($conn, $id, $partial, (int)$saved['revision']), 'Update existing profile.');
        $updated = synk_faculty_profile_fetch($conn, $id);
        profile_check($updated['date_of_birth'] === null && $updated['master_degree'] === '', 'Clear optional dates and education fields.');
        profile_check($updated['campus_name'] === '' && $updated['college_name'] === '', 'Optional location selections can be cleared.');
        profile_check($updated['length_of_service'] === $partial['length_of_service'], 'Manual service persists.');
        profile_check($updated['faculty_id_number'] === 'FAC00987B', 'Alphanumeric faculty ID can be corrected and reloaded on an existing profile.');
        profile_check($updated['faculty_rank'] === 'Professor VI' && $updated['salary_grade'] === 'SG 29', 'Changing rank updates salary in storage.');
        profile_check($updated['work_status'] === 'Legacy status', 'Removing work status from the form preserves stored work status.');
        profile_check($updated['eligibility_ids'] === $partial['eligibility_ids'], 'Replacing selected credentials removes only deselected items.');
        profile_check($updated['eligibility'] === $valid['eligibility'], 'Changing selections leaves legacy eligibility text intact.');
        $unknownCredential = $partial;
        $unknownCredential['eligibility_ids'] = [2147483647];
        try {
            synk_faculty_profile_save($conn, $id, $unknownCredential, (int)$updated['revision']);
            profile_check(false, 'Reject unknown credentials at save.');
        } catch (InvalidArgumentException $exception) {
            profile_check(synk_faculty_profile_fetch($conn, $id) === $updated, 'Invalid credential IDs cannot partially change a profile.');
        }
        $clearedProfile = $partial;
        $clearedProfile['eligibility_ids'] = [];
        $clearedProfile['faculty_id_number'] = '';
        $conn->query('SAVEPOINT profile_selection_clear');
        profile_check(synk_faculty_profile_save($conn, $id, $clearedProfile, (int)$updated['revision']), 'Save with all credentials cleared.');
        profile_check(synk_faculty_profile_fetch($conn, $id)['eligibility_ids'] === [], 'Cleared credentials stay cleared after reload.');
        profile_check(synk_faculty_profile_fetch($conn, $id)['faculty_id_number'] === '', 'Optional faculty ID can be cleared.');
        $conn->query('ROLLBACK TO SAVEPOINT profile_selection_clear');
        profile_check(synk_faculty_profile_fetch($conn, $id) === $updated, 'Transaction rollback restores profile and selections together.');
        $invalidRank = $partial;
        $invalidRank['faculty_rank'] = 'Made-up rank';
        try {
            synk_faculty_profile_save($conn, $id, $invalidRank, (int)$updated['revision']);
            profile_check(false, 'Reject unknown ranks at the persistence boundary.');
        } catch (InvalidArgumentException $exception) {
            profile_check(true, 'Unknown rank rejected.');
        }
        try {
            synk_faculty_profile_save($conn, 2147483647, $valid, 0);
            profile_check(false, 'A nonexistent account must be rejected.');
        } catch (mysqli_sql_exception $exception) {
            profile_check($exception->getCode() === 1452, 'Foreign key rejects an unregistered account.');
        }

        $educationPrefix = 'Profile test ' . bin2hex(random_bytes(8));
        $sharedValues = [
            'degree' => $educationPrefix . ' Bachelor of Science in Computing',
            'institution' => $educationPrefix . ' University',
            'address' => $educationPrefix . ' City, Province',
            'specialization' => $educationPrefix . ' Data Science',
        ];
        $educationProfile = $updated;
        foreach (['bachelor', 'master', 'doctoral'] as $level) {
            foreach ($sharedValues as $type => $value) {
                $educationProfile[$level . '_' . $type] = $value;
            }
        }
        profile_check(synk_faculty_profile_save($conn, $id, $educationProfile, (int)$updated['revision']), 'Save reusable entries from all education levels.');
        $educationSaved = synk_faculty_profile_fetch($conn, $id);
        foreach ($sharedValues as $type => $value) {
            $options = synk_faculty_education_search($conn, $type, $educationPrefix);
            profile_check(count($options['results']) === 1 && $options['results'][0]['text'] === $value, 'All levels share one ' . $type . ' entry.');
            synk_faculty_education_register($conn, $type, "  " . strtoupper(str_replace(' ', '  ', $value)) . "  ");
            $options = synk_faculty_education_search($conn, $type, $educationPrefix);
            profile_check(count($options['results']) === 1 && $options['results'][0]['text'] === $value, 'Reuse case/spacing variants of ' . $type . '.');
            foreach (['bachelor', 'master', 'doctoral'] as $level) {
                profile_check($educationSaved[$level . '_' . $type] === $value, 'Reload ' . $level . ' ' . $type . '.');
            }
        }
        $otherId = $ids[1];
        profile_check(synk_faculty_profile_save($conn, $otherId, $educationProfile, (int)($before[$otherId]['revision'] ?? 0)), 'Another account can reuse shared education values.');
        profile_check(synk_faculty_profile_fetch($conn, $otherId)['bachelor_degree'] === $sharedValues['degree'], 'Reused degree is saved to the other account.');
        profile_check(count(synk_faculty_education_search($conn, 'degree', $educationPrefix)['results']) === 1, 'Sharing across accounts does not duplicate an option.');
        $staleEducation = $educationProfile;
        $staleEducation['bachelor_degree'] = $educationPrefix . ' stale draft';
        profile_check(!synk_faculty_profile_save($conn, $id, $staleEducation, (int)$updated['revision']), 'Reject stale educational edits.');
        profile_check(!synk_faculty_education_search($conn, 'degree', $staleEducation['bachelor_degree'])['results'], 'Stale edits do not publish suggestions.');
        synk_faculty_education_register($conn, 'address', $educationPrefix . ' 100%_Place');
        profile_check(count(synk_faculty_education_search($conn, 'address', $educationPrefix . ' 100%_Place')['results']) === 1, 'Search treats percent and underscore literally.');
        profile_check(!synk_faculty_education_search($conn, 'degree', "' OR 1=1 --")['results'], 'Search safely handles SQL-like input.');
        $pagePrefix = $educationPrefix . ' page ';
        for ($n = 0; $n < 28; $n++) {
            synk_faculty_education_register($conn, 'specialization', $pagePrefix . sprintf('%02d', $n));
        }
        $pageOne = synk_faculty_education_search($conn, 'specialization', $pagePrefix, 1);
        $pageTwo = synk_faculty_education_search($conn, 'specialization', $pagePrefix, 2);
        profile_check(count($pageOne['results']) === 25 && $pageOne['pagination']['more'], 'Paginate the first 25 shared suggestions.');
        profile_check(count($pageTwo['results']) === 3 && !$pageTwo['pagination']['more'], 'Return the remaining suggestions once.');
        profile_check(!array_intersect(array_column($pageOne['results'], 'id'), array_column($pageTwo['results'], 'id')), 'Search pages do not overlap.');
        try {
            synk_faculty_education_register($conn, 'invalid_type', 'Example');
            profile_check(false, 'Reject unexpected education categories.');
        } catch (InvalidArgumentException $exception) {
            profile_check(true, 'Education categories are allowlisted.');
        }
    } finally {
        $conn->rollback();
    }
    foreach ($ids as $id) {
        profile_check(synk_faculty_profile_fetch($conn, $id) === $before[$id], 'All test profile writes were rolled back.');
    }
    profile_check((int)$conn->query('SELECT COUNT(*) AS total FROM tbl_faculty_education_options')->fetch_assoc()['total'] === $educationCountBefore, 'Test suggestions rolled back together with profile data.');
}
echo 'Faculty profile: ', $checks, ' checks passed.', PHP_EOL;

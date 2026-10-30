<?php

// Questionnaire and scale transcribed from SKSU-INS-EFP-01, Revision 01,
// effective January 14, 2026. The attachment specifies frequency anchors,
// but no aggregate percentage formula or student/supervisor weighting.
function synk_student_faculty_evaluation_current_version(): string
{
    return 'sksu-set-2026-rev01';
}

function synk_student_faculty_evaluation_instrument(string $version = ''): array
{
    if ($version === 'legacy-20') {
        return [
            'version' => 'legacy-20',
            'label' => 'Previous 20-question instrument',
            'question_count' => 20,
            'effective_date' => '',
        ];
    }

    return [
        'version' => synk_student_faculty_evaluation_current_version(),
        'label' => 'SKSU-INS-EFP-01 · Revision 01',
        'question_count' => 15,
        'effective_date' => 'January 14, 2026',
    ];
}

function synk_student_faculty_evaluation_record_version(array $evaluation): string
{
    $version = (string)($evaluation['instrument_version'] ?? '');
    if ($version !== '') {
        return $version;
    }
    // Older installations can still be viewed before the schema migration.
    return (int)($evaluation['question_count'] ?? 20) === 15
        ? synk_student_faculty_evaluation_current_version()
        : 'legacy-20';
}

function synk_student_faculty_evaluation_is_current(array $evaluation): bool
{
    return synk_student_faculty_evaluation_record_version($evaluation) === synk_student_faculty_evaluation_current_version()
        && (int)($evaluation['question_count'] ?? 0) === 15;
}

function synk_student_faculty_evaluation_scale(string $version = ''): array
{
    if ($version === 'legacy-20') {
        return synk_student_faculty_evaluation_legacy_scale();
    }

    return [
        5 => ['label' => 'Always manifested', 'description' => 'Evident in nearly all relevant situations (91-100% of instances).'],
        4 => ['label' => 'Often manifested', 'description' => 'Evident most of the time, with occasional lapses (61-90%).'],
        3 => ['label' => 'Sometimes manifested', 'description' => 'Evident about half the time (31-60%).'],
        2 => ['label' => 'Seldom manifested', 'description' => 'Infrequently Demonstrated: Rarely evident in relevant instances (11-30%).'],
        1 => ['label' => 'Never/Rarely manifested', 'description' => 'Seldom Demonstrated. Almost never evident, with only isolated cases (0-10%).'],
    ];
}

function synk_student_faculty_evaluation_question_bank(string $version = ''): array
{
    if ($version === 'legacy-20') {
        return synk_student_faculty_evaluation_legacy_question_bank();
    }

    return [
        [
            'key' => 'teaching_learning',
            'title' => 'Management of Teaching and Learning',
            'description' => 'Management of Teaching and Learning refers to the intentional and organized handling of classroom presence, clear communication of academic expectations, efficient use of time, and the purposeful use of student-centered activities that promote critical thinking, independent learning, reflection, decision-making, and continuous academic improvement through constructive feedback.',
            'items' => [
                ['key' => 'MTL1', 'text' => 'Comes to class on time.'],
                ['key' => 'MTL2', 'text' => 'Explains learning outcomes, expectations, grading system, and various requirements of the subject/course.'],
                ['key' => 'MTL3', 'text' => 'Maximizes the allocated time/learning hours effectively.'],
                ['key' => 'MTL4', 'text' => 'Facilitates students to think critically and creatively by providing appropriate learning activities.'],
                ['key' => 'MTL5', 'text' => 'Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.'],
                ['key' => 'MTL6', 'text' => 'Communicates constructive feedback to students for their academic growth'],
            ],
        ],
        [
            'key' => 'knowledge_pedagogy_technology',
            'title' => 'Content Knowledge, Pedagogy and Technology',
            'description' => "Content Knowledge, Pedagogy, and Technology refer to a teacher's ability to demonstrate a strong grasp of subject matter, present complex concepts in a clear and accessible way, relate content to real-world contexts and current developments, engage students through appropriate instructional strategies and digital tools, and apply assessment methods aligned with intended learning outcomes.",
            'items' => [
                ['key' => 'CPT1', 'text' => 'Demonstrates extensive and broad knowledge of the subject/course.'],
                ['key' => 'CPT2', 'text' => 'Simplifies complex ideas in the lesson for ease of understanding.'],
                ['key' => 'CPT3', 'text' => 'Relates the subject matter to contemporary issues and developments in the discipline and/or daily life activities.'],
                ['key' => 'CPT4', 'text' => 'Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms'],
                ['key' => 'CPT5', 'text' => 'Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.'],
            ],
        ],
        [
            'key' => 'commitment_transparency',
            'title' => 'Commitment and Transparency',
            'description' => 'Commitment and Transparency refer to the teacher’s consistent dedication to supporting student learning by acknowledging learner diversity, offering timely academic support and feedback, and upholding fairness and accountability through the use of clear and openly communicated performance criteria.',
            'items' => [
                ['key' => 'CT1', 'text' => 'Recognizes and values the unique diversity and individual differences among students.'],
                ['key' => 'CT2', 'text' => 'Assists students with their learning challenges during consultation hours.'],
                ['key' => 'CT3', 'text' => 'Provides immediate feedback on student outputs and performance.'],
                ['key' => 'CT4', 'text' => "Provides transparent and clear criteria in rating student's performance."],
            ],
        ],
    ];
}

function synk_student_faculty_evaluation_flat_questions(string $version = ''): array
{
    $questions = [];
    $order = 1;
    foreach (synk_student_faculty_evaluation_question_bank($version) as $category) {
        foreach ($category['items'] as $item) {
            $questions[$item['key']] = [
                'category_key' => $category['key'],
                'category_title' => $category['title'],
                'question_key' => $item['key'],
                'question_order' => $order++,
                'question_text' => $item['text'],
            ];
        }
    }
    return $questions;
}

function synk_student_faculty_evaluation_has_comment($comment): bool
{
    // A required comment must contain visible text, including Unicode input.
    return is_string($comment) && preg_match('/[^\s\p{Z}\p{C}]/u', $comment) === 1;
}

function synk_student_faculty_evaluation_normalize_answers(array $answers): array
{
    $normalized = [];
    foreach (synk_student_faculty_evaluation_flat_questions() as $questionKey => $question) {
        $value = $answers[$questionKey] ?? null;
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-5]$/D', $value)))
            || (int)$value < 1 || (int)$value > 5) {
            throw new RuntimeException('All 15 SET questions must be answered using whole-number ratings from 1 to 5.');
        }
        $normalized[$questionKey] = (int)$value;
    }
    return $normalized;
}

// This is percentage of maximum possible points, not observed frequency.
// Use raw sums/counts, and round only the displayed results.
function synk_student_faculty_evaluation_score(float $totalPoints, int $answerCount): array
{
    $mean = $answerCount > 0 ? $totalPoints / $answerCount : 0.0;
    $percentage = $answerCount > 0 ? $totalPoints / ($answerCount * 5) * 100 : 0.0;
    return [
        'mean' => round($mean, 2),
        'percentage' => round($percentage, 2),
        'student_component_percentage' => round($percentage * 0.60, 2),
    ];
}

function synk_student_faculty_evaluation_percentage_from_mean(float $mean): float
{
    return round((max(0, min(5, $mean)) / 5) * 100, 2);
}

function synk_student_faculty_evaluation_legacy_scale(): array
{
    return [
        5 => [
            'label' => 'Outstanding',
            'description' => 'The performance almost always exceeds the job requirements. The faculty is an exceptional role model.',
        ],
        4 => [
            'label' => 'Very Satisfactory',
            'description' => 'The performance meets and often exceeds the job requirements.',
        ],
        3 => [
            'label' => 'Satisfactory',
            'description' => 'The performance meets and sometimes exceeds the job requirements.',
        ],
        2 => [
            'label' => 'Fair',
            'description' => 'The performance needs some development to meet job requirements.',
        ],
        1 => [
            'label' => 'Poor',
            'description' => 'The faculty fails to meet job requirements.',
        ],
    ];
}

function synk_student_faculty_evaluation_legacy_question_bank(): array
{
    return [
        [
            'key' => 'commitment',
            'title' => 'Commitment',
            'items' => [
                ['key' => 'COM1', 'text' => 'Demonstrates sensitivity to students’ ability to attend and absorb content information.'],
                ['key' => 'COM2', 'text' => 'Integrates sensitivity to his/her learning objectives with those of the students in a collaborative process.'],
                ['key' => 'COM3', 'text' => 'Makes her/himself available to students beyond official time.'],
                ['key' => 'COM4', 'text' => 'Coordinates student needs with internal and external enabling groups.'],
                ['key' => 'COM5', 'text' => 'Supplements available resources.'],
            ],
        ],
        [
            'key' => 'subject_matter',
            'title' => 'Knowledge of Subject Matter',
            'items' => [
                ['key' => 'KSM1', 'text' => 'Discusses the subject matter without completely relying on the prescribed reading.'],
                ['key' => 'KSM2', 'text' => 'Draws and shares information on the state-of-the-art theory and practice in his/her discipline.'],
                ['key' => 'KSM3', 'text' => 'Integrates subject to practical circumstances and learning intents/purposes of students.'],
                ['key' => 'KSM4', 'text' => 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.'],
                ['key' => 'KSM5', 'text' => 'Demonstrates up-to-date knowledge and/or awareness on current trends and issues of the subject.'],
            ],
        ],
        [
            'key' => 'independent_learning',
            'title' => 'Teaching for Independent Learning',
            'items' => [
                ['key' => 'TIL1', 'text' => 'Employs teaching strategies that allow students to practice using concepts they need to understand (interactive discussion).'],
                ['key' => 'TIL2', 'text' => 'Provides exercises which develop critical and analytical thinking among the students.'],
                ['key' => 'TIL3', 'text' => 'Enhances student self-esteem through proper recognition of their abilities.'],
                ['key' => 'TIL4', 'text' => 'Allows students of the course to create their own use of well-defined objectives and realistic student-faculty rules.'],
                ['key' => 'TIL5', 'text' => 'Empowers students to make their own decisions and be accountable for their performance.'],
            ],
        ],
        [
            'key' => 'management_of_learning',
            'title' => 'Management of Learning',
            'items' => [
                ['key' => 'MOL1', 'text' => 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).'],
                ['key' => 'MOL2', 'text' => 'Assumes roles as facilitator, resource person, coach, inquisitor, integrator, referee in drawing students to contribute to knowledge and understanding of the concepts at hand.'],
                ['key' => 'MOL3', 'text' => 'Designs and implements learning conditions and experience that promotes healthy exchange and/or confrontations.'],
                ['key' => 'MOL4', 'text' => 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.'],
                ['key' => 'MOL5', 'text' => 'Stimulates students’ desire and interest to learn more about the subject matter.'],
            ],
        ],
    ];
}


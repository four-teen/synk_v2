<?php
// Independently transcribed from SKSU-INS-EFP-03, Revision 01, January 14, 2026.
// These are supervisor benchmarks, not aliases of the student questionnaire.
function synk_sef_version(): string { return 'sksu-sef-2026-rev01'; }
function synk_sef_instrument(): array
{
    return ['version'=>synk_sef_version(),'label'=>'SKSU-INS-EFP-03 · Revision 01','effective_date'=>'January 14, 2026','question_count'=>15];
}
function synk_sef_scale(): array
{
    return [
        5=>['label'=>'Always manifested','description'=>'Evident in nearly all relevant situations (91-100% of instances).'],
        4=>['label'=>'Often manifested','description'=>'Evident most of the time, with occasional lapses (61-90%).'],
        3=>['label'=>'Sometimes manifested','description'=>'Evident about half the time (31-60%).'],
        2=>['label'=>'Seldom manifested','description'=>'Infrequently Demonstrated: Rarely evident in relevant instances (11-30%).'],
        1=>['label'=>'Never/Rarely manifested','description'=>'Seldom Demonstrated. Almost never evident, with only isolated cases (0-10%).'],
    ];
}
function synk_sef_categories(): array
{
    return [
        'teaching_learning'=>['title'=>'Management of Teaching and Learning','items'=>[
            ['Comes to class on time.',['Daily Time Record','Faculty schedule and timetable','Informal interview with students']],
            ['Submits updated syllabus, grade sheets, and other required reports on time.',['Documents submission log','Submission receipts or acknowledgment emails']],
            ['Maximizes the allocated time/learning hours effectively.',['Class schedules & Timetables','LMS Logs','Informal interview with students']],
            ['Provide appropriate learning activities that facilitate critical thinking and creativity of students.',['Course syllabus','Learning Plan','Classroom Observation','Information Interview with students','LMS Logs']],
            ['Guides students to learn on their own, reflect on new ideas and experiences, and make decisions in accomplishing given tasks.',['Course Syllabus','Learning Plan','Student Work Samples','Classroom Observation','LMS Logs','Informal Interview with students','Faculty Consultation Log']],
            ['Communicates constructive feedback to students for their academic growth.',['Graded Student Work with Feedback','Faculty Consultation Log','Informal interview with students','Emails or Official correspondence','LMS Logs']],
        ]],
        'knowledge_pedagogy_technology'=>['title'=>'Content Knowledge, Pedagogy and Technology','items'=>[
            ['Demonstrates extensive and broad knowledge of the subject/course.',['Course Syllabus','Learning Plan','IMs developed by the faculty','Informal interview with students','Mentorship or Thesis/Dissertation Advisory Records']],
            ['Simplifies complex ideas in the lesson for ease of understanding.',['Learning Plan','Course Syllabus','Classroom Observation','Informal interview with students','Lecture notes and presentations','LMS logs']],
            ['Integrates contemporary in the discipline and/or daily life activities in the syllabus.',['Course Syllabus','Learning Plan','Classroom Observation','Informal interview with students','LMS logs','IMs developed by the faculty','Participation in Conferences, Webinars and Training']],
            ['Promotes active learning and student engagement by using appropriate teaching and learning resources including ICT tools and platforms.',['Course Syllabus','Learning Plan','Classroom Observation','Informal interview with students','LMS Logs','Multimedia Lecture Materials','Student Work Samples']],
            ['Uses appropriate assessments (projects, exams, quizzes, assignments, etc.) aligned with the learning outcomes.',['Course Syllabus','Learning Plan','Informal interview with students','Assessment tools and rubrics','Exam and Quiz Samples','Graded Student Work Samples','LMS records']],
        ]],
        'commitment_transparency'=>['title'=>'Commitment and Transparency','items'=>[
            ['Recognizes and values the unique diversity and individual differences among students.',['Course Syllabus','Learning Plan','IMs developed by the faculty','Classroom Observation','Informal interview with students']],
            ['Assists students with their learning challenges during consultation hours.',['Course Syllabus','Faculty Consultation Log','Advisory Records','LMS Logs','Emails or Official Correspondence']],
            ['Provides immediate feedback on student outputs and performance.',['Graded Student Work Samples','Assessment tools and rubrics','Informal interview with students','Emails or Official Correspondence','Faculty Consultation Log','Advising Reports']],
            ["Provides transparent and clear criteria in rating student's performance.",['Course Syllabus','Assessment Tools and Rubrics','Informal interview with students','LMS records','Grade Sheets and Records']],
        ]],
    ];
}
function synk_sef_questions(): array
{
    $questions=[]; $order=1;
    foreach (synk_sef_categories() as $categoryKey=>$category) foreach ($category['items'] as $item) {
        $key='SEF'.$order;
        $questions[$key]=['question_key'=>$key,'order'=>$order++,'category_key'=>$categoryKey,'category_title'=>$category['title'],'text'=>$item[0],'verification'=>$item[1]];
    }
    return $questions;
}
function synk_sef_normalize_answers(array $answers): array
{
    $normalized=[];
    if (count($answers)!==15) throw new DomainException('Answer all 15 supervisor statements.');
    foreach (synk_sef_questions() as $key=>$question) {
        $value=$answers[$key] ?? null;
        if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-5]$/D',$value))) || (int)$value<1 || (int)$value>5) throw new DomainException('Rate each supervisor statement using a whole number from 1 to 5.');
        $normalized[$key]=(int)$value;
    }
    return $normalized;
}
function synk_sef_score(float $points,int $answers): ?array
{
    if ($answers<1) return null;
    return ['points'=>$points,'answers'=>$answers,'mean'=>round($points/$answers,2),'percentage'=>round($points/($answers*5)*100,2),
        'component'=>round($points/($answers*5)*40,2)];
}
function synk_sef_combined_score(?array $student,?array $supervisor): ?float
{
    if (!$student || !$supervisor || $student['answers']<1 || $supervisor['answers']<1) return null;
    return round($student['points']/($student['answers']*5)*60+$supervisor['points']/($supervisor['answers']*5)*40,2);
}

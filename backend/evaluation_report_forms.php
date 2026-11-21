<?php

function synk_sef_report_forms(): array
{
    return [
        'training' => [
            'href' => 'training-recommendation-report.php',
            'title' => 'Training Recommendation Form',
            'icon' => 'bx-book-open',
            'number' => '07',
        ],
        'feedback' => [
            'href' => 'faculty-evaluation-feedback-report.php',
            'title' => 'Faculty Evaluation Feedback Report Form',
            'icon' => 'bx-message-square-detail',
            'number' => '06',
        ],
        'consolidated' => [
            'href' => 'consolidated-faculty-performance-report.php',
            'title' => 'Consolidated Faculty Performance Evaluation',
            'icon' => 'bx-table',
            'number' => '04',
        ],
        'individual' => [
            'href' => 'faculty-performance-report.php',
            'title' => 'Individual Faculty Performance Evaluation',
            'icon' => 'bx-user',
            'number' => '05',
        ],
    ];
}

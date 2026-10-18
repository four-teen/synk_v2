<?php
define('SYNK_PROGRAM_CHAIR_CURRICULUM_API',true);
try { require __DIR__ . '/query_prospectus.php'; }
catch (Throwable $exception) {
    error_log('Program Chair curriculum builder: '.$exception->getMessage());
    http_response_code(500); echo 'ERROR|Curriculum changes could not be saved. Please try again.';
}

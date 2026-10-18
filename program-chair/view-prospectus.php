<?php
define('SYNK_PROGRAM_CHAIR_VIEW',true);
define('SYNK_PROGRAM_CHAIR_CURRICULUM_VIEW',true);
$curriculumViewBufferLevel = ob_get_level();
require __DIR__ . '/../administrator/view-prospectus.php';
while (ob_get_level()>$curriculumViewBufferLevel) ob_end_flush();

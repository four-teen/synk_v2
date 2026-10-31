<?php
require_once __DIR__.'/../backend/program_chair_portal_view.php';
synk_program_chair_render_portal('faculty-performance-report');
require $GLOBALS['synk_program_chair_view_file'];
synk_program_chair_finish_portal();

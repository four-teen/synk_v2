<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/curriculum-page.php';
synk_di_curriculum_render('manage-prospectus-browser.php', $conn, $moduleAccount, $moduleTerm);

<?php
ob_start();
require __DIR__ . '/../college-dean/navbar.php';
$chairNavbarMarkup = ob_get_clean();
echo str_replace('src="view-term-settings.js', 'src="../college-dean/view-term-settings.js', $chairNavbarMarkup);

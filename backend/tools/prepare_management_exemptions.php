<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
foreach (['management_officers.sql','management_exemptions.sql'] as $file) {
    $sql=file_get_contents(__DIR__.'/../sql/'.$file);
    if ($sql===false) throw new RuntimeException('Management exemption schema is unavailable.');
    $conn->multi_query($sql);
    do {
        if ($result=$conn->store_result()) $result->free();
        if (!$conn->more_results()) break;
    } while ($conn->next_result());
}
echo "Management exemption storage prepared. Existing officer permissions, profiles, payments and periods preserved.\n";

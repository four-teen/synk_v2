<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../db.php';
$sql=file_get_contents(__DIR__.'/../sql/management_payments.sql');
$conn->multi_query($sql);
do { if ($result=$conn->store_result()) $result->free(); }
while ($conn->more_results() && $conn->next_result());
echo "Fresh Management payment and receipt storage prepared. No history imported or period settings changed.\n";

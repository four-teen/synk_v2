<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../db.php';
$conn->multi_query(file_get_contents(__DIR__.'/../sql/management_officers.sql'));
do{if($result=$conn->store_result())$result->free();}while($conn->more_results()&&$conn->next_result());
echo "Management student-officer storage prepared. Existing assignments and student profiles unchanged.\n";

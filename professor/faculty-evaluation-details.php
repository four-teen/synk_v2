<?php
ini_set('display_errors','0');
session_start();
session_write_close();
header('Cache-Control: no-store, private, max-age=0');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='GET') { header('Allow: GET'); throw new DomainException('This request method is not supported.',405); }
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '')!=='professor') throw new DomainException('Sign in using your professor account to continue.',401);
    require_once __DIR__.'/../backend/db.php';
    require_once __DIR__.'/../backend/professor_evaluations_helper.php';
    $offeringId=synk_professor_workload_query_integer($_GET['offering_id'] ?? null);
    $evaluationView=$_GET['view'] ?? '';
    if (!$offeringId || !is_string($evaluationView) || !in_array($evaluationView,['pending','results','export'],true)) throw new DomainException('Choose a valid class and evaluation view.',422);
    $expected=[];
    foreach (['college_id','ay_id','semester'] as $field) {
        $expected[$field]=synk_professor_workload_query_integer($_GET[$field] ?? null);
        if (!$expected[$field]) throw new DomainException('Reload the page to use the current college and academic term.',422);
    }
    $evaluationDetail=synk_professor_evaluation_class_detail($conn,$offeringId,$evaluationView==='export'?'pending':$evaluationView,$expected);
    if ($evaluationView==='export') {
        $csv=synk_professor_evaluation_pending_csv($evaluationDetail);
        if (count($evaluationDetail['students'])!==(int)$evaluationDetail['counts']['not_evaluated'] || strlen($csv)<=3) throw new RuntimeException('The student export could not be completed.');
        $filename=synk_professor_evaluation_pending_filename($evaluationDetail);
        $asciiFilename=preg_replace('/[^\x20-\x7E]/','_',$filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$asciiFilename.'"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('Content-Length: '.strlen($csv));
        echo $csv;
    } else {
        ob_start(); require __DIR__.'/faculty-evaluation-panel.php'; $html=ob_get_clean();
        echo json_encode(['ok'=>true,'view'=>$evaluationView,'offering_id'=>$offeringId,'counts'=>$evaluationDetail['counts'],'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
} catch (Throwable $error) {
    $known=$error instanceof DomainException;
    $status=$known && in_array($error->getCode(),[401,403,404,405,409,422],true) ? $error->getCode() : ($known?403:503);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!$known) error_log('Professor evaluation details: '.$error->getMessage());
    echo json_encode(['ok'=>false,'message'=>$known?$error->getMessage():'This evaluation view could not be loaded. Please try again.']);
}

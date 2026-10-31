<?php
// Shared presentation bootstrap only. Both portals revalidate their own account.
if (!isset($sefPage) || !in_array($sefPage,['supervisor','results','report'],true)) { http_response_code(404); exit; }
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
ob_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/supervisor_evaluation_helper.php';
header('Cache-Control: no-store, private');
header('Vary: Cookie');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
// Viewing term is selected in the portal settings, not by evaluation links.
unset($_GET['dean_ay_id'],$_GET['dean_semester'],$_GET['dean_term']);
synk_college_dean_require_login($conn);
$sefActor=synk_sef_actor($conn); $sefScope=$sefActor['scope']; $sefTerm=synk_dean_view_term($conn);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$sefReady=synk_sef_ready($conn); $sefError=''; $sefNotice=$_SESSION['sef_notice'] ?? ''; unset($_SESSION['sef_notice']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    try {
        if (($sefPage ?? '')!=='supervisor') throw new DomainException('Use the Supervisor Evaluation workspace to save evaluations.');
        $sefNotice=synk_sef_write($conn,$_POST);
        $_SESSION['sef_notice']=$sefNotice;
        header('Location: supervisor-evaluations.php'); exit;
    } catch (DomainException $error) { http_response_code($error->getCode()===403?403:422); $sefError=$error->getMessage(); }
    catch (Throwable $error) { http_response_code(500); error_log('Supervisor evaluation: '.$error->getMessage()); $sefError='The evaluation could not be saved. Reload and review the latest records before trying again.'; }
}
$sefCurrent=synk_evaluation_is_current($sefTerm,synk_fetch_current_academic_term($conn));
$sefSettings=synk_sef_settings($conn,$sefScope,$sefTerm);
$sefQuery=[];
$collegeDeanPortalContext=synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName=$sefActor['name']; $collegeDeanPortalDisplayEmail=(string)($_SESSION['email'] ?? '');
function sef_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function sef_url(string $page,array $query): string { return $page.($query?'?'.http_build_query($query):''); }
function sef_hidden(array $values): void { foreach ($values as $name=>$value) echo '<input type="hidden" name="',sef_h($name),'" value="',sef_h($value),'">'; }
$sefFields=['csrf_token'=>$_SESSION['csrf_token'],'college_id'=>$sefScope['college_id'],'ay_id'=>$sefTerm['ay_id'],'semester'=>$sefTerm['semester']];

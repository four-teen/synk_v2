<?php
declare(strict_types=1);

// This same-origin endpoint always derives its identity from the signed-in account.
ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

final class SynkDashboardResearchError extends RuntimeException {}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        throw new SynkDashboardResearchError('This request method is not supported.');
    }
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'professor') {
        http_response_code(401);
        throw new SynkDashboardResearchError('Please sign in using your professor account to load your research.');
    }
    if ($_GET) {
        http_response_code(422);
        throw new SynkDashboardResearchError('The dashboard research request does not accept account or search parameters.');
    }
    if (!is_file(__DIR__ . '/oracle-research.php')) {
        http_response_code(503);
        throw new SynkDashboardResearchError('The Oracle research connection is awaiting setup. Please contact your administrator.');
    }
    require_once __DIR__ . '/oracle-research.php';
    require_once __DIR__ . '/../backend/professor_dashboard_helper.php';
    $identity = SynkOracleResearch::identity(SynkOracleResearch::connection(), $_SESSION, $synkOracleConfig['allowed_domain']);
    session_write_close();
    $portfolio = SynkOracleResearch::fetch($synkOracleConfig, ['action' => 'portfolio', 'email' => $identity['email']]);
    $summary = synk_professor_dashboard_research_summary($portfolio, SynkOracleResearch::roleLabels());
    echo json_encode(['ok' => true, 'email' => $identity['email'], 'summary' => $summary], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $known = $error instanceof SynkOracleError;
    if ($known) {
        http_response_code($error->httpStatus);
    } elseif (http_response_code() < 400) {
        http_response_code(503);
        error_log('Professor dashboard research failed: ' . $error->getMessage());
    }
    $message = $known || $error instanceof SynkDashboardResearchError
        ? $error->getMessage() : 'Research is temporarily unavailable. Please try again in a moment.';
    echo json_encode(['ok' => false, 'message' => $message]);
}

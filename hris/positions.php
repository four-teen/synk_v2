<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/position-catalog-data.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_admin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$reading = in_array($method, ['GET', 'HEAD'], true);
if ($reading && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') === false) {
    header('Location: ./#position-catalog', true, 302);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (!$reading && $method !== 'POST') {
    header('Allow: GET, HEAD, POST');
    $reply(405, ['message' => 'Use the Position Catalog to manage entries.']);
}
if (!$reading && !synk_hris_valid_post()) {
    $reply(403, ['message' => 'This form expired. Reload the page before trying again.']);
}
session_write_close();

try {
    if (!synk_hris_personnel_ready($conn)) {
        $reply(503, ['message' => 'The position catalog is unavailable.']);
    }
    if ($reading) {
        $positions = array_map('synk_hris_catalog_position', array_values(synk_hris_position_catalog($conn)));
        $reply(200, ['positions' => $positions]);
    }

    $action = $_POST['operation'] ?? '';
    if (!in_array($action, ['add', 'edit', 'delete'], true)) {
        $reply(422, ['message' => 'Choose an action in the Position Catalog.']);
    }

    $values = null;
    if ($action !== 'delete') {
        [$values, $error] = synk_hris_validate_catalog_position($_POST);
        if ($error !== '') $reply(422, ['message' => $error]);
    }
    if ($action === 'add') {
        $position = synk_hris_add_catalog_position($conn, $values, synk_hris_actor_id($account));
        $reply(201, ['position' => $position, 'message' => 'Position added to the catalog.']);
    }

    $id = filter_var($_POST['position_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $version = $_POST['version'] ?? '';
    if ($id === false || !is_string($version) || !preg_match('/\A[a-f0-9]{64}\z/', $version)) {
        $reply(422, ['message' => 'Select the position again from the catalog.']);
    }
    $position = synk_hris_change_catalog_position($conn, $id, $version, $values);
    if ($action === 'delete') {
        $reply(200, ['deleted_id' => $id, 'message' => 'Position deleted from the catalog.']);
    }
    $reply(200, ['position' => $position, 'message' => 'Position changes saved.']);
} catch (DomainException $error) {
    $reply(409, ['message' => $error->getMessage(), 'code' => 'stale_position']);
} catch (mysqli_sql_exception $error) {
    if ((int)$error->getCode() === 1062) {
        $reply(409, ['message' => 'This position title is already in the catalog.']);
    }
    if ((int)$error->getCode() === 1451) {
        $reply(409, ['message' => 'This position is assigned to an employee. Update the employee’s position before deleting this entry.']);
    }
    error_log('Kawani position catalog request failed. Database error ' . $error->getCode());
    $reply(503, ['message' => $reading ? 'The position catalog could not be loaded.' : 'The change could not be saved. Try again.']);
}

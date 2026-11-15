<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
define('SYNK_DI_CURRICULUM_API', true);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/di_curriculum_helper.php';
require_once __DIR__ . '/di_catalog_helper.php';

try {
    $catalog = $_GET['catalog'] ?? '';
    if (!is_string($catalog) || !in_array($catalog, ['program','subject'], true)) throw new DomainException('Choose a valid catalog.', 400);
    synk_di_curriculum_authorize($conn, $catalog);
    synk_di_catalog_handle($conn, $catalog);
} catch (Throwable $exception) {
    synk_di_curriculum_error($exception);
}

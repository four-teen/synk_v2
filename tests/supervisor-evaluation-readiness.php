<?php
// CLI-only read-only schema check for manual deployments.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/supervisor_evaluation_helper.php';
if (!synk_sef_ready($conn)) { fwrite(STDERR,"Supervisor evaluation schema is not prepared.\n");exit(1); }
foreach (['tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluations','tbl_supervisor_evaluation_audit'] as $table) {
    echo $table,': ',(int)$conn->query('SELECT COUNT(*) n FROM '.$table)->fetch_assoc()['n'],' row(s)',PHP_EOL;
}
echo 'Supervisor evaluation schema is ready. No records were changed.',PHP_EOL;

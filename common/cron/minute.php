<?php

require_once GEN_HTTP_FNS;
require_once COMMON_DIR . '/trace.php';
require_once FNS_DIR . '/cron/cron_fns.php';
require_once HTTP_FNS . '/rand_crypt/PseudoRandom.php';
require_once QUERIES_DIR . '/artifact_location.php';
require_once QUERIES_DIR . '/bans.php';
require_once QUERIES_DIR . '/gp.php';
require_once QUERIES_DIR . '/messages.php';
require_once QUERIES_DIR . '/servers.php';

// tell the command line
$time = date('r');
output("Minute CRON starting at $time...\n");

// connect
$pdo = pdo_connect();

// perform minute tasks, recording each one so an observer can tell not only
// that this job ran but how far it got
$trace = trace_begin('minute');
trace_task($trace, 'generate_level_list:newest', function () use ($pdo) {
    return generate_level_list($pdo, 'newest');
});
trace_task($trace, 'update_artifact', function () use ($pdo) {
    return update_artifact($pdo);
});
trace_task($trace, 'run_update_cycle', function () use ($pdo) {
    return run_update_cycle($pdo);
});
trace_task($trace, 'write_server_status', function () use ($pdo) {
    return write_server_status($pdo);
});
trace_finish($trace);

// tell the command line
output("Minute CRON successful.\n");

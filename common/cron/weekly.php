<?php

require_once GEN_HTTP_FNS;
require_once COMMON_DIR . '/trace.php';
require_once QUERIES_DIR . '/all_optimize.php';
require_once QUERIES_DIR . '/bans.php';
require_once QUERIES_DIR . '/best_levels.php';
require_once QUERIES_DIR . '/level_backups.php';
require_once QUERIES_DIR . '/messages.php';
require_once QUERIES_DIR . '/new_levels.php';
require_once QUERIES_DIR . '/servers.php';

// tell the command line
$time = date('r');
output("Weekly CRON starting at $time...");

// connect
$pdo = pdo_connect();

$trace = trace_begin('weekly');
trace_task($trace, 'level_backups_delete_old', function () use ($pdo) {
    return level_backups_delete_old($pdo);
});
trace_task($trace, 'new_levels_delete_old', function () use ($pdo) {
    return new_levels_delete_old($pdo);
});
trace_task($trace, 'messages_delete_old', function () use ($pdo) {
    return messages_delete_old($pdo);
});
trace_task($trace, 'bans_delete_old', function () use ($pdo) {
    return bans_delete_old($pdo);
});
trace_task($trace, 'users_reset_status', function () use ($pdo) {
    return users_reset_status($pdo);
});
trace_task($trace, 'best_levels_reset', function () use ($pdo) {
    return best_levels_reset($pdo);
});
trace_task($trace, 'all_optimize', function () use ($pdo, $DB_NAME) {
    return all_optimize($pdo, $DB_NAME);
});
trace_finish($trace);

// tell the command line
output('Weekly CRON successful.');

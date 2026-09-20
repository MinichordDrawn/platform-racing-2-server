<?php

require_once GEN_HTTP_FNS;
require_once COMMON_DIR . '/trace.php';
require_once FNS_DIR . '/cron/cron_fns.php';
require_once QUERIES_DIR . '/changing_emails.php';
require_once QUERIES_DIR . '/exp_today.php';
require_once QUERIES_DIR . '/gp.php';
require_once QUERIES_DIR . '/guild_transfers.php';
require_once QUERIES_DIR . '/rank_tokens.php';
require_once QUERIES_DIR . '/rank_token_rentals.php';
require_once QUERIES_DIR . '/ratings.php';
require_once QUERIES_DIR . '/servers.php';

// tell the command line
$time = date('r');
output("Daily CRON starting at $time...");

// connect
$pdo = pdo_connect();

// Nine tasks in a row with nothing around them: a raise part way through
// silently skips the rest, and a trace saying only "the daily job ran" would
// be true and useless. Each is recorded, so a truncated run shows up as
// missing tasks rather than as a silence.
$trace = trace_begin('daily');
trace_task($trace, 'ratings_delete_old', function () use ($pdo) {
    return ratings_delete_old($pdo);
});
trace_task($trace, 'guilds_reset_gp_today', function () use ($pdo) {
    return guilds_reset_gp_today($pdo);
});
trace_task($trace, 'gp_reset', function () use ($pdo) {
    return gp_reset($pdo);
});
trace_task($trace, 'check_expired_rank_token_rentals', function () use ($pdo) {
    return check_expired_rank_token_rentals($pdo);
});
trace_task($trace, 'tokens_delete_old', function () use ($pdo) {
    return tokens_delete_old($pdo);
});
trace_task($trace, 'guild_transfers_expire_old', function () use ($pdo) {
    return guild_transfers_expire_old($pdo);
});
trace_task($trace, 'changing_emails_expire_old', function () use ($pdo) {
    return changing_emails_expire_old($pdo);
});
trace_task($trace, 'exp_today_truncate', function () use ($pdo) {
    return exp_today_truncate($pdo);
});
trace_task($trace, 'poll_servers:start_new_day', function () use ($pdo) {
    return poll_servers(servers_select($pdo), 'start_new_day`');
});
trace_finish($trace);

// tell the command line
output('Daily CRON successful.');

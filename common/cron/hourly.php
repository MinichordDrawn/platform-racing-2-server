<?php

// all fns
require_once GEN_HTTP_FNS;
require_once COMMON_DIR . '/trace.php';

// ensure part awards
require_once QUERIES_DIR . '/part_awards.php';

// folding_at_home data select/insert/update from/into/in db, send confirmation message
require_once FNS_DIR . '/cron/cron_fns.php';
require_once QUERIES_DIR . '/folding_at_home.php';
require_once QUERIES_DIR . '/messages.php';
require_once QUERIES_DIR . '/rank_tokens.php';

// speak to servers (campaign prizes update), remove expired servers
require_once QUERIES_DIR . '/campaigns.php';
require_once QUERIES_DIR . '/servers.php';

// tell the command line
$time = date('r');
output("Hourly CRON starting at $time...");

// connect
$pdo = pdo_connect();

// Four tasks here are commented out. The declared set in the observer lists
// what this job actually runs, so the difference is visible rather than
// forgotten -- which is the point of comparing a trace against a declaration
// instead of against itself.
$trace = trace_begin('hourly');

try {
    // servers_deactivate_expired($pdo);
    // servers_delete_old($pdo);
    // ensure_awards($pdo);
    foreach (array('newest', 'best', 'best_week', 'campaign') as $mode) {
        trace_task($trace, "generate_level_list:$mode", function () use ($pdo, $mode) {
            return generate_level_list($pdo, $mode);
        });
    }
    trace_task($trace, 'set_campaign', function () use ($pdo) {
        return set_campaign($pdo);
    });
    // fah_update($pdo);

    trace_finish($trace);

    // tell the command line
    output('Hourly CRON successful.');
} catch (Exception $e) {
    // The finish line is deliberately not written here. This catch swallows
    // the failure and lets cron see a successful exit, so until now a failed
    // hourly run was invisible to everything. A trace that starts and never
    // finishes is what makes it visible.
    output('ERROR: Hourly CRON failed. ' . $e->getMessage());
}

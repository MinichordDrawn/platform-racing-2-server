<?php

namespace pr2obs\web;

// What this observer expects the scheduled work on its host to look like
// (SPEC.md section 14.2).
//
// These are this observer's own constants, declared here and not read from the
// jobs. That separation is the whole value of T3: the observer says what a
// schedule ought to run, the trace says what it did, and the two are compared.
// Deriving the expectation from the job would compare the job with itself and
// could never notice a task that had been commented out -- which is precisely
// the condition this is built to surface, since four of hourly.php's tasks
// are commented out right now.
//
// If a job gains or loses a task, this list and the job change together, and
// the cycle in between reports a coverage failure. That is the intended cost:
// a schedule whose declared set has drifted from its code is a schedule nobody
// is really watching.

// Periods are derived from the crontab the image installs, not chosen.
const SCHEDULE_PERIODS = array(
    'minute' => 60,
    'hourly' => 3600,
    'daily'  => 86400,
    'weekly' => 604800,
);

// Margin and deadline are values to be set. The design's only constraints:
// a margin exists and is small against its period; a deadline exceeds the
// job's honest running time and is less than its period. These are provisional
// and want measuring against real runs before they are trusted -- a deadline
// set below a job's true running time reports a healthy job as dying part way
// through, every time it runs.
const SCHEDULE_MARGINS = array(
    'minute' => 30,
    'hourly' => 300,
    'daily'  => 600,
    'weekly' => 3600,
);

const SCHEDULE_DEADLINES = array(
    'minute' => 30,
    'hourly' => 600,
    'daily'  => 3600,
    'weekly' => 7200,
);

// The task identifiers each schedule declares. Where a function is called more
// than once in a job, the argument follows a colon, so the calls stay
// distinguishable.
const SCHEDULE_DECLARED = array(
    'minute' => array(
        'generate_level_list:newest',
        'update_artifact',
        'run_update_cycle',
        'write_server_status',
    ),
    'hourly' => array(
        // servers_deactivate_expired, servers_delete_old, ensure_awards and
        // fah_update are commented out in the job. They are deliberately not
        // declared: this list is what the job runs, and the commented-out four
        // are recorded in the design as a finding rather than hidden here.
        'generate_level_list:newest',
        'generate_level_list:best',
        'generate_level_list:best_week',
        'generate_level_list:campaign',
        'set_campaign',
    ),
    'daily' => array(
        'ratings_delete_old',
        'guilds_reset_gp_today',
        'gp_reset',
        'check_expired_rank_token_rentals',
        'tokens_delete_old',
        'guild_transfers_expire_old',
        'changing_emails_expire_old',
        'exp_today_truncate',
        'poll_servers:start_new_day',
    ),
    'weekly' => array(
        'level_backups_delete_old',
        'new_levels_delete_old',
        'messages_delete_old',
        'bans_delete_old',
        'users_reset_status',
        'best_levels_reset',
        'all_optimize',
    ),
);

// In the shape check_traces() expects.
function schedules_config(): array
{
    $out = array();
    foreach (SCHEDULE_PERIODS as $name => $period) {
        $out[] = array(
            'schedule'         => $name,
            'period_seconds'   => $period,
            'margin_seconds'   => SCHEDULE_MARGINS[$name],
            'deadline_seconds' => SCHEDULE_DEADLINES[$name],
            'declared'         => SCHEDULE_DECLARED[$name],
        );
    }
    return $out;
}

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

// The tasks whose affected-row count must not be zero, and only those.
//
// Every task records the affected-row count of its query, and for most of
// these the honest answer is that zero means nothing. The daily job is mostly
// cleanup: a delete-old that found nothing old enough had a quiet day, not a
// broken one. Declaring a minimum for those would fault on the first quiet
// Sunday, and a halt that fires on quiet Sundays teaches a deployment to
// ignore halts -- which costs more than the check is worth.
//
// Two are different in kind, and the difference is in the SQL rather than in
// anyone's judgement:
//
//   guilds_reset_gp_today   UPDATE guilds SET gp_today = 0
//   gp_reset                UPDATE gp     SET gp_today = 0
//
// Neither carries a WHERE clause, so each touches every row of its table on
// every run. A zero from one of them cannot mean "nothing needed doing"; it
// means the table is empty. This deployment has guilds with members in them,
// so a zero is the job not doing what it says, or the table being gone.
//
// **That holds only because those two functions report the rows they reached
// rather than the rows they changed**, and they were changed to do so after
// this list halted a running deployment. MySQL's affected-row count is rows
// changed, so on a day when no guild scored, every gp_today is already 0, an
// update that ran correctly over the whole table returns zero, and the
// minimum below stops the game. A quiet day and an empty table were
// indistinguishable, which is precisely the two-readings problem this list
// exists to avoid. See common/queries/guilds.php and common/queries/gp.php;
// test 100 holds them to it.
//
// Everything absent from this list is unchecked on purpose, and stays that way
// until somebody who knows the game can say what its zero would mean. An
// expectation nobody can justify is worse than none, because it is the one
// that gets ignored.
//
// **This list says something about this game, not about this code.** A freshly
// migrated deployment has no guilds, so its first daily run would change zero
// rows here and halt the ring -- correctly, by the letter, and uselessly. A
// deployment that is not the live game removes these two entries.
const SCHEDULE_MIN_COUNTS = array(
    'minute' => array(),
    'hourly' => array(),
    'daily'  => array(
        'guilds_reset_gp_today' => 1,
        'gp_reset'              => 1,
    ),
    'weekly' => array(),
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
            'min_counts'       => SCHEDULE_MIN_COUNTS[$name],
        );
    }
    return $out;
}

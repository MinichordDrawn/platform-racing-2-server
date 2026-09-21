<?php
// A task that must change rows did.
//
// Every scheduled task records the affected-row count of its query, and until
// now nothing read it. The observer asked three things of a trace -- that it is
// fresh, that it finished, and that it completed the declared set of tasks --
// all of which a job satisfies by running. None of them asks whether running
// achieved anything.
//
// For most of these tasks that is the right silence. `count` is an affected-row
// count, and the daily job is mostly cleanup: a delete-old that found nothing
// old enough had a quiet day, not a broken one. Declaring a minimum for those
// would fault on the first quiet Sunday, and a halt that fires on quiet Sundays
// teaches a deployment to ignore halts.
//
// Two of them are different in kind, and the difference is visible in the SQL:
//
//   guilds_reset_gp_today   UPDATE guilds SET gp_today = 0
//   gp_reset                UPDATE gp     SET gp_today = 0
//
// Neither carries a WHERE clause. Each touches every row of its table on every
// run, so a zero does not mean "nothing needed doing" -- it means the table is
// empty. On a deployment with guilds that have members in them, and this one
// has them, a zero is the job not doing what it says or the table being gone.
//
// So the minimum is declared per task, by a person who knows the game, and only
// where a zero has one reading. Everything undeclared is unchecked, on purpose,
// and stays that way until somebody can say what its zero would mean.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/schedules.php';
require_once REPO . '/observers/web/traces.php';

echo "a task that must change rows did\n";

// --- the minimum is declared, and only where a zero has one reading --------

$declared = \pr2obs\web\SCHEDULE_MIN_COUNTS;

ok(isset($declared['daily']['guilds_reset_gp_today']),
    'guilds_reset_gp_today must change at least one row');
ok(isset($declared['daily']['gp_reset']),
    'and so must gp_reset');

// The cleanup tasks are deliberately absent. Named individually, because the
// point is that these specific ones are left unchecked rather than that the
// list happens to be short.
foreach (array(
    'tokens_delete_old'          => 'nothing aged out today',
    'changing_emails_expire_old' => 'nobody left a change pending',
    'ratings_delete_old'         => 'nothing aged out today',
    'guild_transfers_expire_old' => 'none were pending',
    'exp_today_truncate'         => 'TRUNCATE reports zero whatever happens',
) as $task => $why) {
    ok(!isset($declared['daily'][$task]),
        "$task declares no minimum, because a zero there means: $why");
}

// And it reaches the configuration the cycle reads.
$config = \pr2obs\web\schedules_config();
$daily = null;
foreach ($config as $s) {
    if ($s['schedule'] === 'daily') {
        $daily = $s;
    }
}
ok($daily !== null, 'the daily schedule is configured');
ok(isset($daily['min_counts']['gp_reset']),
    'and carries the minimums into the check');

// --- the check ------------------------------------------------------------

function t100_reader($now)
{
    return new \pr2obs\web\Reader(
        sys_get_temp_dir(), 'web',
        array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30, 'now' => $now,
        ),
        array(), array()
    );
}

// One finished daily run, with whatever counts the case wants.
function t100_trace($counts)
{
    $dir = sys_get_temp_dir() . '/pr2trace-' . getmypid() . '-' . mt_rand();
    mkdir("$dir/daily", 0777, true);

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $lines = array(json_encode(array(
        'kind' => 'trace', 'version' => 1, 'schedule' => 'daily',
        'run' => 1, 'started' => $now,
    )));
    foreach (\pr2obs\web\SCHEDULE_DECLARED['daily'] as $id) {
        $lines[] = json_encode(array(
            'kind' => 'task', 'id' => $id, 'completed' => true,
            'count' => array_key_exists($id, $counts) ? $counts[$id] : 7,
        ));
    }
    $lines[] = json_encode(array('kind' => 'finish', 'finished' => $now));

    file_put_contents("$dir/daily/0000000001.trace", implode("\n", $lines) . "\n");
    return array($dir, $now);
}

function t100_rm($dir)
{
    foreach (glob("$dir/daily/*") as $f) {
        @unlink($f);
    }
    @rmdir("$dir/daily");
    @rmdir($dir);
}

function t100_checks($counts)
{
    list($dir, $now) = t100_trace($counts);
    $R = t100_reader($now);
    \pr2obs\web\check_traces($R, $dir, \pr2obs\web\schedules_config(), 86400 * 3);
    t100_rm($dir);

    $out = array();
    foreach ($R->findings as $f) {
        $out[] = $f['check'];
    }
    return $out;
}

// Everything did something: no complaint about effect.
ok(!in_array('trace-effect', t100_checks(array()), true),
    'a run where every task changed rows raises nothing');

// The two that cannot legitimately be zero.
foreach (array('gp_reset', 'guilds_reset_gp_today') as $task) {
    ok(in_array('trace-effect', t100_checks(array($task => 0)), true),
        "$task reporting zero rows is a finding");
}

// A count that is missing altogether is not a pass. A task declared to change
// rows that reports no number did not run its query, whatever else it did.
ok(in_array('trace-effect', t100_checks(array('gp_reset' => null)), true),
    'and so is a declared task reporting no count at all');

// The cleanup tasks are still free to do nothing.
ok(!in_array('trace-effect', t100_checks(array('tokens_delete_old' => 0)), true),
    'while a cleanup task that found nothing to clean is left alone');

// --- and the observer says it ran the check -------------------------------

foreach (glob(REPO . '/observers/*/apply.php') as $file) {
    $name = basename(dirname($file));
    ok(strpos(file_get_contents($file), "'trace-effect'") !== false,
        "$name counts trace-effect as its own finding");
}
foreach (glob(REPO . '/observers/*/cycle.php') as $file) {
    $name = basename(dirname($file));
    ok(strpos(file_get_contents($file), "'trace-effect:'") !== false,
        "$name publishes the check in its heartbeat");
}

t_done();

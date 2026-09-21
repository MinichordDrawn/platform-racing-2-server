<?php
// The work writes a trace that the observer accepts.
//
// Same pairing as the heartbeat: the writer and the reader are separate pieces
// of code that have to agree about bytes, and the cheapest way to find out
// that they do not is to run one against the other. Here it matters more than
// usual, because the writer is the application and the reader is an observer
// that shares no code with it -- there is nothing but this test and the
// specification holding the two together.
//
// It also exercises the three things a trace exists to make visible:
//   a job that stops running at all          -> trace-fresh
//   a job that dies part way through         -> trace-complete
//   a job that quietly stops doing something -> trace-coverage
//
// The third is the one that cannot be seen any other way. A job that runs
// faithfully every day with a task commented out succeeds every time, and
// nothing anywhere notices -- which is exactly the condition four of
// hourly.php's tasks are in right now.

require_once __DIR__ . '/helper.php';

// trace.php is application code and uses the application's cache directory.
$tmp = sys_get_temp_dir() . '/pr2trace-' . getmypid();
define('CACHE_DIR', $tmp);

require_once REPO . '/common/trace.php';
require_once REPO . '/observers/web/procedures.php';
require_once REPO . '/observers/web/traces.php';
require_once REPO . '/observers/web/schedules.php';

echo "the work writes a trace the observer accepts\n";

function t75_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t75_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

// A reader configured as the web observer is, with a clock we control.
function t75_reader($now)
{
    $R = new \pr2obs\web\Reader(
        sys_get_temp_dir() . '/pr2trace-nostores',
        'web',
        array(
            'window' => 4, 'cadence_seconds' => 10, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => $now,
        ),
        null,
        array()
    );
    $R->others = array('multi', 'policy', 'super');
    return $R;
}

function t75_checks($R)
{
    $out = array();
    foreach ($R->findings as $f) {
        $out[] = $f['check'] . ':' . $f['subject'];
    }
    sort($out);
    return $out;
}

// Only the daily schedule, so the other three do not report themselves absent.
function t75_daily_only()
{
    foreach (\pr2obs\web\schedules_config() as $s) {
        if ($s['schedule'] === 'daily') {
            return array($s);
        }
    }
    return array();
}

// How long this deployment has been observed, in seconds: the observer's own
// sequence times its cadence. Long enough here that every schedule is due.
const OBSERVED_LONG = 2000000;

t75_rm($tmp);

// --- a complete run -------------------------------------------------------

$declared = \pr2obs\web\SCHEDULE_DECLARED['daily'];
ok(count($declared) === 9, 'the daily schedule declares nine tasks');

$trace = trace_begin('daily');
ok(is_array($trace), 'the work can begin a trace');
foreach ($declared as $id) {
    trace_task($trace, $id, function () { return 0; });
}
trace_finish($trace);

$file = "$tmp/traces/daily/0000000001.trace";
ok(is_file($file), 'and it lands under its schedule, named by run');

$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), OBSERVED_LONG);
is_same(t75_checks($R), array(), 'the observer accepts a complete run with no findings');

// Every line is one complete JSON object ending in a line feed.
$lines = explode("\n", rtrim(file_get_contents($file), "\n"));
is_same(count($lines), 11, 'one header line, nine task lines, one finish line');
is_same(substr(file_get_contents($file), -1), "\n", 'the file ends with a line feed');
$head = json_decode($lines[0], true);
is_same($head['kind'], 'trace', 'the first line is the header');
is_same($head['run'], 1, 'whose run matches the name');
is_same(json_decode(end($lines), true)['kind'], 'finish', 'and the last is the finish');

// --- a run that dies part way through ------------------------------------
//
// The failure worth catching. A start with no finish is what distinguishes a
// job that died mid-run from one that never started, and the two want
// different responses.

$trace = trace_begin('daily');
$threw = false;
try {
    trace_task($trace, $declared[0], function () { return 0; });
    trace_task($trace, $declared[1], function () { throw new RuntimeException('the database went away'); });
    trace_finish($trace);
} catch (\Throwable $e) {
    $threw = true;
}
ok($threw, 'a failing task still raises, so the job stops as it always did');

$file2 = "$tmp/traces/daily/0000000002.trace";
$body = file_get_contents($file2);
ok(strpos($body, '"kind":"finish"') === false, 'and writes no finish line');
ok(strpos($body, '"completed":false') !== false, 'while recording which task it was');

// Within the deadline, an unfinished run is simply in progress.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), OBSERVED_LONG);
is_same(t75_checks($R), array(), 'inside its deadline that is in progress, not a failure');

// Past it, it is a job that died.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z', time() + 4000));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), OBSERVED_LONG);
is_same(t75_checks($R), array('trace-complete:daily'), 'past its deadline it is reported');

// --- a job that quietly stops doing something ----------------------------

$trace = trace_begin('daily');
foreach ($declared as $id) {
    if ($id === 'tokens_delete_old') {
        continue;          // as if the line had been commented out
    }
    trace_task($trace, $id, function () { return 0; });
}
trace_finish($trace);

$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), OBSERVED_LONG);
is_same(t75_checks($R), array('trace-coverage:daily'),
    'a task that stopped running is visible against the declared set');

// --- a job that stops running at all --------------------------------------

$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z', time() + 90000));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), OBSERVED_LONG);
is_same(t75_checks($R), array('trace-fresh:daily'),
    'a schedule past its period plus margin is reported as not running');

// --- never run yet is not the same as stale ------------------------------
//
// A deployment that came up ten minutes ago has no daily trace and nothing is
// wrong: the daily job is not due. Reporting it would halt every fresh
// deployment for a day and every new one for a week -- and the two schedules
// that cannot simply be run at startup to avoid that are exactly daily and
// weekly, because one resets players' counters and the other optimises every
// table.
//
// The clock is the observer's own sequence times its cadence, not the
// container's uptime. That distinction is the whole point: uptime resets on
// restart, so a schedule that is never scheduled at all would be granted a
// fresh period of grace every time the container bounced and would never be
// reported -- which is the one case this check exists for.

function t75_weekly_only()
{
    foreach (\pr2obs\web\schedules_config() as $s) {
        if ($s['schedule'] === 'weekly') {
            return array($s);
        }
    }
    return array();
}

$weekly = t75_weekly_only();
$due_after = $weekly[0]['period_seconds'] + $weekly[0]['margin_seconds'];

ok(!is_dir("$tmp/traces/weekly"), 'the weekly schedule has never run here');

// Ten minutes of observation: not due, so nothing is reported.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", $weekly, 600);
is_same(t75_checks($R), array(), 'a schedule that is not due yet reports nothing');

// One second short of due: still nothing.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", $weekly, $due_after - 1);
is_same(t75_checks($R), array(), 'and still nothing the moment before it is due');

// Due, and it never ran. This is the finding the design wanted: the weekly job
// not scheduled at all, which nothing noticed for as long as it was true.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z'));
\pr2obs\web\check_traces($R, "$tmp/traces", $weekly, $due_after);
is_same(t75_checks($R), array('trace-fresh:weekly'),
    'once it is overdue and has never run, it is reported');

// The grace applies only to a schedule with no runs at all. Once one exists,
// ordinary staleness governs and the deployment's age is irrelevant -- so a
// job that ran once and then stopped is caught however new the deployment is.
$R = t75_reader(gmdate('Y-m-d\TH:i:s\Z', time() + 90000));
\pr2obs\web\check_traces($R, "$tmp/traces", t75_daily_only(), 0);
is_same(t75_checks($R), array('trace-fresh:daily'),
    'a schedule that ran once and stopped is reported whatever the deployment age');

// --- every schedule the observer declares is one the work writes ----------
//
// The observer's declared sets are its own constants, deliberately not derived
// from the jobs -- that is what lets T3 notice a task that has been commented
// out. The cost is that the two can drift, so the names are checked against
// the jobs here, where drift is cheap to find.
foreach (\pr2obs\web\SCHEDULE_DECLARED as $schedule => $ids) {
    $job = file_get_contents(REPO . "/common/cron/$schedule.php");
    ok(
        strpos($job, "trace_begin('$schedule')") !== false,
        "$schedule.php opens a trace"
    );
    $missing = array();
    foreach ($ids as $id) {
        // The identifier appears either literally or, where a job loops, as
        // the interpolated form.
        $literal = "'$id'";
        $interpolated = str_replace(':' . substr($id, strpos($id, ':') + 1), ':$', $id);
        if (strpos($job, $literal) === false
            && (strpos($id, ':') === false || strpos($job, '"' . $interpolated) === false)
        ) {
            $missing[] = $id;
        }
    }
    is_same($missing, array(), "every task $schedule declares is traced in the job");
}

// --- traces do not accumulate for ever ------------------------------------
//
// The design records no retention rule, and the minute job alone would leave
// 1,440 files a day. The writer prunes its own schedule's folder, which keeps
// the single-writer rule: nobody prunes anybody else's.
for ($i = 0; $i < 12; $i++) {
    $t = trace_begin('minute');
    trace_task($t, 'generate_level_list:newest', function () { return 0; });
    trace_finish($t);
}
$kept = array_values(array_filter(scandir("$tmp/traces/minute"), function ($n) {
    return preg_match('/^[0-9]{10}\.trace$/', $n) === 1;
}));
is_same(count($kept), TRACE_KEEP_RUNS, 'only the most recent runs are kept');
is_same(end($kept), '0000000012.trace', 'and the newest is the one just written');

t75_rm($tmp);

t_done();

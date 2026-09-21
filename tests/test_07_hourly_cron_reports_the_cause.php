<?php
// When the hourly job fails, it must say why.
//
// hourly.php catches Exception and prints a bare "ERROR: Hourly CRON failed."
// without touching $e, so the cause is lost. This is the job that rebuilds
// every level list and sets the campaign, so a silent failure is the whole
// signal a maintainer gets.

require_once __DIR__ . '/helper.php';

define('GEN_HTTP_FNS', __DIR__ . '/stubs/cron_env/gen.php');
define('FNS_DIR', __DIR__ . '/stubs/cron_env');
define('QUERIES_DIR', __DIR__ . '/stubs/cron_queries');

// The job now leaves a trace, so it needs the real trace writer and somewhere
// to write to.
define('COMMON_DIR', REPO . '/common');
define('CACHE_DIR', sys_get_temp_dir() . '/pr2trace07-' . getmypid());

echo "hourly cron: a failure reports its cause\n";

// The job prints as it goes; capture it rather than let it reach the report.
ob_start();
require REPO . '/common/cron/hourly.php';
$printed = ob_get_clean();

ok(strpos($printed, 'Hourly CRON failed') !== false, 'the job reports that it failed');
ok(
    strpos($printed, 'the database went away while building newest') !== false,
    'the reason for the failure is reported'
);
is_same(
    strpos($printed, 'Hourly CRON successful.') === false,
    true,
    'it does not also claim success'
);

// --- and the failure is legible from outside the job ---------------------
//
// The message above goes to cron's output, where this catch swallows the
// exception and cron sees a clean exit -- so until there was a trace, nothing
// downstream could tell a failed hourly run from a successful one. A run that
// starts and never finishes is what makes it visible.

$trace_file = CACHE_DIR . '/traces/hourly/0000000001.trace';
ok(is_file($trace_file), 'the job leaves a trace of the run');

if (is_file($trace_file)) {
    $body = file_get_contents($trace_file);
    ok(strpos($body, '{"kind":"trace"') === 0, 'which opens with its header');
    ok(strpos($body, '"kind":"finish"') === false, 'and has no finish line, because it did not finish');
    ok(
        strpos($body, '"id":"generate_level_list:newest","completed":false') !== false,
        'naming the task that failed'
    );
    ok(
        strpos($body, '"id":"generate_level_list:best"') === false,
        'and showing the run got no further'
    );
}

foreach (glob(CACHE_DIR . '/traces/hourly/*') as $f) {
    @unlink($f);
}
@rmdir(CACHE_DIR . '/traces/hourly');
@rmdir(CACHE_DIR . '/traces');
@rmdir(CACHE_DIR);

t_done();

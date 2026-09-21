<?php
// The daily and weekly jobs must actually be scheduled.
//
// Both scripts exist and are complete, and nothing anywhere runs them. Only
// the minute and hourly jobs are scheduled, so everything these two do has
// never happened: rented ranks are never given back, guild transfers and
// pending e-mail address changes never lapse, old login tokens are never
// deleted, expired bans are never cleared out, daily and weekly counters are
// never reset, and old messages, level backups and new-level rows are never
// removed.
//
// Every one of these is an expiry. Code that takes a privilege away and never
// runs leaves the privilege in place for good.

require_once __DIR__ . '/helper.php';

echo "the daily and weekly jobs are scheduled\n";

$cron = file_get_contents(REPO . '/docker/minute-cron');

// The schedules are named as arguments to the wrapper rather than as scripts.
// The wrapper is the coupling: it reads the observer network itself, and when
// the ring says stop it records a refused run instead of the work. What this
// file is about is unchanged -- that all four are scheduled at all.

// what was already scheduled stays scheduled
ok(preg_match('/run\.php minute\b/', $cron) === 1, 'the minute job is still scheduled');
ok(preg_match('/run\.php hourly\b/', $cron) === 1, 'the hourly job is still scheduled');

// and the two that were not, now are
ok(preg_match('/run\.php daily\b/', $cron) === 1, 'the daily job is scheduled');
ok(preg_match('/run\.php weekly\b/', $cron) === 1, 'the weekly job is scheduled');

// Each line has to be a real crontab entry: five time fields, a user, and a
// command. A malformed line is ignored by cron without saying so.
$lines = preg_split('/\r?\n/', trim($cron));
$seen = array();
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) {
        continue;
    }
    ok(
        preg_match('/^(\S+\s+){5}(\S+)\s+\S+/', $line, $fields) === 1,
        'the entry is well formed: ' . substr($line, 0, 40)
    );
    // The user field is not decoration. cron is the one process in this
    // deployment that has to start as root, and the only thing that is for
    // is dropping to an unprivileged user before running anything. A job
    // scheduled as root undoes that in one word.
    if (isset($fields[2])) {
        ok(
            $fields[2] !== 'root' && $fields[2] !== '0',
            'and runs the job unprivileged: ' . substr($line, 0, 40)
        );
    }
    foreach (array('minute', 'hourly', 'daily', 'weekly') as $which) {
        if (preg_match('/run\.php ' . $which . '\b/', $line) === 1) {
            $seen[$which] = $line;
        }
    }
}

is_same(count($seen), 4, 'all four jobs have an entry');

// They must not all fire at once. The daily job polls every game server and
// the weekly one optimises every table, so running them on top of the minute
// and hourly jobs concentrates the load.
if (isset($seen['daily']) && isset($seen['hourly'])) {
    $daily_fields = preg_split('/\s+/', $seen['daily']);
    $hourly_fields = preg_split('/\s+/', $seen['hourly']);
    ok(
        $daily_fields[0] !== $hourly_fields[0] || $daily_fields[1] !== $hourly_fields[1],
        'the daily job does not fire at the same moment as the hourly one'
    );
}
if (isset($seen['weekly']) && isset($seen['daily'])) {
    $weekly_fields = preg_split('/\s+/', $seen['weekly']);
    $daily_fields = preg_split('/\s+/', $seen['daily']);
    ok(
        $weekly_fields[0] !== $daily_fields[0] || $weekly_fields[1] !== $daily_fields[1],
        'the weekly job does not fire at the same moment as the daily one'
    );
}

// The scripts they point at have to be the ones that exist.
ok(file_exists(REPO . '/common/cron/run.php'), 'the wrapper every entry names exists');
ok(file_exists(REPO . '/common/cron/daily.php'), 'the daily script exists');
ok(file_exists(REPO . '/common/cron/weekly.php'), 'the weekly script exists');

t_done();

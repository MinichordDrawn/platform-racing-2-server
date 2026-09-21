<?php
// A replay row that cannot be written must not take the race down with it.
//
// The three replay inserts run execute() with no result check and return void,
// so under the warning mode a failure left a replay file with no row and no
// trace. Under the explicit error mode the statement now throws instead, and
// the throw would travel up through the finish handler into the socket
// dispatcher's catch-all, which drops the packet. A replay is a record of the
// race, not the race itself, so it is reported and the race continues.

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/helper_log.php';
require_once REPO . '/common/queries/replays.php';

echo "replay writes: a failure is recorded, and does not break the race\n";

// A database with none of the replay tables.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log = capture_log_start();
$threw = false;
try {
    replay_insert(
        $pdo,
        'replay-abc',
        1234,
        2,
        'race',
        1000,
        60000,
        '/replays/replay-abc.bin',
        512,
        7,
        59000,
        59500,
        3,
        3,
        4
    );
} catch (Throwable $t) {
    $threw = true;
}
$written = capture_log_read($log);

is_same($threw, false, 'the failure does not propagate into the race');
ok($written !== '', 'the failure is written to the log');
ok(strpos($written, 'replay-abc') !== false, 'the log names the replay');

@unlink($log);
t_done();

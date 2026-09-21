<?php
// A replay is found by where the data lives now, not by where it lived when
// it was recorded.
//
// The recorder built an absolute path and wrote it into the database, and the
// download endpoint opened whatever that column said. Moving the replay root
// out of the served tree would therefore orphan every row already written:
// the file is still on the same volume, but every stored path names a
// directory that no longer exists.
//
// So the column stores a path relative to the replay root, and a reader
// resolves it against the root as it stands now. Moving the root again costs
// nothing.
//
// It closes something else on the way past. Opening an absolute path straight
// out of a database column means whatever writes that column chooses which
// file the web tier opens. Resolving a relative name against a fixed root
// means the column chooses which replay, and nothing else. Only the recorder
// writes it today, so this is a bound rather than a fix -- but it is the
// difference between a rule that holds and one that happens to hold.

require_once __DIR__ . '/helper.php';

// The resolver is deliberately its own file with no dependencies, so it can
// be exercised without standing up the application.
define('DATA_DIR', '/pr2/data');
require_once REPO . '/functions/replay_path_fns.php';

echo "a replay is found where the data lives\n";

ok(function_exists('replay_root'), 'the replay root is named in one place');
ok(function_exists('replay_path_store'), 'there is a form that goes in the database');
ok(function_exists('replay_path_resolve'), 'and a form a reader opens');

if (!function_exists('replay_path_resolve')) {
    t_done();
}

// --- what gets stored is relative -----------------------------------------

is_same(replay_root(), '/pr2/data/replays', 'the root is under the data directory');

is_same(
    replay_path_store('/pr2/data/replays/8p/1234/8p_1234_5_99.pr2r'),
    '8p/1234/8p_1234_5_99.pr2r',
    'a recorded path is stored relative to the root'
);

// The recorder can be given a different base. What is stored is still
// relative, so a test harness writing elsewhere does not poison the column.
is_same(
    replay_path_store('/tmp/rec/pr2hub/7/hub_7_1_2.pr2r', '/tmp/rec'),
    'pr2hub/7/hub_7_1_2.pr2r',
    'a path under an injected base is stored relative to that base'
);

$threw = false;
try {
    replay_path_store('/etc/passwd');
} catch (Exception $e) {
    $threw = true;
}
ok($threw, 'a path outside the root is refused rather than stored');

// --- what a reader opens --------------------------------------------------

is_same(
    replay_path_resolve('8p/1234/8p_1234_5_99.pr2r'),
    '/pr2/data/replays/8p/1234/8p_1234_5_99.pr2r',
    'a stored path resolves against the root as it stands now'
);

// Rows written before the move hold an absolute path under the served tree.
// The bytes never moved -- only the mount point did.
is_same(
    replay_path_resolve('/pr2/http_server/replays/8p/1234/8p_1234_5_99.pr2r'),
    '/pr2/data/replays/8p/1234/8p_1234_5_99.pr2r',
    'a row written before the move still finds its file'
);

// --- and nothing else -----------------------------------------------------

$refused = array(
    '../../etc/passwd'                  => 'a traversal',
    '8p/../../../etc/passwd'            => 'a traversal below a valid prefix',
    '/etc/passwd'                       => 'an absolute path',
    ''                                  => 'an empty value',
    '8p//1234/x.pr2r'                   => 'an empty segment',
    '8p/./1234/x.pr2r'                  => 'a current-directory segment',
    'C:/Windows/System32/config/sam'    => 'a drive-qualified path',
);

foreach ($refused as $value => $what) {
    $threw = false;
    try {
        replay_path_resolve($value);
    } catch (Exception $e) {
        $threw = true;
    }
    ok($threw, "$what is refused");
}

// --- the call sites use them ----------------------------------------------

$recorder = file_get_contents(REPO . '/multiplayer_server/ReplayRecorder.php');

ok(
    strpos($recorder, 'replay_path_fns.php') !== false,
    'the recorder loads the resolver'
);
ok(
    preg_match('/replay_path_store\s*\(/', $recorder) === 1,
    'and stores the relative form'
);
ok(
    preg_match('/replay_insert\s*\((?:[^;]*?)\(string\)\s*\$this->path/s', $recorder) !== 1,
    'the absolute path is no longer what goes into the database'
);

$endpoint = file_get_contents(REPO . '/http_server/replays_get.php');

ok(
    strpos($endpoint, 'replay_path_resolve') !== false,
    'the endpoint resolves the stored path'
);

// The raw column must not reach anything that touches the filesystem.
$raw_uses = array();
foreach (array('is_file', 'filesize', 'fopen', 'file_get_contents', 'readfile') as $fn) {
    if (preg_match_all('/\b' . $fn . '\s*\(\s*\$row->file_path/', $endpoint, $m)) {
        $raw_uses[] = $fn;
    }
}
is_same($raw_uses, array(), 'and never opens the column directly');

t_done();

<?php
// A level save must not report success when the level file was not written.
//
// upload_level.php opens the level file and, when fopen() fails, skips the
// write with no else branch and carries on to "The save was successful."
// The player is told their level saved when it did not.
//
// This is a static check on that one block: driving the endpoint would need
// the token login, ban check, salted hash match and S3 client that gate it,
// which is out of proportion to the change.

require_once __DIR__ . '/helper.php';

echo "upload_level: a failed level-file write is reported, not swallowed\n";

$src = file_get_contents(REPO . '/http_server/upload_level.php');

$start = strpos($src, '// write to the file system');
$end = strpos($src, '// save the new file to the backup system');
ok($start !== false && $end !== false && $end > $start, 'the level-file write block is present');

$block = substr($src, $start, $end - $start);

ok(strpos($block, 'fopen(') !== false, 'the block opens the level file');
ok(
    strpos($block, 'throw new Exception') !== false,
    'a failed write raises instead of continuing to the success message'
);
is_same(
    preg_match('/if\s*\(\s*\$file\s*!==\s*false\s*\)\s*\{/', $block),
    0,
    'the silent skip-on-failure form is gone'
);

t_done();

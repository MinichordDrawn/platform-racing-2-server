<?php
// A failed level backup must be recorded.
//
// backup_level() catches its own exception, sets $success = false and returns
// it, and the caller in upload_level.php discards the return. So a backup that
// never happened leaves no trace anywhere. The save itself should still
// succeed, so the function keeps returning false rather than throwing.

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/helper_log.php';
require_once REPO . '/functions/http_fns/query_fns.php';

echo "backup_level: a failed backup is written to the log\n";

class FailingS3
{
    public function copyObject($a, $b, $c, $d)
    {
        return false;
    }
}

$log = capture_log_start();
$result = backup_level(test_pdo(), new FailingS3(), 7, 1234, 2, 'My Level');
$written = capture_log_read($log);

is_same($result, false, 'the caller is still told the backup failed');
ok($written !== '', 'the failure is written to the log');
ok(strpos($written, '1234') !== false, 'the log names the level');

@unlink($log);
t_done();

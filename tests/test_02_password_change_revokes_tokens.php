<?php
// A password change must revoke the account's existing login tokens.
//
// token_login() accepts a token from POST, GET or cookie
// (functions/http_fns/query_fns.php:48-58), so expiring only the browser
// cookie leaves every previously issued token usable. Tokens have no working
// expiry either: tokens_delete_old() is only called from common/cron/daily.php,
// which nothing in the tree schedules.

require_once __DIR__ . '/helper.php';

// The endpoint calls header(); buffer so CLI does not warn about sent headers.
ob_start();

define('GEN_HTTP_FNS', __DIR__ . '/stubs/gen_http_fns.php');
define('HTTP_FNS', __DIR__ . '/stubs');
define('QUERIES_DIR', REPO . '/common/queries');

echo "change_password: a password change revokes existing login tokens\n";

$pdo = test_pdo();
$GLOBALS['test_pdo'] = $pdo;
$GLOBALS['test_user_id'] = 7;

$pdo->exec("INSERT INTO users (user_id, name, pass_hash, temp_pass_hash)
            VALUES (7, 'racer', 'HASHED:old', NULL)");
// Two tokens for this account, and one for a different account that must survive.
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (7, 'tok-a', '2026-01-01')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (7, 'tok-b', '2026-01-02')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (9, 'tok-other', '2026-01-03')");

$LOGIN_KEY = 'unused';
$LOGIN_IV = 'unused';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['i'] = json_encode([
    'name' => 'racer',
    'old_pass' => 'oldpass',
    'new_pass' => 'newpass',
]);

// The endpoint ends in die(), so the assertions run from a shutdown handler.
register_shutdown_function(function () use ($pdo) {
    echo "\n";

    $mine = $pdo->query('SELECT COUNT(*) FROM tokens WHERE user_id = 7')->fetchColumn();
    $other = $pdo->query('SELECT COUNT(*) FROM tokens WHERE user_id = 9')->fetchColumn();
    $user = user_row($pdo, 7);

    is_same($user->pass_hash, 'HASHED:newpass', 'the new password is stored');
    is_same((int) $mine, 0, 'the account\'s own login tokens are revoked');
    is_same((int) $other, 1, 'another account\'s token is left alone');

    t_done();
});

require REPO . '/http_server/change_password.php';

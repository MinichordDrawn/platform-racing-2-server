<?php
// A password change must invalidate any outstanding temporary credential.
//
// pass_login() accepts temp_pass_hash as an alternative credential
// (functions/http_fns/query_fns.php:27-28), so a temporary password that
// survives a deliberate password change stays a working way in.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/queries/users.php';

echo "user_update_pass: a password change clears the temporary credential\n";

$pdo = test_pdo();
$pdo->exec("INSERT INTO users (user_id, name, pass_hash, temp_pass_hash)
            VALUES (7, 'racer', 'OLD_REAL_HASH', 'TEMP_HASH_FROM_RESET')");

user_update_pass($pdo, 7, 'NEW_REAL_HASH');

$user = user_row($pdo, 7);

is_same($user->pass_hash, 'NEW_REAL_HASH', 'the new password is stored');
is_same($user->temp_pass_hash, null, 'the temporary credential is cleared');

t_done();

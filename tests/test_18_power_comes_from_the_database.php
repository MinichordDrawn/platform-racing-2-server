<?php
// The socket server must not take an account's power from the caller.
//
// process_register_login() read $login_obj->user->power straight out of the
// payload, and the Player it builds reads the same field, so whatever the
// caller asserted became the in-memory group. isAdmin() tests only that group,
// which is what turns the process role into an administrator session.
//
// The power is a fact about the account, so it is read from the database.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/queries/users.php';
require_once REPO . '/functions/multi_fns/utils.php';

function output($str)
{
}

echo "register_login: the account's power is read, not accepted\n";

// db_op() works through the global $pdo.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, name TEXT, power INTEGER,
                                trial_mod INTEGER, ca INTEGER, time INTEGER)');
$pdo->exec("INSERT INTO users (user_id, name, power, trial_mod, ca, time)
            VALUES (7, 'racer', 1, 0, 0, 0)");
$GLOBALS['pdo'] = $pdo;

is_same(verified_power(7), 1, 'an ordinary account reads as its stored power');
is_same(verified_power(4242), 0, 'an account that does not exist reads as no power');

// The lookup must survive an unknown id rather than raising, because that is
// exactly the case a forged id produces.
$raised = false;
try {
    user_select_name_active_power($pdo, 4242, true);
} catch (Throwable $t) {
    $raised = true;
}
is_same($raised, false, 'the query returns false for an unknown id rather than raising');

// The handler must no longer read the asserted value into the group.
$src = file_get_contents(REPO . '/functions/multi_fns/process_fns.php');
$fn = substr($src, strpos($src, 'function process_register_login'));
$fn = substr($fn, 0, strpos($fn, "\nfunction "));
ok(strpos($fn, 'verified_power(') !== false, 'the handler asks for the stored power');
is_same(
    preg_match('/\$group\s*=\s*\(int\)\s*\$login_obj->user->power\s*;/', $fn) === 1
        && strpos($fn, 'verified_power(') > strpos($fn, '$login_obj = json_decode'),
    true,
    'the group is taken only after the payload value has been replaced'
);

t_done();

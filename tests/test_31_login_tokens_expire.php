<?php
// A login token must stop working once it is old enough, whether or not
// anything has got round to deleting it.
//
// The tokens table records when each one was made and the clean-up deletes
// rows older than a month, but the lookup never read that column, so a token
// worked for ever. Nothing in the tree schedules the clean-up either, so in
// practice no token was ever removed and none of them expired.
//
// Reading the age at the lookup covers every request that authenticates with a
// token, because they all arrive through this one function, and it does not
// depend on the clean-up having run.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/queries/tokens.php';

echo "login tokens expire\n";

$pdo = test_pdo();

$fresh = date('Y-m-d H:i:s');
$inside = date('Y-m-d H:i:s', time() - (29 * 86400));
$beyond = date('Y-m-d H:i:s', time() - (40 * 86400));

$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (1, 'fresh', '$fresh')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (2, 'inside', '$inside')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (3, 'beyond', '$beyond')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (4, 'undated', '')");
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (5, 'nonsense', 'not a date')");

// returns '' when accepted, or the reason it was refused
function tried($pdo, $token)
{
    try {
        token_select($pdo, $token);
        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

is_same(tried($pdo, 'fresh'), '', 'a token made just now is accepted');
is_same(tried($pdo, 'inside'), '', 'a token inside the month is accepted');

$stale = tried($pdo, 'beyond');
ok($stale !== '', 'a token older than the month is refused');
ok(stripos($stale, 'expired') !== false, 'the refusal says the login expired rather than that it was not found');

// A row whose age cannot be read is refused rather than let through.
ok(tried($pdo, 'undated') !== '', 'a token with no time recorded is refused');
ok(tried($pdo, 'nonsense') !== '', 'a token with an unreadable time is refused');

// Still the ordinary answer for something that was never issued.
$missing = tried($pdo, 'never-issued');
ok($missing !== '', 'a token that does not exist is still refused');
ok(stripos($missing, 'expired') === false, 'a token that does not exist is not reported as expired');

// The life is configurable, and a nonsensical setting does not disable it.
$GLOBALS['TOKEN_MAX_AGE_SECONDS'] = 60;
ok(tried($pdo, 'inside') !== '', 'a shorter configured life is applied');

$GLOBALS['TOKEN_MAX_AGE_SECONDS'] = 0;
is_same(tried($pdo, 'inside'), '', 'a zero life falls back to the default rather than expiring everything');

$GLOBALS['TOKEN_MAX_AGE_SECONDS'] = -1;
is_same(tried($pdo, 'inside'), '', 'a negative life falls back to the default');

unset($GLOBALS['TOKEN_MAX_AGE_SECONDS']);
is_same(token_max_age(), 2592000, 'the default is the month the clean-up deletes at');

// The lookup has to read the column for any of this to be possible.
$src = file_get_contents(REPO . '/common/queries/tokens.php');
ok(
    preg_match('/SELECT\s+user_id,\s*token,\s*time/i', $src) === 1,
    'the lookup selects the time the token was made'
);

t_done();

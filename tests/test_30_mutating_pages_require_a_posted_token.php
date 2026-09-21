<?php
// Every mutating staff page must prove the request came from the game or a
// staff page, not from somewhere else that made the browser send its cookie.
//
// These pages authorize on the session cookie alone. A browser attaches that
// cookie to any request to the site whatever caused it, so a logged-in
// moderator or admin reading any other page could have these perform a ban, a
// level moderation, an artifact placement, a guild deletion or an account
// rewrite without knowing.
//
// The defence is to require the session token in the request body as well.
// Another site can cause the browser to send its cookies but cannot read them,
// so it cannot put the value in the body.
//
// This needs the client to send the field on the four pages it calls. The
// server side is done first and the client follows.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/common_fns.php';
require_once REPO . '/common/queries/tokens.php';
require_once REPO . '/functions/http_fns/http_data_fns.php';

echo "mutating pages require a posted token\n";

$pdo = test_pdo();
$now = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO tokens (user_id, token, time) VALUES (5, 'the-token-of-user-five', '$now')");

function refused($pdo, $acting, $token)
{
    try {
        require_posted_token($pdo, $acting, $token);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

is_same(refused($pdo, 5, 'the-token-of-user-five'), false, 'the acting account\'s own token is accepted');
ok(refused($pdo, 6, 'the-token-of-user-five'), 'a token belonging to someone else is refused');
ok(refused($pdo, 5, ''), 'a missing token is refused');
ok(refused($pdo, 5, '   '), 'a blank token is refused');
ok(refused($pdo, 5, 'a token nobody was ever given'), 'a token that does not exist is refused');

// --- every page has to require it ---------------------------------------

$pages = array(
    'http_server/ban_user.php' => 'the ban endpoint',
    'http_server/level_moderate.php' => 'the level moderation endpoint',
    'http_server/place_artifact.php' => 'the artifact placement endpoint',
    'http_server/guild_delete.php' => 'the guild deletion endpoint',
    'http_server/admin/update_account.php' => 'the account rewrite page',
);

foreach ($pages as $rel => $label) {
    $src = file_get_contents(REPO . '/' . $rel);
    ok(strpos($src, "default_post('token'") !== false, "$label reads a posted token");
    ok(strpos($src, 'require_posted_token') !== false, "$label requires it");

    // It has to be required, not merely checked when offered. A page that only
    // looks when a token is present is bypassed by leaving it out.
    ok(
        preg_match('/if\s*\(\s*!\s*is_empty\(\s*\$token\s*\)\s*\)/', $src) !== 1,
        "$label does not skip the check when no token is sent"
    );
}

// The check must come after the account is known, or there is nothing to
// compare the token against.
foreach (array('http_server/ban_user.php', 'http_server/level_moderate.php') as $rel) {
    $src = file_get_contents(REPO . '/' . $rel);
    $auth_at = strpos($src, 'check_moderator');
    $tok_at = strpos($src, 'require_posted_token');
    ok($auth_at !== false && $tok_at > $auth_at, "$rel checks the token against a known account");
}

t_done();

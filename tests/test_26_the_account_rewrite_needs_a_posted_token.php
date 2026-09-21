<?php
// The admin account rewrite must prove the request came from its own form.
//
// The page authorizes on the session cookie alone. A browser sends that cookie
// with any request to the site, whoever caused the request, so a logged-in
// admin visiting any other page could have this one perform a rewrite without
// their knowledge. The page rewrites a name, e-mail, guild, flags and every
// part array, and can mail out a replacement password.
//
// Three other pages already guard against this the same way: the form carries
// the session token in a hidden field, and the mutating branch compares the
// posted token's owner against the account acting. An attacker's page cannot
// read the cookie, so it cannot fill the field in.
//
// This page is reached only from its own form and from a link on another staff
// page, never from the game client, so the check can be required outright.

require_once __DIR__ . '/helper.php';

echo "the admin account rewrite needs a posted token\n";

$src = file_get_contents(REPO . '/http_server/admin/update_account.php');

// the form has to send it
ok(
    preg_match('/name=[\'"]token[\'"]/', $src) === 1,
    'the form carries the token in a hidden field'
);
ok(
    strpos($src, "\$_COOKIE['token']") !== false,
    'the field is filled from the session cookie, which another site cannot read'
);

// the mutating branch has to check it
ok(strpos($src, "default_post('token')") !== false, 'the page reads a posted token');
ok(strpos($src, 'require_posted_token') !== false, 'the page requires the posted token');
ok(
    strpos($src, "QUERIES_DIR . '/tokens.php'") !== false,
    'the page requires the token queries'
);

// The check must happen before anything is written. The rewrite begins by
// reading the posted fields in the update branch.
$check_at = strpos($src, 'require_posted_token');
$update_at = strpos($src, "\$action === 'update'");
$write_at = strpos($src, 'user_update_row');
ok($check_at !== false && $update_at !== false, 'both the branch and the check are present');
ok($check_at > $update_at, 'the check sits inside the update branch, not the form branch');
if ($write_at !== false) {
    ok($check_at < $write_at, 'the check happens before the account is written');
}

// The two staff forms that post to the ban endpoint should send the token too,
// so that only the game client is left to catch up.
$ban_form = file_get_contents(REPO . '/http_server/mod/ban.php');
$reports = file_get_contents(REPO . '/http_server/mod/reports.php');
ok(preg_match('/name=[\'"]token[\'"]/', $ban_form) === 1, 'the ban form sends the token');
ok(preg_match('/name=[\'"]token[\'"]/', $reports) === 1, 'the reports ban form sends the token');

// The ban endpoint now requires one outright. What each page requires is
// covered in full by the mutating-pages test.
$ban = file_get_contents(REPO . '/http_server/ban_user.php');
ok(strpos($ban, "default_post('token'") !== false, 'the ban endpoint reads a posted token');
ok(strpos($ban, 'require_posted_token') !== false, 'the ban endpoint requires it');

t_done();

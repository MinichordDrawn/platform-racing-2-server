<?php
// The origin check must distinguish a request from this site's own pages from
// one caused by somebody else's.
//
// is_trusted_ref() compares the referrer against a list of trusted sites and,
// when none matches, falls back to check_local(), which returned true with the
// comment "your logic here". Returning true makes the whole test constant, so
// require_trusted_ref() never refused anything at any of its call sites.
//
// This sits behind the posted token requirement rather than in place of it.
// The token is what actually stops the request; this is a second test that
// costs nothing and catches the same thing earlier.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/check_local_fn.php';
require_once REPO . '/functions/common_fns.php';
require_once REPO . '/functions/http_fns/http_data_fns.php';

echo "the origin check actually checks\n";

$GLOBALS['TRUSTED_REFS'] = array('https://pr2hub.com/');

function with_request($referer, $host)
{
    if ($referer === null) {
        unset($_SERVER['HTTP_REFERER']);
    } else {
        $_SERVER['HTTP_REFERER'] = $referer;
    }
    $_SERVER['HTTP_HOST'] = $host;
}

// A form on one of this site's own pages.
with_request('https://example.org/mod/ban.php?id=4', 'example.org');
ok(check_local(), 'a request from this site\'s own page is local');

// The same page on somebody else's site.
with_request('https://not-us.example.net/attack.html', 'example.org');
is_same(check_local(), false, 'a request from another site is not local');

// No referrer at all says nothing, so it is not treated as saying yes.
with_request(null, 'example.org');
is_same(check_local(), false, 'a request with no referrer is not local');

with_request('', 'example.org');
is_same(check_local(), false, 'a request with an empty referrer is not local');

with_request('not a url', 'example.org');
is_same(check_local(), false, 'a request with an unreadable referrer is not local');

// A host header carrying a port still matches, and case does not matter.
with_request('https://example.org/mod/ban.php', 'example.org:8080');
ok(check_local(), 'a port on the host is ignored');

with_request('https://EXAMPLE.org/mod/ban.php', 'example.ORG');
ok(check_local(), 'the comparison ignores case');

// A host that merely ends with ours is not ours.
with_request('https://notexample.org/attack.html', 'example.org');
is_same(check_local(), false, 'a host that only looks similar is not local');

// And the trusted list still works, which is how the game client passes.
with_request('https://pr2hub.com/play', 'example.org');
ok(is_trusted_ref(), 'a listed trusted referrer passes');

with_request('https://somewhere-else.example.net/', 'example.org');
is_same(is_trusted_ref(), false, 'an untrusted referrer on another host does not pass');

// require_trusted_ref turns that into a refusal rather than returning it.
$threw = false;
try {
    require_trusted_ref('do this', true);
} catch (Exception $e) {
    $threw = true;
}
ok($threw, 'an untrusted request is refused rather than allowed through');

with_request('https://example.org/mod/ban.php', 'example.org');
$threw = false;
try {
    require_trusted_ref('do this', true);
} catch (Exception $e) {
    $threw = true;
}
is_same($threw, false, 'a request from this site\'s own page is allowed through');

// The stub must be gone.
$src = file_get_contents(REPO . '/functions/http_fns/check_local_fn.php');
ok(strpos($src, 'your logic here') === false, 'the placeholder is gone');
ok(
    preg_match('/return\s+true\s*;\s*\}/', $src) !== 1,
    'the function no longer returns true unconditionally'
);

t_done();

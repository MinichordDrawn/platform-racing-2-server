<?php
// A requester must not be able to choose the address they are recorded under.
//
// get_ip() returned the CF-Connecting-IP header whenever it was present, with
// no test of who the request actually came from. Anyone could set that header,
// and around a hundred call sites key on the result: bans, every rate limit,
// the registration cap, the address recorded against an account, and the
// address a kick is remembered by.
//
// Behind a proxy the requester's address really is in a header and the
// connecting address is the proxy's. Straight off the internet the connecting
// address is the requester and the header is theirs to invent. The request
// cannot tell those apart, so the deployment has to say which it is.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/http_data_fns.php';

echo "the requester cannot choose the address they are recorded under\n";

// A deployment that has not said must be refused, not guessed at. Guessing
// wrong in one direction lets anyone pick their address; in the other it puts
// every player behind one address, where a single ban reaches all of them.
unset($GLOBALS['TRUSTED_PROXIES']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';
$threw = false;
$told = '';
try {
    get_ip();
} catch (Throwable $e) {
    $threw = true;
    $told = $e->getMessage();
}
ok($threw, 'a deployment that has not said whether it is behind a proxy is refused');

// The requester is not told about the deployment's configuration; that detail
// belongs in the log.
ok(
    strpos($told, 'TRUSTED_PROXIES') === false && strpos($told, 'env.php') === false,
    'the refusal does not name the setting or the file to the requester'
);

// Said: there is no proxy. The header is then always someone's invention.
$GLOBALS['TRUSTED_PROXIES'] = array();
is_same(get_ip(), '203.0.113.9', 'with no proxy a forwarded header is ignored');

unset($_SERVER['HTTP_CF_CONNECTING_IP']);
is_same(get_ip(), '203.0.113.9', 'with no proxy the connecting address is used');

// Said: a proxy connects from 10.x. Only that proxy is believed.
$GLOBALS['TRUSTED_PROXIES'] = array('10.');

$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';
is_same(get_ip(), '198.51.100.4', 'a forwarded header from the configured proxy is honoured');

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
is_same(get_ip(), '203.0.113.9', 'the very same header from anywhere else is ignored');

$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
is_same(get_ip(), '10.0.0.5', 'a request from the proxy with no header uses the connecting address');

$_SERVER['HTTP_CF_CONNECTING_IP'] = '   ';
is_same(get_ip(), '10.0.0.5', 'a blank forwarded header is not treated as an address');

// A prefix must match from the start, so a configured 10. does not admit
// something that merely contains it.
$_SERVER['REMOTE_ADDR'] = '110.0.0.5';
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.4';
is_same(get_ip(), '110.0.0.5', 'a prefix is matched from the start of the address');

// The setting has to ship, or every deployment meets the refusal above.
$env = file_get_contents(REPO . '/common/env.example.php');
ok(strpos($env, 'TRUSTED_PROXIES') !== false, 'the example environment carries the setting');

t_done();

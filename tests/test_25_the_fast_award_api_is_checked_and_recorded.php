<?php
// The fast award endpoint must be held to what the slow one is held to.
//
// award_part.php rate limits, validates the request through one shared
// function, validates the prize, and writes a row to the prize log naming who
// was awarded what and from where. award_part_fast.php did none of that: it
// compared the key inline, took a list of names and a list of part types in
// one request, and left no record of anything it granted. Nothing anywhere
// showed that an award had happened or who asked for it.
//
// The key comparison is also worth doing in constant time, which helps both
// endpoints since they now share the one function.

require_once __DIR__ . '/helper.php';
// the shared check uses is_empty, which lives with the other common functions
require_once REPO . '/functions/common_fns.php';
require_once REPO . '/functions/http_fns/api_fns.php';

echo "the fast award endpoint is checked and recorded\n";

$GLOBALS['PR2_HUB_API_KEY'] = 'the real key';
$GLOBALS['PR2_HUB_API_ALLOWED_IPS'] = array('10.0.0.1');

function refused($ip, $key, $override)
{
    try {
        validate_api_request($ip, $key, $override);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

// the shared check still behaves as the slow endpoint relies on
is_same(refused('203.0.113.9', 'the real key', true), false, 'the right key is accepted');
is_same(refused('203.0.113.9', 'the wrong key', true), true, 'a wrong key is refused');
is_same(refused('203.0.113.9', '', true), true, 'an empty key is refused');
is_same(refused('203.0.113.9', 'the real key ', true), true, 'a nearly right key is refused');

// with the address check in play, only a listed address passes
is_same(refused('10.0.0.1', 'the real key', false), false, 'a listed address with the right key is accepted');
is_same(refused('203.0.113.9', 'the real key', false), true, 'an unlisted address is refused');

// the comparison is constant time, so a wrong key gives nothing away by how
// long it takes to reject it
$api = file_get_contents(REPO . '/functions/http_fns/api_fns.php');
ok(strpos($api, 'hash_equals') !== false, 'the key comparison is constant time');
ok(
    preg_match('/\$key\s*!==\s*\$PR2_HUB_API_KEY/', $api) !== 1,
    'the plain comparison is gone'
);

// --- the fast endpoint --------------------------------------------------

$fast = file_get_contents(REPO . '/http_server/api/award_part_fast.php');

ok(
    preg_match('/\$_SERVER\[\s*[\'"]HTTP_X_API_TOKEN[\'"]\s*\]\s*\??\?\?\s*[\'"][\'"]\s*\)\s*!==/', $fast) !== 1,
    'the endpoint no longer compares the key inline'
);
ok(strpos($fast, 'validate_api_request') !== false, 'the endpoint uses the shared check');
ok(strpos($fast, 'rate_limit') !== false, 'the endpoint rate limits like the slow one');
ok(strpos($fast, 'prize_action_insert') !== false, 'the endpoint writes a prize log row');
ok(
    strpos($fast, "QUERIES_DIR . '/prize_actions.php'") !== false,
    'the endpoint requires the prize log queries'
);

// The record has to be written before the part is granted, so that an award
// which cannot be recorded does not happen at all.
$record_at = strpos($fast, 'prize_action_insert');
$award_at = strpos($fast, 'award_epic_part_to_user');
ok(
    $record_at !== false && $award_at !== false && $record_at < $award_at,
    'the award is recorded before it is granted'
);

t_done();

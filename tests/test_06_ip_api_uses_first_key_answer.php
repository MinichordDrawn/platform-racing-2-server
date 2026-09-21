<?php
// query_ip_api() must use the first API key's answer when that answer reports
// success, instead of always falling through to the second key.
//
// file_get_contents() returns a string, so the guard `!empty($data->success)`
// reads a property off a string. In PHP 8 that is always null, so the first
// key's answer can never be returned: every lookup makes a second request and
// throws the first result away.
//
// The caller (check_ip_validity) json_decode()s the result, so the function
// must keep returning the raw JSON string.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/ip_api_fns.php';

echo "query_ip_api: the first key's successful answer is used\n";

// Point the link builder at a local fixture tree instead of the real service.
$fixtures = sys_get_temp_dir() . '/pr2_ip_api_' . getmypid();
$IP_API_LINK_PRE = $fixtures . '/';
$IP_API_LINK_SUF = '.json';
$IP_API_KEY_1 = 'key-one';
$IP_API_KEY_2 = 'key-two';

$ip = '203.0.113.7';

// The function picks its first key by the day of the month; mirror that here
// so the test asserts the same thing on any date.
$first = ((int) date('j') % 2 === 0) ? $IP_API_KEY_1 : $IP_API_KEY_2;
$other = ($first === $IP_API_KEY_1) ? $IP_API_KEY_2 : $IP_API_KEY_1;

foreach ([$first, $other] as $key) {
    @mkdir($fixtures . '/' . $key, 0777, true);
}
file_put_contents("$fixtures/$first/$ip.json", '{"success":true,"from":"first","fraud_score":10}');
file_put_contents("$fixtures/$other/$ip.json", '{"success":true,"from":"other","fraud_score":10}');

$result = query_ip_api($ip);
$decoded = json_decode($result);

ok(is_string($result), 'the raw JSON string is returned, as the caller expects');
is_same($decoded->from, 'first', 'the first key\'s answer is the one used');

// When the first key does not report success, the second is still consulted.
file_put_contents("$fixtures/$first/$ip.json", '{"success":false}');
$fallback = json_decode(query_ip_api($ip));
is_same($fallback->from, 'other', 'a failed first answer still falls back to the other key');

// clean up
foreach ([$first, $other] as $key) {
    @unlink("$fixtures/$key/$ip.json");
    @rmdir("$fixtures/$key");
}
@rmdir($fixtures);

t_done();

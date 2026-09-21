<?php
// The server status page reads its file, instead of dying on it.
//
// `server_status.php` decoded the status file into an object and then asked
// `array_key_exists('error', $data)`. PHP 8.0 removed array_key_exists() for
// objects, so that line raised a TypeError on every request, and the image
// runs PHP 8.2. The page has never rendered on this stack: it answered 500,
// found by asking a running container for it rather than by reading the file.
//
// The catch block already on the page could not have helped. A TypeError is an
// Error, not an Exception, so `catch (Exception $e)` does not see it, and the
// failure happened two lines before anything that block guards. A handler that
// cannot catch the thing that actually goes wrong is a handler that makes the
// code look safer than it is.
//
// Three other ways of failing sat underneath it, unasked: the file may not
// exist yet, because the minute job writes it and a fresh deployment has not
// run one; it may not decode; and it may decode to something with no server
// list in it. Each was an undefined-property warning or a foreach over null.
//
// The reading is now one function with one contract, tested here directly,
// which is the reason it was moved out of the page: driving the endpoint needs
// the whole config and output stack, and the decision being made does not.
//
// Note for whoever reads this next: `http_server/index.php` reads the same
// file, decoded as an array, with its own guards. Two readers of one file that
// disagree about how to read it is the shape this project keeps finding, and
// unifying them is a change to the socket-proxy target list rather than a
// bugfix, so it is recorded and not done here.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/common_fns.php';

echo "the server status page reads its file\n";

// --- what a good file produces --------------------------------------------

$good = json_encode(array('servers' => array(
    array('server_id' => 1, 'server_name' => 'Main', 'population' => 3),
    array('server_id' => 2, 'server_name' => 'Guild', 'population' => 0),
)));

$servers = server_status_servers($good);
ok(is_array($servers), 'a well-formed file produces a list');
is_same(count($servers), 2, 'with one entry per server');
ok($servers[0] instanceof stdClass, 'and the entries are objects, as the page reads them');
is_same($servers[0]->server_name, 'Main', 'carrying the fields the writer wrote');

// An empty list is a real answer and not a failure: a deployment may genuinely
// have no servers up, and the page should show an empty table rather than an
// error.
$empty = server_status_servers(json_encode(array('servers' => array())));
ok(is_array($empty) && count($empty) === 0, 'a file with no servers is an empty list, not a refusal');

// --- and every way of failing refuses, separately -------------------------

function t103_refusal($raw, $label)
{
    try {
        server_status_servers($raw);
    } catch (Exception $e) {
        ok(true, $label);
        return $e->getMessage();
    }
    ok(false, $label);
    return '';
}

// file_get_contents returns false when the file is not there. The page hands
// that straight in, so this is the case a fresh deployment actually hits.
$missing = t103_refusal(false, 'a file that is not there refuses');
$broken  = t103_refusal('{not json', 'a file that does not decode refuses');
$shapeless = t103_refusal(json_encode(array('something' => 1)), 'a file with no server list refuses');
$notalist  = t103_refusal(json_encode(array('servers' => 'all of them')), 'a server list that is not a list refuses');
$scalar    = t103_refusal(json_encode('a string'), 'a file that decodes to a scalar refuses');

// The missing case is told apart from the broken one, because they mean
// different things to whoever is reading the page.
ok($missing !== $broken, 'a file not written yet is not reported as a file that is wrong');

// --- an error placed in the file is still honoured ------------------------

$carried = t103_refusal(
    json_encode(array('error' => 'the game is down for maintenance')),
    'an error written into the file is raised'
);
is_same($carried, 'the game is down for maintenance', 'with the writer\'s own words');

// --- and the page uses it, rather than decoding again ---------------------

$page = file_get_contents(REPO . '/http_server/server_status.php');

ok(strpos($page, 'server_status_servers(') !== false, 'the page calls the reader');
ok(strpos($page, "array_key_exists('error'") === false,
    'the call that PHP 8 removed for objects is gone');
ok(preg_match('/json_decode\s*\(/', $page) !== 1, 'and the page does not decode the file itself');
ok(strpos($page, 'foreach ($servers as $server)') !== false,
    'the loop reads what the reader returned');

t_done();

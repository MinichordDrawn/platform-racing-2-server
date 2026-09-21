<?php
// The way in that is not PHP is gated too.
//
// Test 79 proves that every runtime asks before it works, and every runtime it
// names is a PHP process reached through `auto_prepend_file`. That is the
// whole of how the gate is carried -- and it is why this one was missed.
//
// Apache maps /pr2hub/ to the PR2Hub proxy with ProxyPass, so mod_proxy
// answers that route and PHP never runs on it. While every PHP path returned
// 503, this one went on serving from its cache and went on making outbound
// requests to a third party. A halt that leaves a door open is not a halt, and
// a rule carried by one mechanism covers exactly what that mechanism reaches.
//
// The fix is not a second mechanism for carrying the rule. It is the proxy
// reading the store itself, the way every other runtime does -- a sixth
// independent reader, written from the same specification and sharing no code
// with the other five. So this file checks two things: that it is attached,
// and that the two readers have not drifted apart.

require_once __DIR__ . '/helper.php';

echo "the way in that is not php is gated too\n";

$conf  = file_get_contents(REPO . '/docker/pr2hub_proxy.conf');
$gate  = file_get_contents(REPO . '/pr2hub_proxy/gate.go');
$main  = file_get_contents(REPO . '/pr2hub_proxy/main.go');
$php   = file_get_contents(REPO . '/common/observer_gate.php');

// --- it is a way in --------------------------------------------------------
//
// Stated here rather than assumed, because the whole finding is that this
// route did not look like one. It is reachable from outside on the game's own
// origin, and it is served by something with no PHP in it.

ok(preg_match('~^ProxyPass\s+"/pr2hub/"~m', $conf) === 1,
    'Apache maps /pr2hub/ to the proxy');
ok(strpos(file_get_contents(REPO . '/docker/pr2hub_proxy.dockerfile'), 'php') === false,
    'and the proxy runs no PHP, so the prepend that carries the gate cannot reach it');

// --- it is attached --------------------------------------------------------

ok(preg_match('/func \(s \*server\) ServeHTTP\(/', $main) === 1, 'the proxy has one entry point');

$serve_at  = strpos($main, 'func (s *server) ServeHTTP(');
$serve     = substr($main, $serve_at, strpos($main, "\n}", $serve_at) - $serve_at);

ok(strpos($serve, 's.gateReason(') !== false, 'and asks the gate inside it');

// Before the route is built, and therefore before a byte of the request body
// is read. A halted deployment answers one way on every path and says nothing
// about which path was asked for.
$gate_at  = strpos($serve, 's.gateReason(');
$route_at = strpos($serve, 'buildRoute(');
ok($route_at !== false && $gate_at < $route_at, 'before it works out what was asked for');

// And with no exception of any kind.
//
// A liveness route used to sit above the gate, on the reasoning that something
// has to be able to tell a stopped proxy from a dead one. But `ProxyPass`
// maps /pr2hub/ onto this proxy's whole path space with no `<Location>`
// restriction anywhere in the image, and the web tier publishes its ports --
// so the route was reachable by anyone rather than by a supervisor, and
// nothing in the deployment ever called it. During a halt it answered 200
// while every other path answered 503, on the game's own origin, which is the
// one thing both refusal paths are written not to say.
ok(strpos($main, 'healthz') === false, 'and nothing at all is answered ahead of it');
ok(
    preg_match('~^ProxyPass\s+"/pr2hub/"\s+"[^"]*/"~m', $conf) === 1,
    'the whole of the proxy path space is mapped, so there is no unmapped corner to exempt'
);
foreach (glob(REPO . '/docker/*.conf') as $c) {
    ok(strpos(file_get_contents($c), '<Location') === false,
        basename($c) . ' restricts no path, so every one of them is gated');
}

// The one thing the gate must not be talked out of: an unusable environment is
// a refusal, not a default. The proxy still starts, so a misconfiguration
// shows up as a 503 with a reason in the log rather than a container that will
// not come up.
ok(strpos($main, 'if s.cfg.GateSettingsErr != ""') !== false,
    'settings it cannot read are themselves a refusal');

// --- the refusal is the same refusal ---------------------------------------
//
// Not a similar one. A client that learns what a stopped deployment looks like
// should not have to learn it twice.

ok(preg_match('/http_response_code\(503\)/', $php) === 1
    && strpos($main, 'http.StatusServiceUnavailable') !== false,
    'both refuse with 503');
ok(preg_match("/header\('Retry-After: (\d+)'\)/", $php, $p) === 1
    && preg_match('/w\.Header\(\)\.Set\("Retry-After", "(\d+)"\)/', $main, $g) === 1
    && $p[1] === $g[1],
    'both ask for the same wait before a retry');
ok(strpos($php, 'The server is not running.') !== false
    && strpos($main, 'The server is not running.') !== false,
    'and both say the same thing to whoever asked');

// And neither tells a stranger which part stopped. The reason goes to the log.
ok(strpos($main, 'log.Printf("observer gate: refusing to work. %s", reason)') !== false,
    'the reason reaches whoever runs it, not whoever asked');

// --- it reads and never writes ---------------------------------------------
//
// The same division every reader of this tree keeps. verify-stores.sh proves
// it against the kernel; this proves the code never tries.

foreach (array('os.WriteFile', 'os.Create', 'os.Remove', 'os.MkdirAll', 'os.Rename', 'os.OpenFile') as $write) {
    ok(strpos($gate, $write) === false, "the gate does not call $write");
}

// --- the two readers have not drifted --------------------------------------
//
// This is the cost of a second implementation, and the reason it is held to
// exactly the facts that matter. Every value below is one the two readers must
// agree on to be reading the same bytes: disagree on any of them and one of
// them is reading a format the other is not writing.

// The ring, named rather than discovered, in the same spelling.
preg_match("/return array\('web', 'multi', 'policy', 'super'\);/", $php, $m1);
preg_match('/return \[\]string\{"web", "multi", "policy", "super"\}/', $gate, $m2);
ok($m1 !== array() && $m2 !== array(), 'both declare the same four members in the same order');

// SPEC 3.1: the marker, byte for byte.
preg_match("/OBSERVER_GATE_HEARTBEAT_MARKER = '([^']+)'/", $php, $p_marker);
preg_match('/gateHeartbeatMarker = `([^`]+)`/', $gate, $g_marker);
ok(isset($p_marker[1], $g_marker[1]) && $p_marker[1] === $g_marker[1],
    'both classify a heartbeat by the same marker');

// SPEC 3.1: the bound, before anything reads the content.
preg_match('/OBSERVER_GATE_MAX_HEARTBEAT_BYTES = (\d+)/', $php, $p_max);
preg_match('/gateMaxHeartbeatBytes = (\d+)/', $gate, $g_max);
ok(isset($p_max[1], $g_max[1]) && $p_max[1] === $g_max[1],
    'both refuse a heartbeat larger than the same number of bytes');

// SPEC 3.2: the sequence name, so that lexical order is numeric order and a
// staging name is not a heartbeat.
ok(strpos($php, '/^\d{10}\.hb\z/') !== false
    && strpos($gate, '`^\d{10}\.hb\z`') !== false,
    'both take the current heartbeat to be the highest of the same file names');

// SPEC 3.1: the timestamp, exactly -- and anchored with `\z` in both, which
// is the whole of what they used to disagree about. PCRE's `$` matches before
// a final line feed and RE2's does not, so the same bytes were a timestamp to
// one reader and not to the other. Test 95 holds the behaviour; this holds the
// spelling, so that a reader of either file can see the agreement.
ok(strpos($php, '^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z') !== false
    && strpos($gate, '`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z`') !== false,
    'both read the same timestamp and guess at nothing else');

// The format version, so that one of them cannot be reading a heartbeat the
// other would refuse.
ok(preg_match("/\\\$hb\['version'\] !== 1/", $php) === 1
    && strpos($gate, 'version.String() != "1"') !== false,
    'both read version 1 and no other');

// The environment, named identically, with the same bounds. A window the
// deployment did not state is not a window, in either language.
foreach (array('OBSERVER_STORES', 'OBSERVER_SUPER_MAX_AGE_SECONDS') as $name) {
    ok(strpos($php, $name) !== false && strpos($gate, $name) !== false,
        "both take $name from the environment under the same name");
}
ok(strpos($php, "'/stores'") !== false && strpos($gate, '"/stores"') !== false,
    'both default the store root to the same path, and default nothing else');
ok(strpos($php, '< 1 || (int) $raw > 3600') !== false
    && strpos($gate, 'seconds < 1 || seconds > 3600') !== false,
    'both hold the window to the same bounds');

// Every condition under which the PHP gate stops, the Go gate stops on too.
// Named as pairs, because a reader that skipped one of these would be reading
// the same bytes and reaching a different answer.
$conditions = array(
    'a store that is not there'   => array('is not there', 'is not there'),
    'a halt'                      => array('has halted', 'has halted'),
    'a fault'                     => array('is faulted', 'is faulted'),
    'a halt delivered by a peer'  => array('has been delivered to', 'has been delivered to'),
    'a halts directory it cannot read' => array('cannot be read', 'cannot be read'),
    'a heartbeat never written'   => array('has never written a heartbeat', 'has never written a heartbeat'),
    'a missing heartbeat folder'  => array('has no heartbeat folder', 'has no heartbeat folder'),
    'a heartbeat that does not parse' => array('does not parse', 'does not parse'),
    'a misplaced heartbeat'       => array('was not written by', 'was not written by'),
    'an observer that stopped'    => array('has stopped', 'has stopped'),
    'a stale heartbeat'           => array('seconds old', 'seconds old'),
    'a future-dated heartbeat'    => array('seconds from now', 'seconds from now'),
);
foreach ($conditions as $what => $pair) {
    list($p, $g) = $pair;
    ok(strpos($php, $p) !== false && strpos($gate, $g) !== false,
        "both stop on $what");
}

// What the Go reader deliberately does not do, and why. It holds no observer
// and is no member, so there is no local heartbeat for it to read and no
// OBSERVER_LOCAL for it to be told. Reading one would mean trusting a file
// written in a container this one does not share.
// Asked of the code rather than of the prose: gate.go says the words in its
// own header, explaining why it reads no local heartbeat. What matters is that
// it never looks the variable up.
ok(preg_match('/env\("OBSERVER_LOCAL/', $gate) !== 1
    && preg_match('/Getenv\("OBSERVER_LOCAL/', $gate . $main) !== 1,
    'the proxy never reads OBSERVER_LOCAL, because it is no member of the ring');
ok(strpos($gate, 'gateAlive(cfg.GateStores, "super"') !== false,
    'and the only heartbeat it reads is the super observer\'s');

// --- the Go side is checked, and by something that runs -------------------
//
// These two are guards rather than findings. There is no Go toolchain on the
// host and this suite cannot run Go, so gate.go is checked in exactly one
// place: the image build. `go test` on a package with no test files prints
// "no test files" and exits 0, so a build step alone would keep passing if the
// tests were ever removed. This is what notices.

$build = file_get_contents(REPO . '/docker/pr2hub_proxy.dockerfile');
ok(preg_match('/RUN go vet \.\/\.\.\. && go test \.\/\.\.\./', $build) === 1,
    'the image build runs the Go tests before it builds the binary');

$gate_tests = file_get_contents(REPO . '/pr2hub_proxy/gate_test.go');
foreach (array(
    'TestGateAllowsWhenTheRingIsClean'          => 'a clean ring is served',
    'TestGateRefusesOnEveryStopSignal'          => 'every stop signal stops it',
    'TestGateRefusesEveryUnusableSuperHeartbeat' => 'every unusable heartbeat stops it',
    'TestProxyRefusesEveryRouteWhenTheGateSaysStop' => 'every route refuses when it should',
    'TestNothingIsAnsweredAheadOfTheGate' => 'no path is exempt from it',
) as $test => $what) {
    ok(strpos($gate_tests, "func $test(") !== false, "and there is a test that $what");
}

// --- and the deployment says so --------------------------------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');
if (!preg_match('/^  pr2hub-proxy:\s*$/m', $compose, $m, PREG_OFFSET_CAPTURE)) {
    ok(false, 'the compose file has a pr2hub-proxy service');
    t_done();
}
$from = $m[0][1] + strlen($m[0][0]);
$rest = substr($compose, $from);
$end  = preg_match('/^  [a-z0-9][a-z0-9_-]*:\s*$/m', $rest, $n, PREG_OFFSET_CAPTURE) ? $n[0][1] : strlen($rest);
$body = substr($rest, 0, $end);

ok(true, 'the compose file has a pr2hub-proxy service');
ok(strpos($body, '- OBSERVER_STORES=/stores') !== false, 'which is told where the store tree is');
ok(preg_match('/- OBSERVER_SUPER_MAX_AGE_SECONDS=(\d+)/', $body, $s) === 1,
    'and how long the super observer may go quiet');
ok(strpos($body, '- OBSERVER_LOCAL=') === false,
    'and is told of no local observer, matching what it reads');

// It cannot ask the store anything without being able to read it. Test 72
// holds every one of these to read-only.
ok(substr_count($body, ':/stores') === 17, 'it can see the whole store tree');

// The same window the gated PHP containers are given. One of them allowing the
// super observer longer than another would mean two answers to the same
// question depending on which door was knocked on.
preg_match('/^  web:\s*$/m', $compose, $wm, PREG_OFFSET_CAPTURE);
$web = substr($compose, $wm[0][1]);
preg_match('/- OBSERVER_SUPER_MAX_AGE_SECONDS=(\d+)/', $web, $w);
ok(isset($s[1], $w[1]) && $s[1] === $w[1],
    'and the same window as the PHP containers, so every door answers alike');

t_done();

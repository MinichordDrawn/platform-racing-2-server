<?php
// SocketDaemon can deliver reads before its first timer. The policy listener
// must refuse those reads until it has checked the observer gate itself.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/observer_gate.php';
require_once REPO . '/vend/socket/index.php';
require_once REPO . '/policy_server/server.php';
require_once REPO . '/policy_server/server_client.php';

// Exercise the real handler, capturing its output instead of opening a port.
class PolicyGateClient extends \jiggmin\ps\ServerClient
{
    public $sent = '';

    public function __construct() {}

    public function write($buffer, $length = 4096)
    {
        $this->sent .= $buffer;
    }
}

function policy_request($packet)
{
    $client = new PolicyGateClient();
    $client->read_buffer = $packet;
    $client->onRead();
    return $client;
}

$policy = '<policy-file-request/>' . chr(0);
foreach (array($policy, 'status') as $packet) {
    $client = policy_request($packet);
    is_same($client->sent, '', 'no answer before the first gate check');
    is_same($client->read_buffer, '', 'an early request is discarded');
}

$dir = sys_get_temp_dir() . '/pr2-policy-gate-' . bin2hex(random_bytes(8));
foreach (observer_gate_members() as $member) {
    mkdir("$dir/$member/heartbeat", 0777, true);
    mkdir("$dir/$member/halts");
    file_put_contents("$dir/$member/heartbeat/0000000001.hb", json_encode(array(
        'kind' => 'heartbeat', 'version' => 1, 'observer' => $member,
        'sequence' => 1, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'cadence_seconds' => 6, 'checks' => array('own-store-writable'),
        'check_count' => 1, 'observed' => new stdClass(),
        'previous' => null, 'boot' => null, 'stop' => false,
    )));
}

$env = array(
    'OBSERVER_STORES' => $dir,
    'OBSERVER_LOCAL' => 'policy',
    'OBSERVER_LOCAL_MAX_AGE_SECONDS' => '60',
    'OBSERVER_SUPER_MAX_AGE_SECONDS' => '60',
);
$saved = array();
foreach ($env as $name => $value) {
    $saved[$name] = getenv($name);
    putenv("$name=$value");
}

// Bypass only socket construction; onTimer reads the real gate and stores.
$server = (new ReflectionClass(\jiggmin\ps\Server::class))->newInstanceWithoutConstructor();
try {
    file_put_contents("$dir/policy/halts/web", 'halt');
    $server->onTimer();
    is_same(policy_request($policy)->sent, '', 'a first check finding a halt keeps refusing');

    unlink("$dir/policy/halts/web");
    $server->onTimer();
    is_same(policy_request($policy)->sent, \jiggmin\ps\get_policy_file() . chr(0),
        'a healthy check enables policy replies without restarting');
    is_same(policy_request('status')->sent, 'ok' . chr(4), 'status replies recover too');

    file_put_contents("$dir/policy/halts/web", 'halt');
    $server->onTimer();
    $refused = policy_request($policy);
    is_same($refused->sent, '', 'a later halt stops replies again');
    is_same($refused->read_buffer, '', 'a refused request is not queued');

    unlink("$dir/policy/halts/web");
    $server->onTimer();
    $refused->onRead();
    is_same($refused->sent, '', 'recovery does not replay refused work');
    is_same(policy_request($policy)->sent, \jiggmin\ps\get_policy_file() . chr(0),
        'a new request succeeds after recovery');
} finally {
    foreach ($saved as $name => $value) {
        putenv($value === false ? $name : "$name=$value");
    }
    foreach (observer_gate_members() as $member) {
        foreach (glob("$dir/$member/halts/*") as $file) {
            unlink($file);
        }
        unlink("$dir/$member/heartbeat/0000000001.hb");
        rmdir("$dir/$member/heartbeat");
        rmdir("$dir/$member/halts");
        rmdir("$dir/$member");
    }
    rmdir($dir);
}

t_done();

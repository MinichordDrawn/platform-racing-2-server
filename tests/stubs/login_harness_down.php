<?php
// Drives login.php against a server whose status the cron has marked 'down'.
// Uses the real query functions so server_select() behaves as it does live.

define('GEN_HTTP_FNS', __DIR__ . '/login_gen_db.php');
define('HTTP_FNS', __DIR__);
define('QUERIES_DIR', dirname(dirname(__DIR__)) . '/common/queries');

$BLS_IP_PREFIX = 'test';
$LOGIN_KEY = 'unused';
$LOGIN_IV = 'unused';
$ALLOWED_CLIENT_VERSIONS = ['1'];

$GLOBALS['test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['test_pdo']->exec('CREATE TABLE servers (
    server_id INTEGER PRIMARY KEY, server_name TEXT, address TEXT, port INTEGER,
    status TEXT, active INTEGER, guild_id INTEGER, tournament INTEGER, population INTEGER
)');
// active, as an operator left it, but the poller has since marked it down.
$GLOBALS['test_pdo']->exec("INSERT INTO servers
    (server_id, server_name, address, port, status, active, guild_id, tournament, population)
    VALUES (1, 'Main', '127.0.0.1', 9160, 'down', 1, 0, 0, 0)");

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['build'] = '1';
$_POST['i'] = json_encode([
    'user_name' => 'racer',
    'user_pass' => 'secret',
    'build' => '1',
    'remember' => 0,
    'server' => ['server_id' => 1, 'port' => 9160, 'address' => '127.0.0.1'],
]);

require dirname(dirname(__DIR__)) . '/http_server/login.php';

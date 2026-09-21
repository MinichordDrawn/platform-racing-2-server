<?php
// Drives login.php with a blob that does not decode, which is what a stale or
// corrupted client sends. Everything here stops short of the database: the
// endpoint fails long before it connects.

define('GEN_HTTP_FNS', __DIR__ . '/login_gen.php');
define('HTTP_FNS', __DIR__);
define('QUERIES_DIR', __DIR__ . '/login_queries');

$BLS_IP_PREFIX = 'test';
$LOGIN_KEY = 'unused';
$LOGIN_IV = 'unused';
$ALLOWED_CLIENT_VERSIONS = ['1'];

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['i'] = 'this is not json';
$_POST['build'] = '1';

require dirname(dirname(__DIR__)) . '/http_server/login.php';

<?php
// Stand-ins for the shared HTTP helpers, so an endpoint can be exercised
// without a web server, a live database or the real credential paths.

function default_post($key, $default = null)
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function default_get($key, $default = null)
{
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function get_ip()
{
    return '203.0.113.7';
}

function require_trusted_ref($action = '')
{
    return true;
}

function is_empty($str)
{
    return !isset($str) || $str === '';
}

function rate_limit($key, $seconds, $limit, $message = null)
{
    return true;
}

function pdo_connect()
{
    return $GLOBALS['test_pdo'];
}

function pass_login($pdo, $name, $password, $ban_check_scope = 'b')
{
    $user = new stdClass();
    $user->user_id = $GLOBALS['test_user_id'];
    $user->name = $name;
    $user->power = 1;
    return $user;
}

function output($msg)
{
    return true;
}

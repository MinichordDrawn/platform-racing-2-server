<?php
// Helpers login.php needs before it reaches the database.

function default_post($key, $default = null)
{
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function find($key, $default = null)
{
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
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

function rate_limit($key, $seconds, $limit, $message = null)
{
    return true;
}

function is_empty($str)
{
    return !isset($str) || $str === '';
}

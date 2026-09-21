<?php
// Captures whatever the code under test sends to error_log().

function capture_log_start()
{
    $file = sys_get_temp_dir() . '/pr2_errlog_' . getmypid() . '.txt';
    @unlink($file);
    ini_set('log_errors', '1');
    ini_set('error_log', $file);
    return $file;
}

function capture_log_read($file)
{
    return is_file($file) ? file_get_contents($file) : '';
}

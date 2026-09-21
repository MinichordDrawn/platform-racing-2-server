<?php
// Stand-ins for the helpers the hourly job uses. output() echoes so the test
// can read what the job reported.

function output($str)
{
    echo $str . "\n";
}

function pdo_connect()
{
    return new PDO('sqlite::memory:');
}

// The job's first real step. The test makes this fail.
function generate_level_list($pdo, $which)
{
    throw new Exception('the database went away while building ' . $which);
}

function set_campaign($pdo)
{
    return true;
}

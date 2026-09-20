<?php

// Refuses to start on a secret that still holds the value published here.
//
// All three dockerfiles copy env.example.php over env.php, so an image built
// from this tree runs on whatever that file sets, and every key, salt and
// password in it is readable by anyone who can read the repository. A
// deployment that leaves one of them alone is running on a secret that is not
// secret, and nothing used to say so.
//
// The published values are not written out again here. They are read from the
// example file itself, so a secret added there is covered as soon as its name
// is listed below.


// The variables that are secrets. Everything else in the example file is a
// setting: an address, a port, a limit, a list of referrers. Those are meant
// to be used as shipped and are not checked.
function env_secret_names()
{
    return array(
        'DB_PASS',
        'S3_SECRET',
        'S3_PASS',
        'PROCESS_KEY',
        'COMM_PASS',
        'EMAIL_PASS',
        'PR2_HUB_API_KEY',
        'IP_API_KEY_1',
        'IP_API_KEY_2',
        'KONG_API_PASS',
        'PAYPAL_CLIENT_ID',
        'PAYPAL_SECRET',
        'PAYPAL_DATA_KEY',
        'PAYPAL_DATA_IV',
        'URL_SALT',
        'URL_KEY',
        'URL_IV',
        'LEVEL_LIST_SALT',
        'LEVEL_SALT',
        'LEVEL_SALT_2',
        'LEVEL_PASS_SALT',
        'LEVEL_PASS_KEY',
        'LEVEL_PASS_IV',
        'LOGIN_KEY',
        'LOGIN_IV',
        'ACCOUNT_CHANGE_KEY',
        'ACCOUNT_CHANGE_IV',
    );
}


// What the example file sets.
//
// Included inside a function on purpose: its assignments land in this
// function's own scope, so reading them cannot disturb the live configuration.
function env_shipped_values()
{
    include __DIR__ . '/env.example.php';

    $values = get_defined_vars();
    unset($values['values']);

    return $values;
}


// The example file picks its payment values through its own sandbox switch,
// and the include above only ever evaluates one side of it. Both sides are
// published, so both count as unchanged.
function env_also_shipped()
{
    return array(
        'PAYPAL_CLIENT_ID' => array('sandbox client id', 'production client id'),
        'PAYPAL_SECRET' => array('sandbox secret', 'production secret'),
    );
}


// The secrets this deployment has not changed, by name.
function env_unchanged_secrets()
{
    $shipped = env_shipped_values();
    $also = env_also_shipped();
    $unchanged = array();

    foreach (env_secret_names() as $name) {
        // A secret that is missing entirely is not a secret that was changed.
        if (!array_key_exists($name, $GLOBALS)) {
            $unchanged[] = $name;
            continue;
        }

        $live = $GLOBALS[$name];

        if (array_key_exists($name, $shipped) && $live === $shipped[$name]) {
            $unchanged[] = $name;
            continue;
        }

        if (isset($also[$name]) && in_array($live, $also[$name], true)) {
            $unchanged[] = $name;
        }
    }

    return $unchanged;
}


// Stops here when any of them is still the published value.
function env_require_secrets_changed()
{
    // Without the published file there is nothing to compare against, and a
    // check that cannot run is not a check that passed.
    if (!is_readable(__DIR__ . '/env.example.php')) {
        error_log(
            'env: refusing to start. env.example.php is not readable, so no secret can be checked '
            . 'against the value published for it.'
        );
        throw new Exception('The server is not configured correctly. Please try again later.');
    }

    $unchanged = env_unchanged_secrets();

    if (!empty($unchanged)) {
        // Whoever asked is told nothing about the deployment; whoever runs it
        // gets the list.
        error_log(
            'env: refusing to start. These still hold the value published in env.example.php, so '
            . 'anyone who can read the repository holds them: ' . implode(', ', $unchanged)
        );
        throw new Exception('The server is not configured correctly. Please try again later.');
    }
}

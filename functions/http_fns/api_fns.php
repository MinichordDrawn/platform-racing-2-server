<?php

/**
 * Validates an API request against the origin IP and API key sent.
 *
 * @param string ip The user's IP.
 * @param string key The passed API key.
 * @param bool override_ip Boolean value to determine if the function should override the IP validation step.
 *
 * @throws Exception if the user is not determined to be privileged.
 * @return void
 */
function validate_api_request(string $ip, string $key, bool $override_ip = false)
{
    global $PR2_HUB_API_ALLOWED_IPS, $PR2_HUB_API_KEY;

    $ip_cond = $override_ip === false && (is_empty($ip) || !in_array($ip, $PR2_HUB_API_ALLOWED_IPS));

    // compared in constant time, so a wrong key gives nothing away by how long
    // it takes to turn it down
    $key_ok = hash_equals((string) $PR2_HUB_API_KEY, $key);

    if ($ip_cond || !$key_ok) {
        throw new Exception('Access denied.');
    }
}

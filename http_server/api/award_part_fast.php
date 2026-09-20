<?php

require_once GEN_HTTP_FNS;
require_once HTTP_FNS . '/api_fns.php';
require_once QUERIES_DIR . '/prize_actions.php';

header('Content-Type: application/json');

$key = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
$ip = '';

try {
    // the same limit and the same check the one-at-a-time endpoint uses
    $ip = get_ip();
    rate_limit('api-award-part-fast-' . $ip, 5, 1);
    validate_api_request($ip, $key, true);
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// usernames=oxen,a2,a3
$raw = $_POST['usernames'] ?? '';
$usernames = array_filter(array_map('trim', explode(',', $raw)));

$part_types_raw = $_POST['part_types'] ?? ($_POST['part_type'] ?? '');
$part_types = array_filter(array_map('trim', explode(',', $part_types_raw)));
$part_id   = isset($_POST['part_id']) ? (int)$_POST['part_id'] : 0;

if (empty($usernames) || empty($part_types) || !$part_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters.']);
    exit;
}

$results = [];
$pdo = pdo_connect();
foreach ($usernames as $username) {
    $results[$username] = [];
    foreach ($part_types as $part_type) {
        try {
            // Recorded before it is granted. prize_action_insert raises when
            // the row cannot be written, so an award that cannot be accounted
            // for does not happen rather than happening unseen.
            $user = user_select_by_name($pdo, $username);
            $msg = "Prize awarded via the bulk API to $user->name from $ip "
                . "{to_user_id: $user->user_id, "
                . "part_type: $part_type, "
                . "part_id: $part_id, "
                . 'is_epic: true}';
            prize_action_insert($pdo, (int) $user->user_id, $msg, 'api', false, $ip);

            $results[$username][$part_type] = award_epic_part_to_user($pdo, $username, $part_type, $part_id);
        } catch (Exception $e) {
            $results[$username][$part_type] = 'Error: ' . $e->getMessage();
        }
    }
}

echo json_encode(['results' => $results]);

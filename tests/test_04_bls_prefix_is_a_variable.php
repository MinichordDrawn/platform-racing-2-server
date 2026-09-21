<?php
// The staff-IP prefix is a configuration variable, $BLS_IP_PREFIX, set in
// common/env.php. http_server/mod/manage_ip_validity.php reads it as a bare
// constant instead, which PHP 8 raises as a fatal Error, so the 'clear' action
// cannot run. Every other reader in the tree uses the variable.
//
// This is a static check: it asks PHP's own tokenizer whether the name is ever
// used as a constant. Reaching that line at runtime needs the full moderator
// path, which is out of proportion to a missing sigil.

require_once __DIR__ . '/helper.php';

echo "BLS_IP_PREFIX is read as a configuration variable, never as a constant\n";

$files = [
    'http_server/mod/manage_ip_validity.php',
    'http_server/login.php',
    'http_server/admin/management/multi_logs.php',
    'http_server/admin/management/phpinfo.php',
    'http_server/admin/management/view_error_log.php',
    'functions/http_fns/ip_api_fns.php',
];

foreach ($files as $rel) {
    $tokens = token_get_all(file_get_contents(REPO . '/' . $rel));
    $as_constant = 0;

    foreach ($tokens as $token) {
        // A bare T_STRING of this name is a constant lookup. The variable form
        // arrives as T_VARIABLE '$BLS_IP_PREFIX' and never matches here.
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'BLS_IP_PREFIX') {
            $as_constant++;
        }
    }

    is_same($as_constant, 0, "$rel reads it as a variable");
}

t_done();

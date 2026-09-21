<?php
// Every staff page that changes something must prove the request came from a
// page of this site, not from somewhere else that made the browser send its
// cookie.
//
// A browser attaches the session cookie to any request to this site whatever
// caused the request. A page that decides who is asking from the cookie alone
// therefore acts for whoever caused the request, not for whoever is logged in.
// The defence is to require the session token in the request body as well:
// another site can cause the browser to send its cookies but cannot read them,
// so it cannot put the same value in the body.
//
// This test enumerates rather than listing. It finds every page that resolves
// a staff identity and then writes something, and requires each to check a
// posted token. Naming the pages individually is how a page gets missed, and
// pages were missed.

require_once __DIR__ . '/helper.php';

echo "every mutating staff page requires a posted token\n";

$root = REPO . '/http_server';
$pages = array();
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($dir as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $src = file_get_contents($file->getPathname());

    // Does it resolve a staff identity?
    if (!preg_match('/check_moderator\s*\(|is_staff\s*\(/', $src)) {
        continue;
    }
    // Does it write anything?
    if (!preg_match('/\w+_(update|insert|delete|set)\s*\(/', $src)) {
        continue;
    }

    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(REPO) + 1));
    $pages[$rel] = preg_match('/require_posted_token\s*\(|token_select\s*\(/', $src) === 1;
}

ok(count($pages) > 0, 'the search found staff pages that write, so it works');

$missing = array();
foreach ($pages as $rel => $checked) {
    if (!$checked) {
        $missing[] = $rel;
    }
}

is_same($missing, array(), 'every staff page that writes checks a posted token');

// The check has to be against the account the page already resolved, not just
// any valid token. Spot the shape on the pages that carry it.
foreach ($pages as $rel => $checked) {
    if (!$checked) {
        continue;
    }
    $src = file_get_contents(REPO . '/' . $rel);
    if (strpos($src, 'require_posted_token') !== false) {
        ok(
            preg_match('/require_posted_token\(\s*\$pdo\s*,\s*\$[a-z_]+(->user_id)?\s*,\s*\$token\s*\)/', $src) === 1,
            "$rel compares the token against the account acting"
        );
    }
}

// And a page must read a token before it can check one.
foreach ($pages as $rel => $checked) {
    if (!$checked) {
        continue;
    }
    $src = file_get_contents(REPO . '/' . $rel);
    ok(strpos($src, "default_post('token'") !== false, "$rel reads a posted token");
}

t_done();

<?php
// A page that demands a posted token must be able to supply one.
//
// Requiring the token is only half of the arrangement. The other half is that
// something puts it in the request. Where a page renders the very form that
// would carry it, demanding the token before that form is rendered makes the
// page unreachable: the form never appears, so nothing ever posts the field,
// so the page refuses every request including the one that would have shown
// the form. The action does not become safer, it becomes impossible.
//
// The rules below are what distinguishes the two shapes. An endpoint that only
// ever receives a post has no form to render and demands the token outright.
// A page that renders its own form demands it on the branch that writes, and
// puts the field in the form it renders.
//
// These also catch the plainer way of getting it wrong: demanding a value the
// page never read, and offering a write as a link when it is only accepted as
// a post.

require_once __DIR__ . '/helper.php';

echo "a page can supply what it demands\n";

$root = REPO . '/http_server';
$pages = array();
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($dir as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if (strpos($src, 'require_posted_token') === false) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(REPO) + 1));
    $pages[$rel] = $src;
}

ok(count($pages) > 0, 'the search found pages that demand a posted token');

// --- a page must read the value before it demands it ----------------------

foreach ($pages as $rel => $src) {
    $read_at = strpos($src, "default_post('token'");
    $demand_at = strpos($src, 'require_posted_token');
    ok(
        $read_at !== false && $read_at < $demand_at,
        "$rel reads the token before it demands it"
    );
}

// --- a page that renders its own form ------------------------------------

foreach ($pages as $rel => $src) {
    if (preg_match('/echo[^;]*<form/i', $src) !== 1) {
        continue; // an endpoint with no form of its own
    }

    // The form has to carry the field, or nothing ever posts it.
    ok(
        preg_match('/name=[\'"]token[\'"]/', $src) === 1,
        "$rel renders the token in the form it builds"
    );

    // And the demand has to sit on a branch rather than in front of the page,
    // or the form is never reached. Depth is how that shows in these files:
    // the body of the try is one level in, a branch of it is two.
    preg_match('/^([ ]*)require_posted_token/m', $src, $m);
    ok(
        isset($m[1]) && strlen($m[1]) > 4,
        "$rel demands the token on a branch, not in front of the form"
    );
}

// --- a write that is only accepted as a post is not offered as a link -----

foreach ($pages as $rel => $src) {
    ok(
        preg_match('/href=[\'"][^\'"]*action=/', $src) !== 1,
        "$rel does not offer a posted action as a link"
    );
}

// --- something that posts to such a page has to send the field ------------

$callers = array();
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($dir as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $src = file_get_contents($file->getPathname());
    if (!preg_match_all('/\$\.post\(\s*[\'"]([^\'"]+\.php)[\'"]/', $src, $m)) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(REPO) + 1));
    foreach ($m[1] as $target) {
        $resolved = str_replace('\\', '/', dirname($rel)) . '/' . $target;
        $resolved = preg_replace('#/\./#', '/', $resolved);
        if (isset($pages[$resolved])) {
            $callers[$rel][$resolved] = true;
        }
    }
}

ok(count($callers) > 0, 'the search found pages that post to a page demanding a token');

foreach ($callers as $rel => $targets) {
    $src = file_get_contents(REPO . '/' . $rel);
    foreach ($targets as $target => $_) {
        ok(
            preg_match('/->token\s*=/', $src) === 1,
            "$rel puts the token in what it posts to $target"
        );
    }
}

t_done();

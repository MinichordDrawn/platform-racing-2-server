<?php
// Nothing the application writes lives inside the tree the image ships.
//
// `/pr2/http_server` is two things at once today: the directory the image
// copies code into, and the directory the application writes its data into.
// Uploaded levels, recorded replays, generated level lists and the server
// status file all land there, so the contents of a running container's code
// tree change through ordinary play.
//
// That makes the strongest check available to an observer impossible to
// write. A hash of the code tree, re-taken on a cycle and compared against a
// manifest, answers "did something write into this running container" with no
// benign case -- but only if nothing benign writes there. Every generated file
// under the served tree is a benign write, and the check drowns.
//
// The origin is a name. WWW_ROOT means "where the web root is", and the multi
// container has no web root at all -- its image never copies http_server in --
// yet it uses WWW_ROOT to find levels and to write replays. A constant that
// says "served" was used to mean "data", and the data followed the name.
//
// So: data goes under DATA_DIR, which is outside the served tree. The served
// tree keeps its URLs by holding symlinks, the way emblems already worked.
// Serving is unchanged; what changes is that nothing writes through a path
// that starts at the code.
//
// This test enumerates rather than lists. It finds every path the source
// builds from WWW_ROOT, or from __DIR__ inside the served tree, and requires
// that none of them names a region the application writes. A new data path
// added through WWW_ROOT tomorrow fails it without anyone editing this file.

require_once __DIR__ . '/helper.php';

echo "written data is not in the served tree\n";

// The regions the application writes. A path under the served tree beginning
// with one of these is data wearing the code tree's address.
$data_regions = array('levels', 'replays', 'files', 'emblems');

// --- the data root exists and is not inside the served one ----------------

$config = file_get_contents(REPO . '/config.php');

ok(strpos($config, "define('DATA_DIR'") !== false, 'a data root is defined');

// It has to be a sibling of the served tree, not a directory inside it.
// Defining it as WWW_ROOT . '/something' would satisfy the name and change
// nothing.
ok(
    preg_match("/define\('DATA_DIR',\s*\\\$directory\s*\.\s*'\/[a-z]+'\)/", $config) === 1,
    'and sits beside the served tree rather than inside it'
);
ok(
    strpos($config, "define('DATA_DIR', WWW_ROOT") === false,
    'and is not built from the served tree'
);

// --- no source builds a data path out of the served tree ------------------

function scan_php($dir, &$out)
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            // Third-party and client sources are not ours to hold to this.
            if (in_array($entry, array('vend', 'clientsrc', 'pr2hub_proxy', 'liquibase', '.git'), true)) {
                continue;
            }
            scan_php($path, $out);
        } elseif (substr($entry, -4) === '.php') {
            $out[] = $path;
        }
    }
}

$files = array();
scan_php(REPO, $files);

ok(count($files) > 100, 'the scan reaches the source');

$served_paths = array();  // [file, constant, suffix]
foreach ($files as $file) {
    $src = file_get_contents($file);
    $rel = substr($file, strlen(REPO) + 1);
    $in_served_tree = strpos($rel, 'http_server/') === 0;

    if (preg_match_all('/(WWW_ROOT|__DIR__)\s*\.\s*([\'"])([^\'"]*)\2/', $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            // __DIR__ only resolves into the served tree for files that are
            // themselves in it.
            if ($hit[1] === '__DIR__' && !$in_served_tree) {
                continue;
            }
            $served_paths[] = array($rel, $hit[1], $hit[3]);
        }
    }
}

ok(count($served_paths) > 5, 'the scan finds paths built from the served tree');

$offenders = array();
foreach ($served_paths as $hit) {
    list($rel, $constant, $suffix) = $hit;
    $first = strtok(ltrim($suffix, '/'), '/');
    if (in_array($first, $data_regions, true)) {
        $offenders[] = "$rel: $constant . '$suffix'";
    }
}

is_same($offenders, array(), 'no source builds a written-data path from the served tree');

// --- and nothing writes through the served tree at all --------------------

$writers = array(
    'file_put_contents', 'fopen', 'mkdir', 'unlink', 'rename', 'touch',
    'move_uploaded_file', 'copy', 'rmdir', 'file_put_contents'
);

$write_offenders = array();
foreach ($files as $file) {
    $src = file_get_contents($file);
    $rel = substr($file, strlen(REPO) + 1);
    $in_served_tree = strpos($rel, 'http_server/') === 0;

    foreach ($writers as $fn) {
        $pattern = '/\b' . preg_quote($fn, '/') . '\s*\(([^;]{0,200})/';
        if (!preg_match_all($pattern, $src, $m, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($m as $hit) {
            $args = $hit[1];
            if (strpos($args, 'WWW_ROOT') !== false) {
                $write_offenders[] = "$rel: $fn( ... WWW_ROOT ... )";
            } elseif ($in_served_tree && strpos($args, '__DIR__') !== false) {
                $write_offenders[] = "$rel: $fn( ... __DIR__ ... )";
            }
        }
    }
}

is_same($write_offenders, array(), 'nothing writes through a path rooted at the served tree');

// --- the served tree holds no generated file in the repository ------------

// A generated file that is also committed is the blurred boundary in one
// object: the image ships it and cron rewrites it, so a manifest sees it
// change every minute.
ok(
    !is_dir(REPO . '/http_server/files'),
    'the generated directory is not part of the source tree'
);

// Shipped client binaries belong with the other shipped client binaries, in
// the directory that is already mounted read-only.
$stray_swf = array();
foreach (glob(REPO . '/http_server/**/*.swf') as $swf) {
    $stray_swf[] = substr($swf, strlen(REPO) + 1);
}
foreach (glob(REPO . '/http_server/*.swf') as $swf) {
    $stray_swf[] = substr($swf, strlen(REPO) + 1);
}
is_same($stray_swf, array(), 'no shipped binary sits in the served tree');

// --- the containers mount data outside the served tree --------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

$served_mounts = array();
foreach (explode("\n", $compose) as $line) {
    if (preg_match('/:(\/pr2\/http_server[^\s:]*)(:ro)?\s*$/', trim($line), $m)) {
        $served_mounts[] = $m[1];
    }
}

// The clients directory is shipped content served read-only. It is the one
// thing that legitimately appears under the served tree from outside, and it
// is never written.
$unexpected = array_values(array_diff($served_mounts, array('/pr2/http_server/clients')));
is_same($unexpected, array(), 'no writable volume is mounted inside the served tree');

// --- serving is unchanged, by symlink -------------------------------------

// The links are made when the image is built, not at startup. That matters
// beyond tidiness: a served tree assembled at runtime is a served tree that
// changes while the container runs, which is the property this whole move
// exists to remove. A symlink is the one thing that can sit in it without
// making it vary, because its content is the target path and that is fixed.
$web_image = file_get_contents(REPO . '/docker/http_server.dockerfile');

ok(
    preg_match('/ln -s\s+"?\/pr2\/data\/\S*"?\s+"?\/pr2\/http_server\/\S*"?/', $web_image) === 1,
    'the served tree reaches the data directory by symlink'
);

foreach ($data_regions as $region) {
    ok(
        preg_match('/\b' . preg_quote($region, '/') . '\b/', $web_image) === 1,
        "$region is one of the directories linked in, so its URLs are unchanged"
    );
}

// Nothing in the startup path may write into the served tree, or the tree is
// assembled at runtime after all.
$startup = file_get_contents(REPO . '/docker/http_server_startup.sh');

$startup_code = '';
foreach (explode("\n", $startup) as $line) {
    $line = trim(rtrim($line, "\r"));
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $startup_code .= $line . "\n";
}

ok(
    strpos($startup_code, 'ln -s') === false && strpos($startup_code, '/pr2/http_server') === false,
    'and the startup script does not touch the served tree'
);

t_done();

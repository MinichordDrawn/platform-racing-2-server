<?php
// Every image this repository builds from has to be one that still exists.
//
// An image that cannot be pulled is a deployment that cannot be reproduced.
// It is not a slow decay either: the migration runner built `FROM openjdk:12`,
// and the entire `openjdk` repository has been withdrawn from Docker Hub --
// not deprecated with the tags left in place, removed. So the container that
// creates the database schema could not be built at all, which means this
// fork could not be deployed from scratch by anyone, including us.
//
// Nothing caught it because nothing had been built. A test cannot ask Docker
// Hub what exists without a network, so this asserts the two things it can
// decide from the files: that no base comes from a repository known to be
// withdrawn, and that every base names an explicit tag. An untagged base is
// `latest`, which is a different image on different days and reproduces
// nothing.

require_once __DIR__ . '/helper.php';

echo "every base image can still be pulled\n";

// Repositories that have been withdrawn, with what replaced them. A base
// image from one of these cannot be pulled at all.
$withdrawn = array(
    'openjdk' => 'withdrawn from Docker Hub; use eclipse-temurin',
);

$dockerfiles = glob(REPO . '/docker/*.dockerfile');
ok(count($dockerfiles) >= 4, 'the dockerfiles are found');

$froms = array();
foreach ($dockerfiles as $path) {
    $rel = substr($path, strlen(REPO) + 1);
    foreach (explode("\n", file_get_contents($path)) as $n => $raw) {
        $line = trim(rtrim($raw, "\r"));
        if (!preg_match('/^FROM\s+(\S+)/i', $line, $m)) {
            continue;
        }
        $froms[] = array('file' => $rel, 'line' => $n + 1, 'image' => $m[1]);
    }
}

ok(count($froms) >= 5, 'every FROM is enumerated');

// --- none from a withdrawn repository -------------------------------------

$dead = array();
foreach ($froms as $f) {
    $repo_name = strpos($f['image'], ':') === false
        ? $f['image']
        : substr($f['image'], 0, strpos($f['image'], ':'));

    if (isset($withdrawn[$repo_name])) {
        $dead[] = $f['file'] . ':' . $f['line'] . ' uses ' . $f['image']
            . ' (' . $withdrawn[$repo_name] . ')';
    }
}
is_same($dead, array(), 'no base image comes from a withdrawn repository');

// --- every base names a tag -----------------------------------------------

$untagged = array();
foreach ($froms as $f) {
    $image = $f['image'];

    // A build stage reference (FROM x AS y is handled by taking $1 already);
    // a stage used as a base is not an image to pull.
    $is_stage = in_array($image, array('build', 'builder'), true);
    if ($is_stage) {
        continue;
    }

    $tag_at = strrpos($image, ':');
    $tag = $tag_at === false ? '' : substr($image, $tag_at + 1);

    if ($tag === '' || $tag === 'latest') {
        $untagged[] = $f['file'] . ':' . $f['line'] . ' uses ' . $image;
    }
}
is_same($untagged, array(), 'every base image names an explicit tag');

// --- the migration runner builds on something maintained ------------------

$liquibase = file_get_contents(REPO . '/docker/liquibase.dockerfile');
ok(
    preg_match('/^FROM\s+eclipse-temurin:/mi', $liquibase) === 1,
    'the migration runner builds on a maintained JDK'
);

t_done();

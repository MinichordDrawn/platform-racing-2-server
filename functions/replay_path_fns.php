<?php

// Where a replay file is, in the two forms that matter.
//
// The database stores a path relative to the replay root. The root itself is
// named here and nowhere else, so moving it -- as moving written data out of
// the served tree did -- does not orphan rows that were written before.
//
// This file deliberately requires nothing. It is loaded by the recorder in
// the game container and by the download endpoint in the web container, and
// both reach it without pulling in anything else.


// The directory every replay file lives under.
function replay_root()
{
    return DATA_DIR . '/replays';
}


// The form that goes into the database: relative to the root it was written
// under. A path that is not under that root is refused rather than stored,
// because a stored path naming somewhere else is a row no reader can trust.
function replay_path_store($absolute_path, $base = null)
{
    $base = $base === null ? replay_root() : $base;
    $base = rtrim(str_replace('\\', '/', (string) $base), '/');
    $path = str_replace('\\', '/', (string) $absolute_path);

    if ($base === '' || strpos($path, $base . '/') !== 0) {
        throw new Exception('Replay path is outside the replay root.');
    }

    return substr($path, strlen($base) + 1);
}


// The form a reader opens. A stored value names a replay inside the root and
// nothing else: it decides which replay, never which file on disk.
function replay_path_resolve($stored)
{
    $stored = str_replace('\\', '/', (string) $stored);

    // Rows written before the data moved out of the served tree hold an
    // absolute path under the old root. The bytes never moved -- the mount
    // point did -- so the tail still names the file.
    $legacy_roots = array('/pr2/http_server/replays/', '/pr2/data/replays/');
    foreach ($legacy_roots as $legacy) {
        if (strpos($stored, $legacy) === 0) {
            $stored = substr($stored, strlen($legacy));
            break;
        }
    }

    if ($stored === '' || $stored[0] === '/' || strpos($stored, ':') !== false) {
        throw new Exception('Replay file is unavailable.');
    }

    // Every segment has to be an ordinary name. Refusing '..' here is what
    // keeps the root a boundary rather than a starting point.
    foreach (explode('/', $stored) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new Exception('Replay file is unavailable.');
        }
    }

    return replay_root() . '/' . $stored;
}

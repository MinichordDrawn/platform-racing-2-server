<?php
// The migrations use syntax the database in the compose file accepts.
//
// add-replays.yaml wrote `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, which is
// MariaDB. MySQL has no such form and rejects the statement outright, so the
// migration aborted partway through: fifty-one tables were created, the
// replays columns were not, and seed-servers -- which comes after it in the
// changelog -- never ran at all. A fresh database therefore had no row in
// `servers`, and the game server crash-looped on "Could not find a server with
// that ID."
//
// Nothing caught it because nothing had ever run it. It is the same shape as
// the withdrawn base image: a defect that only exists when the thing is
// actually built, invisible to every test that reads source.
//
// `IF NOT EXISTS` was belt-and-braces in any case. Liquibase records every
// changeset it has run in DATABASECHANGELOG and does not run one twice, which
// is the whole point of a changeset id. What the clause added was tolerance
// for a column somebody added by hand outside Liquibase -- and quietly
// tolerating that is worse than stopping, because it means the schema is not
// what the changelog believes and nobody is told.

require_once __DIR__ . '/helper.php';

echo "the migrations run on the database we ship\n";

// --- what database are we actually targeting? -----------------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');
ok(
    preg_match('/^\s+image:\s*mysql\s*$/m', $compose) === 1,
    'the compose file runs MySQL'
);

// --- forms MySQL does not accept ------------------------------------------

// Each is valid MariaDB and a syntax error on MySQL. CREATE TABLE IF NOT
// EXISTS and DROP TABLE IF EXISTS are valid on both and are not listed.
$mariadb_only = array(
    '/ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS/i'   => 'ADD COLUMN IF NOT EXISTS',
    '/DROP\s+COLUMN\s+IF\s+EXISTS/i'        => 'DROP COLUMN IF EXISTS',
    '/ADD\s+INDEX\s+IF\s+NOT\s+EXISTS/i'    => 'ADD INDEX IF NOT EXISTS',
    '/DROP\s+INDEX\s+IF\s+EXISTS/i'         => 'DROP INDEX IF EXISTS',
    '/ADD\s+KEY\s+IF\s+NOT\s+EXISTS/i'      => 'ADD KEY IF NOT EXISTS',
    '/ADD\s+CONSTRAINT\s+IF\s+NOT\s+EXISTS/i' => 'ADD CONSTRAINT IF NOT EXISTS',
    '/CHANGE\s+COLUMN\s+IF\s+EXISTS/i'      => 'CHANGE COLUMN IF EXISTS',
    '/MODIFY\s+COLUMN\s+IF\s+EXISTS/i'      => 'MODIFY COLUMN IF EXISTS',
);

$files = array_merge(
    glob(REPO . '/liquibase/*.yaml'),
    glob(REPO . '/liquibase/*.sql'),
    glob(REPO . '/liquibase/*.xml')
);

ok(count($files) >= 4, 'the changelogs are found (' . count($files) . ' files)');

$offences = array();
foreach ($files as $path) {
    $rel = substr($path, strlen(REPO) + 1);
    $src = file_get_contents($path);
    foreach ($mariadb_only as $pattern => $label) {
        if (preg_match_all($pattern, $src, $m)) {
            $offences[] = $rel . ': ' . $label . ' x' . count($m[0]);
        }
    }
}

is_same($offences, array(), 'no changelog uses syntax MySQL rejects');

// --- the changelog still includes everything ------------------------------
//
// The failure was not only the statement. seed-servers.yaml comes after
// add-replays.yaml, so one aborting statement stopped the seed from running
// and left the deployment unable to start a game server. Order matters here.

$changelog = file_get_contents(REPO . '/liquibase/changelog.yaml');
foreach (array('base.mysql.sql', 'add-replays.yaml', 'add-indexes.yaml', 'seed-servers.yaml') as $part) {
    ok(strpos($changelog, $part) !== false, "the changelog includes $part");
}

// A seed that runs last is a seed that never runs if anything before it
// aborts. That is a property of the file, and it is worth knowing.
$seed_at = strpos($changelog, 'seed-servers.yaml');
$replays_at = strpos($changelog, 'add-replays.yaml');
ok(
    $seed_at !== false && $replays_at !== false && $seed_at > $replays_at,
    'the seed runs after the schema it depends on'
);

t_done();

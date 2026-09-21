<?php
// The one row the daily job's effect check needs, for a deployment that is not
// the live game.
//
// `guilds_reset_gp_today` and `gp_reset` are the two scheduled tasks that carry
// no WHERE clause, so the number of rows they change is the number of rows the
// table holds. That is why they are the only two with a declared minimum in
// SCHEDULE_MIN_COUNTS: a zero from them means the table is empty, and in the
// live game an empty guilds table is a fault worth stopping for.
//
// A freshly migrated deployment has an empty guilds table because it is new,
// not because anything is wrong. Its first daily run therefore reaches zero
// rows, fails `trace-effect` twice, and halts the ring -- correctly by the
// letter and uselessly in fact. schedules.php says so in the comment above the
// list, and this is the other half of that sentence: rather than removing the
// entries and weakening the check that the live game needs, a verification
// deployment is given the one guild that makes the invariant true of it.
//
// One guild and one gp row is all it takes. The tasks count rows; they do not
// read them.
//
// **Seed before the first daily run, because this cannot undo one.** The effect
// check reads the most recent finished run that was not refused, and a refused
// run is skipped -- so once a bad run is on record, the job that would write a
// better one is refused by the halt that the bad run caused, and no amount of
// seeding changes the record. Recovering a deployment already in that state
// means deleting the trace by hand:
//
//   docker compose ... exec -T --user 33:33 web \
//     rm -f /pr2/shared/traces/daily/0000000001.trace
//
// Having no history at all is safe, because the check is skipped when there is
// no finished unrefused run to read. Having one bad run is permanent.
//
// This is a fixture for a deployment that serves nobody. It is not applied by
// docker-compose.yml, it is not a migration, and nothing that ships to a real
// deployment references it. The seeded guild belongs to no real account.
//
// It runs with the prepend disabled, the way common/cron/run.php does, because
// config.php refuses while the ring says stop and this is one of the things
// that is run in order to clear a halt.
//
// `docker/` is not copied into any image, deliberately, so this is piped in
// rather than executed in place. The tree it talks to is at /pr2, which is the
// image's own layout and the same constant container.php is built on.
//
// Run from the docker/ directory, against a running deployment:
//   docker compose -f docker-compose.yml -f docker-compose.verify.yml \
//     exec -T --user 33:33 web sh -c 'cat > /tmp/s.php \
//       && php -d auto_prepend_file= /tmp/s.php; rc=$?; rm -f /tmp/s.php; exit $rc' \
//     < verify-seed.php

define('PR2_TREE', '/pr2');

require_once PR2_TREE . '/common/env.php';
require_once PR2_TREE . '/common/pdo_connect.php';

$pdo = pdo_connect();

// Idempotent on both tables, so running it twice is not two guilds.
// guild_name is UNIQUE, which is what makes INSERT IGNORE exact here.
$guilds = $pdo->exec(
    "INSERT IGNORE INTO guilds
        (guild_id, guild_name, creation_date, active_date, member_count,
         emblem, gp_total, gp_today, owner_id, note)
     VALUES
        (1, 'Verification', NOW(), NOW(), 1, '', 0, 0, 1, '')"
);

// gp carries no unique key, so the guard is the select rather than the index.
$gp = $pdo->exec(
    "INSERT INTO gp (user_id, guild_id, gp_today, gp_total)
     SELECT 1, 1, 0, 0 FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM gp WHERE user_id = 1 AND guild_id = 1)"
);

$have_guilds = (int) $pdo->query('SELECT COUNT(*) FROM guilds')->fetchColumn();
$have_gp     = (int) $pdo->query('SELECT COUNT(*) FROM gp')->fetchColumn();

printf("guilds: %d inserted, %d present\n", $guilds, $have_guilds);
printf("gp:     %d inserted, %d present\n", $gp, $have_gp);

// The point of the fixture is that the next daily run reports a non-zero count
// for both tasks. Saying so here means a run that seeded nothing is visible
// rather than silently fine.
if ($have_guilds < 1 || $have_gp < 1) {
    fwrite(STDERR, "seed did not produce a row in both tables\n");
    exit(1);
}

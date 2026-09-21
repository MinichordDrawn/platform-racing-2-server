<?php
// The weekly optimize quotes the table names it was given.
//
// `all_optimize` read every table name out of SHOW TABLES and joined them raw
// into one statement. This schema has a table called `keys`, which is a MySQL
// reserved word, so the statement was a syntax error:
//
//   SQLSTATE[42000] ... near 'keys, level_backups, level_prizes, levels, ...'
//
// `weekly.php` has no try around its tasks, so the raise ended the run. The
// tasks after it never ran, and no finish line was written. That has been true
// on every deployment since the table was added, and nothing said so: the only
// record was a stack trace in a log no code reads.
//
// The observer network found it on the first weekly run it watched. The trace
// showed `all_optimize` with completed false and no finish line, and two hours
// later the deadline expired and `trace-complete` halted the deployment. That
// is the check working; the bug underneath it is this one.
//
// The statement is built by a function of its own so the decision can be asked
// here rather than only against a database, because what was wrong was the
// string and not the connection.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/queries/all_optimize.php';

echo "the weekly optimize quotes its table names\n";

// --- the reserved word that broke it --------------------------------------

$sql = all_optimize_statement(array('guilds', 'keys', 'levels'));

ok(strpos($sql, '`keys`') !== false, 'a reserved word is quoted');
ok(strpos($sql, ', keys,') === false, 'and is not left bare');
is_same($sql, 'OPTIMIZE TABLE `guilds`, `keys`, `levels`',
    'every name is quoted, in the order given');

// --- the shape of the statement -------------------------------------------

ok(strpos($sql, 'OPTIMIZE TABLE ') === 0, 'it is one OPTIMIZE TABLE statement');
is_same(substr_count($sql, 'OPTIMIZE'), 1, 'and only one');

$one = all_optimize_statement(array('users'));
is_same($one, 'OPTIMIZE TABLE `users`', 'a single table needs no separator');

// --- nothing to do is not a broken statement ------------------------------
//
// The old walk counted from 0 to count-1, so an empty database asked for row
// -1 and built OPTIMIZE TABLE with nothing after it. Saying so with null lets
// the caller skip the query rather than send a statement that cannot parse.

is_same(all_optimize_statement(array()), null, 'no tables produces no statement');

// --- an identifier carrying a backtick ------------------------------------
//
// Nothing a requester controls reaches this string today, because SHOW TABLES
// is its only source. It is escaped anyway: quoting without escaping is what
// makes the next caller's input exploitable.

$odd = all_optimize_statement(array('we`ird'));
is_same($odd, 'OPTIMIZE TABLE `we``ird`', 'a backtick in a name is doubled, not left to close the quote');

// --- and the query uses it ------------------------------------------------

$src = file_get_contents(REPO . '/common/queries/all_optimize.php');

ok(strpos($src, 'all_optimize_statement(') !== false, 'all_optimize builds its statement with it');
ok(preg_match('/join\s*\(\s*",\s*"\s*,\s*\$table_names\s*\)/', $src) !== 1,
    'the raw join that caused the syntax error is gone');
ok(preg_match('/range\s*\(\s*0\s*,\s*\$end\s*\)/', $src) !== 1,
    'and so is the walk that indexed row -1 on an empty database');
ok(strpos($src, 'return count($table_names);') !== false,
    'the task reports how many tables it optimized, so the trace records it');

t_done();

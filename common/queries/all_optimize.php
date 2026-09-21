<?php

// TO-DO: is this needed?


// The OPTIMIZE statement for a set of table names, or null when there are none.
//
// Separated from the query so it can be asked here rather than only against a
// database, because what went wrong was the string and not the connection.
//
// Every identifier is quoted. The statement used to join the names raw, and
// this schema has a table called `keys`, which is a MySQL reserved word. The
// result was a syntax error on every run, on every deployment, since that
// table was added: the weekly job died part way through and the tasks after it
// never ran. weekly.php has no try around its tasks, so the run simply ended,
// and before the observer network the only record was a stack trace in a log
// nothing reads.
//
// A backtick inside an identifier is escaped by doubling it. `SHOW TABLES` is
// the only source that reaches here, so nothing a requester controls arrives
// in this string today. It is escaped anyway, because quoting an identifier
// without escaping it is the habit that makes the next one exploitable.
function all_optimize_statement(array $table_names)
{
    $quoted = array();
    foreach ($table_names as $name) {
        $quoted[] = '`' . str_replace('`', '``', (string) $name) . '`';
    }

    if (count($quoted) === 0) {
        return null;
    }

    return 'OPTIMIZE TABLE ' . join(', ', $quoted);
}


// Returns how many tables were optimized, which the weekly trace records.
function all_optimize($pdo, $DB_NAME)
{
    $stmt = $pdo->query('SHOW TABLES');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // `SHOW TABLES` names its one column after the database. Reading the rows
    // themselves rather than counting them and indexing by number also removes
    // the old `range(0, count - 1)` walk, which on an empty database counted
    // down to -1 and asked for a row that was not there.
    $column = 'Tables_in_' . $DB_NAME;
    $table_names = array();
    foreach ($rows as $row) {
        if (isset($row[$column])) {
            $table_names[] = $row[$column];
        }
    }

    // Tables exist but none of them could be named: the column is not what
    // this expects, and optimizing nothing while reporting success is the
    // quiet failure this whole job just spent a week demonstrating.
    if (count($rows) > 0 && count($table_names) === 0) {
        throw new Exception("SHOW TABLES returned rows with no $column column.");
    }

    $sql = all_optimize_statement($table_names);
    if ($sql === null) {
        return 0;
    }

    $pdo->exec($sql);

    return count($table_names);
}

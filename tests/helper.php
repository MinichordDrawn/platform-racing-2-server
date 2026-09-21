<?php
// Minimal test helper: assertions and an in-memory database, no framework.

// The package this suite belongs to. The tests live inside it now, so this
// is its root and nothing here reaches outside it.
define('REPO', dirname(__DIR__));

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = 0;

function ok($cond, $label)
{
    if ($cond) {
        $GLOBALS['t_pass']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['t_fail']++;
        echo "  FAIL  $label\n";
    }
}

function is_same($actual, $expected, $label)
{
    $cond = $actual === $expected;
    if (!$cond) {
        $label .= sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
    }
    ok($cond, $label);
}

function t_done()
{
    $p = $GLOBALS['t_pass'];
    $f = $GLOBALS['t_fail'];
    echo "\n  $p passed, $f failed\n";
    exit($f > 0 ? 1 : 0);
}

// An in-memory stand-in for the users/tokens tables, with only the columns under test.
function test_pdo()
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (
        user_id INTEGER PRIMARY KEY,
        name TEXT,
        pass_hash TEXT,
        temp_pass_hash TEXT
    )');
    $pdo->exec('CREATE TABLE tokens (
        user_id INTEGER,
        token TEXT,
        time TEXT
    )');
    return $pdo;
}

function user_row($pdo, $user_id)
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = :id');
    $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_OBJ);
}

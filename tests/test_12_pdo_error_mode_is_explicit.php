<?php
// The database error mode must be set explicitly on both paths.
//
// pdo_connect() only called setAttribute() inside the debug branch. With debug
// off it fell through to whatever PHP defaults to, which is EXCEPTION on PHP 8
// but SILENT on PHP 7, so the behaviour of every failing query depended on the
// interpreter rather than on this file.
//
// Static check: the connection cannot be made here without a database.

require_once __DIR__ . '/helper.php';

echo "pdo_connect: the error mode is chosen here, not by the PHP version\n";

$src = file_get_contents(REPO . '/common/pdo_connect.php');
$fn = substr($src, strpos($src, 'function pdo_connect'));
$fn = substr($fn, 0, strpos($fn, "\n}") + 2);

ok(strpos($fn, 'ERRMODE_WARNING') !== false, 'the debug mode is named explicitly');
ok(strpos($fn, 'ERRMODE_EXCEPTION') !== false, 'the production mode is named explicitly');

t_done();

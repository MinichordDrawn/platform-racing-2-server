<?php
// The level a race is for has to be named by something that can be a level.
//
// client_fill_slot split its packet into a course id, a slot and a page. The
// slot was bounded to 0..7 and the page was bounded where it is used. The
// course id was not checked at all: no numeric test, no length limit, no
// character restriction.
//
// It is not merely stored. It is put into the name of the startGame packet
// that every player in the race receives and the replay records, it keys the
// play totals that are sent up to the hub, and it looks up the campaign and
// prize tables. A value carrying the field delimiter therefore chooses the
// shape of a packet other people's clients parse.
//
// A course id is a level id. That is what it means everywhere it is used, and
// it is what the replay metadata casts it to. Nothing else is a course id, so
// nothing else is accepted. Whether it names a level that exists is a
// different question and not one the server can answer, since players race
// levels the server holds no catalogue of.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a course id has to be a level id\n";

ok(valid_course_id('1'), 'a level id is accepted');
ok(valid_course_id('4815162342'), 'a large level id is accepted');
ok(valid_course_id(1234), 'an integer is accepted');

is_same(valid_course_id('0'), false, 'zero is refused');
is_same(valid_course_id('-1'), false, 'a negative is refused');
is_same(valid_course_id(''), false, 'an empty value is refused');
is_same(valid_course_id('   '), false, 'a blank value is refused');
is_same(valid_course_id('12.5'), false, 'a decimal is refused');
is_same(valid_course_id('1e5'), false, 'exponent notation is refused');
is_same(valid_course_id(' 12'), false, 'a value with a space is refused');
is_same(valid_course_id('12abc'), false, 'a value with letters is refused');
is_same(valid_course_id('abc'), false, 'a word is refused');
is_same(valid_course_id(null), false, 'nothing is refused');

// The field delimiter is the point. A value carrying it would let the sender
// choose the structure of the packet other players receive.
is_same(valid_course_id('12`startGame'), false, 'a value carrying the field delimiter is refused');
is_same(valid_course_id("12\n34"), false, 'a value carrying a line break is refused');

// --- the handler has to apply it ----------------------------------------

$lobby = file_get_contents(REPO . '/functions/multi_fns/client/lobby.php');

ok(strpos($lobby, 'valid_course_id') !== false, 'the handler checks the course id');

// A short packet must be refused rather than filled with nulls by list().
ok(
    preg_match('/list\(\s*\$course_id\s*,\s*\$slot\s*,\s*\$page\s*\)\s*=\s*explode/', $lobby) !== 1,
    'the packet is no longer split straight into three names without counting them'
);
ok(strpos($lobby, 'count(') !== false, 'the handler counts the fields it was given');

// The check has to happen before the room is asked to do anything.
$check_at = strpos($lobby, 'valid_course_id');
$fill_at = strpos($lobby, 'right_room->fillSlot');
ok($check_at !== false && $fill_at !== false && $check_at < $fill_at, 'the check comes before the slot is filled');

t_done();

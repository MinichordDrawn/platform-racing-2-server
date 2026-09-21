<?php
// A gate must refuse when the thing it measures was never started.
//
// Force start clears the slot of every occupant of a course box who has not
// confirmed, and writes them a packet closing the menu. It is gated on fifteen
// seconds having passed since a countdown began.
//
// That countdown is never begun. The property is declared, the only line that
// would set it is commented out, and the only other thing done to it is
// setting it back to nothing. Subtracting an unset value from the clock gives
// the clock, which is always more than fifteen, so the gate passed on every
// call. Any occupant could clear every other unconfirmed occupant's slot, as
// often as they liked.

require_once __DIR__ . '/helper.php';

echo "a countdown that never started forces nothing\n";

// The arithmetic behind it, shown rather than described.
$never_started = null;
ok(
    (time() - $never_started) > 15,
    'subtracting a countdown that was never started from the clock always clears fifteen'
);

$started_now = time();
is_same((time() - $started_now) > 15, false, 'a countdown that did start has not elapsed yet');

$started_long_ago = time() - 60;
ok((time() - $started_long_ago) > 15, 'a countdown that started long enough ago has elapsed');

// --- the course box has to refuse when there is no countdown ------------

$src = file_get_contents(REPO . '/multiplayer_server/CourseBox.php');

$at = strpos($src, 'public function forceStart');
ok($at !== false, 'the force start is present');
$body = substr($at !== false ? substr($src, $at) : '', 0, 1200);

ok(strpos($body, 'isset($this->force_time)') !== false, 'it tests whether a countdown was started at all');

$guard_at = strpos($body, 'isset($this->force_time)');
$elapsed_at = strpos($body, '> 15');
$clear_at = strpos($body, 'clearSlot');

ok($guard_at !== false && $elapsed_at !== false, 'both the existence test and the elapsed test are present');
ok($guard_at < $elapsed_at, 'existence is settled before anything is subtracted');
ok($clear_at !== false && $guard_at < $clear_at, 'nothing is cleared before either test');

// The elapsed test itself must survive: this is not about removing the gate.
ok(strpos($body, 'force_time') !== false, 'the countdown is still what the gate measures');
ok(strpos($body, "closeCourseMenu") !== false, 'what it gates is unchanged');

// And the countdown must still be startable, so the line that would start it
// is not quietly removed while this is fixed.
ok(strpos($src, 'force_time') !== false, 'the countdown property is still there to be started');

t_done();

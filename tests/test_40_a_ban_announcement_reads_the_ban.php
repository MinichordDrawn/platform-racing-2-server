<?php
// The socket side of a ban must read the ban, not the packet describing it.
//
// The ban itself is written by the HTTP endpoint, which caps the duration by
// the acting mod's rank, settles the scope to g or s, and stores the reason.
// The socket handler then fetched that exact row to confirm it matches the
// target, and went on to use the client's own copies of the duration, the
// scope and the reason instead of the ones it had just read.
//
// The duration decides how long everyone sharing the target's address is
// socially banned for. The scope decides whether they are socially banned or
// disconnected outright. Both came from the packet.
//
// The row was also not required to be live: ban_select filters on neither
// lifted nor expire_time, so a ban that had been lifted or had run out was as
// good as a current one for announcing and applying.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a ban announcement reads the ban\n";

function ban_row($fields = array())
{
    $row = new stdClass();
    $row->banned_ip = '203.0.113.9';
    $row->banned_user_id = 42;
    $row->expire_time = time() + 3600;
    $row->scope = 'g';
    $row->reason = 'being unkind';
    $row->lifted = 0;

    foreach ($fields as $name => $value) {
        $row->$name = $value;
    }

    return $row;
}

// --- is the ban live at all ---------------------------------------------

ok(ban_row_is_active(ban_row()), 'a current ban is live');
is_same(ban_row_is_active(ban_row(array('expire_time' => time() - 1))), false, 'an expired ban is not live');
is_same(ban_row_is_active(ban_row(array('expire_time' => time()))), false, 'a ban expiring this second is not live');

// lifted arrives from a BIT column, which can reach PHP as a raw byte. A cast
// to int on "\x01" is zero, so every spelling is tested rather than assumed.
is_same(ban_row_is_active(ban_row(array('lifted' => 1))), false, 'a lifted ban, as an integer, is not live');
is_same(ban_row_is_active(ban_row(array('lifted' => '1'))), false, 'a lifted ban, as a digit, is not live');
is_same(ban_row_is_active(ban_row(array('lifted' => "\x01"))), false, 'a lifted ban, as a raw byte, is not live');
is_same(ban_row_is_active(ban_row(array('lifted' => true))), false, 'a lifted ban, as a boolean, is not live');

ok(ban_row_is_active(ban_row(array('lifted' => 0))), 'an unlifted ban, as an integer, is live');
ok(ban_row_is_active(ban_row(array('lifted' => '0'))), 'an unlifted ban, as a digit, is live');
ok(ban_row_is_active(ban_row(array('lifted' => "\x00"))), 'an unlifted ban, as a raw byte, is live');
ok(ban_row_is_active(ban_row(array('lifted' => false))), 'an unlifted ban, as a boolean, is live');

is_same(ban_row_is_active(null), false, 'nothing is not a live ban');
is_same(ban_row_is_active('a string'), false, 'a value that is not a row is not a live ban');

// --- what the row says --------------------------------------------------

$remaining = ban_row_seconds_remaining(ban_row(array('expire_time' => time() + 600)));
ok($remaining > 595 && $remaining <= 600, 'the duration comes from when the ban ends');

is_same(
    ban_row_seconds_remaining(ban_row(array('expire_time' => time() - 600))),
    0,
    'a ban already over has nothing remaining rather than a negative'
);

ok(ban_row_is_social(ban_row(array('scope' => 's'))), 'the row says when a ban is social');
is_same(ban_row_is_social(ban_row(array('scope' => 'g'))), false, 'the row says when a ban is not social');
is_same(ban_row_is_social(ban_row(array('scope' => 'social'))), false, 'only the stored spelling counts');

// --- the handler has to use them ----------------------------------------

$mod = file_get_contents(REPO . '/functions/multi_fns/client/moderation.php');

ok(strpos($mod, 'ban_row_is_active') !== false, 'the handler requires the ban to be live');
ok(strpos($mod, 'ban_row_seconds_remaining') !== false, 'the handler takes the duration from the ban');
ok(strpos($mod, 'ban_row_is_social') !== false, 'the handler takes the scope from the ban');

// The client's copies must no longer decide anything.
$ban_at = strpos($mod, 'function client_ban');
$body = substr($mod, $ban_at, 2600);
ok(
    preg_match('/\$scope\s*===\s*[\'"]social[\'"]/', $body) !== 1,
    'the client\'s scope no longer decides whether the ban is social'
);
ok(
    strpos($body, 'time() + $seconds') === false,
    'the client\'s duration no longer decides when the ban ends'
);
// The handler counted the fields it was given, and refused fewer than four.
// It now declares the five the client sends and is read against that, so the
// count is exact rather than a floor: a field added to the packet is refused
// where before it was ignored.
ok(strpos($body, 'packet_fields') !== false, 'the handler declares the fields it takes');
ok(
    preg_match("/'ban_id'\s*=>\s*'uint'/", $body) === 1,
    'the ban it announces is named by a whole number'
);

t_done();

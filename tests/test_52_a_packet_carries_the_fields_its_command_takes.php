<?php
// A command reads the fields it takes, or refuses the packet.
//
// Handlers took everything after the command name as one opaque run of text
// and passed it on. What the sender wrote therefore decided how many fields
// the packet had, because the field separator was in it, and the receiving
// clients split on that separator. Where the server wrote fields of its own
// after the sender's value, the sender's separators moved them.
//
// A command now declares the fields it takes, by name and by kind, and the
// packet is read against that declaration. Two things follow from reading it
// this way. The count has to match exactly, which is what refuses a separator
// the sender added: an extra separator is an extra field. And each field is
// read as the kind it is used as, so what comes back is a value rather than
// text that happens to be in the right place.
//
// The outgoing packet is then built from what comes back, not from the text
// that arrived, so what was checked and what is sent cannot differ.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a packet carries the fields its command takes\n";

ok(function_exists('packet_fields'), 'a packet can be read against a declaration');

function refused_packet($data, $spec)
{
    try {
        packet_fields($data, $spec);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

if (!function_exists('packet_fields')) {
    t_done();
}

// --- the count has to match ----------------------------------------------

$two = array('x' => 'num', 'y' => 'num');

$got = packet_fields('4`9', $two);
is_same($got, array('x' => '4', 'y' => '9'), 'two fields are read into their names');

ok(refused_packet('4', $two), 'a packet short of a field is refused');
ok(refused_packet('4`9`2', $two), 'a packet with a field too many is refused');

// This is what refuses an added separator: it arrives as an extra field.
ok(refused_packet('4`9`', $two), 'a trailing separator is a field too many');
ok(refused_packet('`4`9', $two), 'a leading separator is a field too many');

// And a command that takes one field takes one.
$one = array('id' => 'uint');
is_same(packet_fields('7', $one), array('id' => 7), 'one field is read');
ok(refused_packet('7`8', $one), 'a second field is refused where one is declared');

// --- whole numbers --------------------------------------------------------

is_same(packet_fields('0', $one), array('id' => 0), 'zero is a whole number');
is_same(packet_fields('12', $one), array('id' => 12), 'a two digit whole number is read whole');

// It comes back as a number, which is what makes it safe to use as a key.
$read = packet_fields('7', $one);
ok(is_int($read['id']), 'a whole number comes back as a number');
is_same(packet_fields('007', $one), array('id' => 7), 'a padded whole number reads as the number it is');

ok(refused_packet('-1', $one), 'a negative is not a whole number');
ok(refused_packet('1.5', $one), 'a decimal is not a whole number');
ok(refused_packet('1e3', $one), 'an exponent is not a whole number');
ok(refused_packet(' 7', $one), 'a padded number is not a whole number');
ok(refused_packet('seven', $one), 'a word is not a whole number');

// --- numbers used in arithmetic -------------------------------------------

$num = array('v' => 'num');

is_same(packet_fields('42', $num), array('v' => '42'), 'a whole number is a number');
is_same(packet_fields('-42', $num), array('v' => '-42'), 'a negative is a number');
is_same(packet_fields('1.5', $num), array('v' => '1.5'), 'a decimal is a number');
is_same(packet_fields('-0.25', $num), array('v' => '-0.25'), 'a negative decimal is a number');

// It comes back as the text that was checked, not as a float. Turning it into
// one and back can change it, which would be a new fault rather than a fix.
$read = packet_fields('1.10', $num);
is_same($read['v'], '1.10', 'a number comes back exactly as it was checked');

ok(refused_packet('1e3', $num), 'an exponent is not a number');
ok(refused_packet('.5', $num), 'a decimal with no whole part is not a number');
ok(refused_packet('5.', $num), 'a decimal with no fraction is not a number');
ok(refused_packet('+5', $num), 'a signed positive is not a number');
ok(refused_packet('1.2.3', $num), 'two points do not make a number');
ok(refused_packet('NaN', $num), 'a name for a number is not a number');
ok(refused_packet('Infinity', $num), 'a name for an unbounded number is not a number');
ok(refused_packet('0x1A', $num), 'another base is not a number');

// --- names ---------------------------------------------------------------

$word = array('n' => 'word');

is_same(packet_fields('state', $word), array('n' => 'state'), 'a name is read');
is_same(packet_fields('best_week', $word), array('n' => 'best_week'), 'a name may be joined with an underscore');
is_same(packet_fields('item2', $word), array('n' => 'item2'), 'a name may carry digits');

ok(refused_packet('two words', $word), 'a name has no spaces');
ok(refused_packet('name-with-dash', $word), 'a name has no dashes');
ok(refused_packet('<b>', $word), 'a name is not markup');

// --- text -----------------------------------------------------------------

$text = array('t' => 'text');

is_same(packet_fields('hello there', $text), array('t' => 'hello there'), 'text is read as it is');
is_same(packet_fields('anything -- goes!', $text), array('t' => 'anything -- goes!'), 'text may carry punctuation');

// --- what every kind refuses ---------------------------------------------

$term = packet_terminator();

foreach (array('uint' => '1', 'num' => '1', 'word' => 'a', 'text' => 'a') as $kind => $fine) {
    $spec = array('f' => $kind);

    ok(!refused_packet($fine, $spec), "a $kind field accepts what it is for");
    ok(refused_packet('', $spec), "a $kind field is not empty");
    ok(refused_packet($fine . $term, $spec), "a $kind field does not carry the message terminator");
    ok(refused_packet($term . $fine, $spec), "a $kind field does not begin with the message terminator");
}

// The separator cannot survive inside a field, because reading the packet
// consumed it. It shows up as a count that does not match, which is the same
// refusal by a different route.
ok(refused_packet('a`b', array('t' => 'text')), 'a separator inside text is refused as a field too many');

// --- a field that is allowed to be empty ---------------------------------
//
// Some commands carry a field that is legitimately empty, because the thing
// that sends it has nothing to put there. That has to be declared rather than
// assumed, so a field is required unless its kind says otherwise.

foreach (array('uint', 'num', 'word', 'text') as $kind) {
    $required = array('f' => $kind);
    $optional = array('f' => $kind . '?');

    ok(refused_packet('', $required), "a $kind field is required by default");
    ok(!refused_packet('', $optional), "a $kind field declared as optional may be empty");
}

is_same(packet_fields('', array('f' => 'text?')), array('f' => ''), 'an empty optional field comes back empty');
is_same(packet_fields('down', array('f' => 'text?')), array('f' => 'down'), 'a filled optional field is read');

// Optional means it may be absent, not that it may be anything.
ok(refused_packet('x', array('f' => 'uint?')), 'an optional whole number is still a whole number when present');
ok(refused_packet('1.2.3', array('f' => 'num?')), 'an optional number is still a number when present');
ok(refused_packet('two words', array('f' => 'word?')), 'an optional name is still a name when present');
ok(refused_packet($term, array('f' => 'text?')), 'an optional field still refuses the message terminator');

// It does not change how many fields the command takes.
$with_optional = array('a' => 'num', 'b' => 'num', 'c' => 'text?');
is_same(
    packet_fields('1`2`', $with_optional),
    array('a' => '1', 'b' => '2', 'c' => ''),
    'a trailing optional field is a field that is present and empty'
);
ok(refused_packet('1`2', $with_optional), 'a trailing optional field is still a field that has to be there');

// --- a declaration this server does not have -----------------------------

ok(
    refused_packet('1', array('f' => 'integer')),
    'a field declared as a kind this server does not have is refused'
);
ok(
    refused_packet('1', array('f' => '')),
    'a field declared as nothing is refused'
);

// --- mixed declarations ---------------------------------------------------

$mixed = array('name' => 'word', 'id' => 'uint', 'amount' => 'num');
is_same(
    packet_fields('rot`3`-1.5', $mixed),
    array('name' => 'rot', 'id' => 3, 'amount' => '-1.5'),
    'a packet of several kinds is read into its names'
);
ok(refused_packet('rot`x`-1.5', $mixed), 'one bad field refuses the whole packet');
ok(refused_packet('`3`-1.5', $mixed), 'one empty field refuses the whole packet');

// The order of the declaration is the order of the fields.
$got = packet_fields('rot`3`-1.5', $mixed);
is_same(array_keys($got), array('name', 'id', 'amount'), 'the names come back in the order declared');

t_done();

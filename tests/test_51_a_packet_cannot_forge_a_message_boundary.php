<?php
// A packet body must not carry the byte that ends a message.
//
// Messages on the wire are separated by one byte, appended after the body is
// assembled and signed. A value that reaches a packet body carrying that byte
// therefore splits one server message into two at every client that receives
// it. The signature was computed over the whole body, so neither half carries
// a signature that covers it, and the second half begins wherever the sender
// chose rather than at a field the server wrote.
//
// This is checked where the message is framed rather than where each value
// arrives. One position covers every packet the server sends, including ones
// written later by code that has never heard of this rule.
//
// The check is a function of the body alone, so it cannot half-send a
// broadcast: a body that fails it fails on the first recipient, before
// anything has gone out, and fails the same way for all of them.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a packet cannot forge a message boundary\n";

// --- the boundary byte ----------------------------------------------------

ok(function_exists('packet_terminator'), 'the byte that ends a message is named');

if (function_exists('packet_terminator')) {
    is_same(packet_terminator(), chr(0x04), 'it is the byte the framing appends');
    is_same(strlen(packet_terminator()), 1, 'it is one byte');
}

// --- the refusal ----------------------------------------------------------

ok(function_exists('require_framable_packet'), 'a body can be tested before it is framed');

function refused_body($body)
{
    try {
        require_framable_packet($body);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

if (function_exists('require_framable_packet')) {
    $t = chr(0x04);

    ok(refused_body($t), 'a body that is only the boundary byte is refused');
    ok(refused_body("something$t"), 'a boundary byte at the end is refused');
    ok(refused_body("{$t}something"), 'a boundary byte at the start is refused');
    ok(refused_body("some{$t}thing"), 'a boundary byte in the middle is refused');
    ok(refused_body("a{$t}b{$t}c"), 'several boundary bytes are refused');

    // Ordinary packets pass. These are shapes the server builds, not values a
    // client sent.
    ok(!refused_body(''), 'an empty body is framable');
    ok(!refused_body('message`hello'), 'an ordinary packet is framable');
    ok(!refused_body("a`b`c`d"), 'a packet of several fields is framable');

    // Neighbouring control bytes are not the boundary and are not this rule's
    // business. Saying so keeps the rule exact.
    ok(!refused_body(chr(0x03)), 'the byte below it is not the boundary');
    ok(!refused_body(chr(0x05)), 'the byte above it is not the boundary');
    ok(!refused_body("line\nbreak"), 'a newline is not the boundary');

    // The same answer whatever the type, because a body is assembled from
    // whatever the caller had.
    ok(!refused_body(0), 'a body that is a number is framable');
}

// --- the framing has to use it --------------------------------------------

$src = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');

$start = strpos($src, 'public function write(');
ok($start !== false, 'the write is present');
$end = strpos($src, 'public function onConnect', $start);
$write = substr($src, $start, $end - $start);

ok(
    strpos($write, 'require_framable_packet') !== false,
    'the write tests the body before framing it'
);

// Before anything is assembled, signed or sent, and for a control connection
// as well as a player one.
$tested_at = strpos($write, 'require_framable_packet');
$signed_at = strpos($write, 'sign_server_packet');
$framed_at = strpos($write, 'packet_terminator');
$sent_at = strpos($write, 'parent::write');

ok($tested_at !== false && $signed_at !== false && $tested_at < $signed_at, 'the test comes before the signing');
ok($tested_at !== false && $sent_at !== false && $tested_at < $sent_at, 'the test comes before the sending');
ok(
    $tested_at !== false && strpos($write, 'if (!$this->process)') > $tested_at,
    'the test runs for a control connection as well as a player one'
);

// The framing appends the same byte the rule names, rather than its own copy.
ok(
    $framed_at !== false,
    'the framing appends the byte that is named rather than a literal of its own'
);

t_done();

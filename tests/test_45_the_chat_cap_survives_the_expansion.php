<?php
// The chat length cap must hold for the message that is actually sent.
//
// A message is cut to 150 bytes when it arrives. The server then expands emote
// short codes into the characters they stand for, and never cuts it again. The
// expansion replaces short sequences with much longer ones, so a message made
// entirely of short codes left the cap far behind, and it is the expanded text
// that is broadcast to the room.
//
// Cutting again after the expansion has to land on a character boundary. The
// expansions are multi byte, so cutting by bytes alone would leave half a
// character at the end and the result would not be valid text.

require_once __DIR__ . '/helper.php';
require_once REPO . '/multiplayer_server/ChatMessage.php';

use pr2\multi\ChatMessage;

echo "the chat cap survives the expansion\n";

is_same(ChatMessage::MAX_LENGTH, 150, 'the cap is stated in one place');

// Short messages are untouched.
is_same(ChatMessage::capLength('hello'), 'hello', 'a short message is left alone');

$exactly = str_repeat('a', 150);
is_same(ChatMessage::capLength($exactly), $exactly, 'a message exactly at the cap is left alone');

$over = str_repeat('a', 200);
is_same(strlen(ChatMessage::capLength($over)), 150, 'a message over the cap is cut to it');

// The case this is really about: text that is already expanded.
$expanded = str_repeat('( ͡° ͜ʖ ͡°)', 30);
ok(strlen($expanded) > 150, 'expanded emotes are well over the cap to begin with');

$capped = ChatMessage::capLength($expanded);
ok(strlen($capped) <= 150, 'expanded emotes are brought back under it');

// And the result has to still be valid text, not half a character.
ok(mb_check_encoding($capped, 'UTF-8'), 'the cut lands on a character boundary');

// Every emote in the table, to be sure none of them can straddle the cut.
$codes = array(':shrug:', ':lenny:', ':yay:', ':hi:', ':thumbsup:', ':thinking:', ':eyes:', ':lol:', ':tada:', ':fred:', ':clown:');
foreach ($codes as $code) {
    $message = ChatMessage::capLength(str_repeat($code, 40));
    ok(strlen($message) <= 150, "a message of $code is within the cap");
}

// --- the constructor has to cut again after expanding -------------------

$src = file_get_contents(REPO . '/multiplayer_server/ChatMessage.php');

$emotes_at = strpos($src, '$this->handleEmotes();');
$cap_after = strpos($src, 'capLength', $emotes_at);
$dispatch_at = strpos($src, 'handleCommand();');

ok($emotes_at !== false, 'the expansion happens in the constructor');
ok($cap_after !== false, 'the message is cut again after it');
ok($cap_after < $dispatch_at, 'the cut happens before the message is acted on');

// Both cuts must go through the same rule rather than each carrying its own
// copy of the number. The only place the number appears is where it is named.
is_same(substr_count($src, '150'), 1, 'the number appears once, where it is named');
ok(
    preg_match('/const MAX_LENGTH = 150;/', $src) === 1,
    'that one place is the constant'
);
is_same(substr_count($src, 'self::capLength('), 2, 'both cuts go through the one rule');

t_done();

<?php
// The control channel answers only from addresses it could legitimately be
// reached from.
//
// The port is not published, so today only services on the same network can
// reach it. That is a property of one deployment file, though, and adding a
// port mapping is an easy mistake to make later. This check means such a
// mistake does not immediately expose the twenty process handlers to the
// internet: a connection from outside the local ranges is refused before any
// command is read.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/manage_socket/control_auth.php';

echo "control channel: connections from outside the local ranges are refused\n";

ok(control_address_allowed('127.0.0.1'), 'the loopback address is allowed');
ok(control_address_allowed('10.1.2.3'), 'a private range address is allowed');
ok(control_address_allowed('172.17.0.4'), 'a container network address is allowed');
ok(control_address_allowed('192.168.1.5'), 'a local network address is allowed');

is_same(control_address_allowed('203.0.113.7'), false, 'a public address is refused');
is_same(control_address_allowed(''), false, 'an unknown address is refused');

// and the control client must actually consult it
$src = file_get_contents(REPO . '/multiplayer_server/PR2ControlClient.php');
ok(strpos($src, 'control_address_allowed') !== false, 'the control client applies the check');
ok(strpos($src, 'close()') !== false, 'a refused connection is closed');

t_done();

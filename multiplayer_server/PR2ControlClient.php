<?php

namespace pr2\multi;

// A connection on the control listener.
//
// It is in process mode from the moment it is created, so there is no step a
// caller takes to acquire the role and therefore no step to reach for. The
// listener this class serves is not published, and every command it accepts
// carries a signature made with a key that never travels.

class PR2ControlClient extends PR2Client
{
    public $process = true;

    // How many of these are open. Control connections are deliberately kept
    // out of the player port's per-address count, since they all arrive from
    // the one address the web tier and the poller share, so the channel keeps
    // its own.
    private static $open = 0;
    private $counted = false;

    public function __construct($socket)
    {
        parent::__construct($socket);
        $this->process = true;

        if (!\control_address_allowed($this->ip)) {
            output('Refused a control connection from ' . $this->ip);
            $this->close();
            $this->onDisconnect();
            return;
        }

        $limit = \control_connection_limit();
        if (self::$open >= $limit) {
            output('Refused a control connection from ' . $this->ip . ": $limit already open");
            $this->close();
            $this->onDisconnect();
            return;
        }

        self::$open++;
        $this->counted = true;

        output(' --- Creating PR2ControlClient --- ');
    }

    // Releases this connection's place. Guarded so that reaching here twice,
    // which the refusal paths above and the daemon between them can do, gives
    // the place back once rather than twice.
    public function onDisconnect()
    {
        if ($this->counted) {
            $this->counted = false;
            self::$open--;
        }

        parent::onDisconnect();
    }
}

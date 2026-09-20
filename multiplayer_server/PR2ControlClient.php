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

        output(' --- Creating PR2ControlClient --- ');
    }
}

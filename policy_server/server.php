<?php

namespace jiggmin\ps;

class Server extends \chabot\SocketServer
{
    // Why the ring says stop, or null while it does not. Shared by the
    // listener and every connection on it, because the question is about the
    // container rather than about a connection.
    public static $halted = null;

    // The daemon calls this on every listener it holds, roughly every two
    // seconds. It is the only periodic thing this server has, and it is what
    // the coupling attaches to.
    //
    // config.php refuses by exiting, which is right for a request or a cron
    // run and wrong here: the policy server is this container's foreground
    // process, so exiting stops the container and the observer inside it, and
    // a ring missing a member is halted by every member that remains and
    // cleared by none of them. Stopping the work would have made the stop
    // permanent. So the work stops and the process does not, and the moment
    // the ring is clear the next tick starts answering again on its own.
    public function onTimer()
    {
        $settings = \observer_gate_settings();
        self::$halted = is_string($settings)
            ? $settings                                 // an environment the gate cannot read
            : \observer_gate_reason($settings);
    }
}

<?php

namespace pr2\multi;

// The listener for the control channel.
//
// It is a socket server like the player one, and it deliberately owns none of
// the periodic sweeps. The daemon calls onTimer() once for every listener it
// holds, so leaving the sweeps here would run each of them twice per tick.
// Most of them only compare a stored time against the clock and would survive
// that, but the loiter counter would not: it subtracts one and adds two per
// call, so counting it twice doubles the rate it climbs and halves the time it
// takes to fall back.

class PR2ControlServer extends PR2SocketServer
{
    public function onTimer()
    {
    }
}

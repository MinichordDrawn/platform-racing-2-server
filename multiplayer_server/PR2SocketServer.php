<?php

namespace pr2\multi;

class PR2SocketServer extends \chabot\SocketServer
{
    public static $tournament = false;
    public static $no_prizes = false;
    public static $tournament_hat = 1;
    public static $tournament_speed = 65;
    public static $tournament_acceleration = 65;
    public static $tournament_jumping = 65;

    public static $prizer_id = 0;

    // Why the ring says stop, or null while it does not.
    //
    // Shared by both listeners and every client on them, because the question
    // is about the container and not about a connection.
    public static $halted = null;

    // Whether the startup loadup has been done.
    //
    // It reads six tables and writes a row saying this server is up, so it
    // does not happen while the ring says stop. A server that skipped it
    // outright could not serve when the halt lifted, so it is deferred to the
    // first clear tick instead, which keeps the startup path and the recovery
    // path the same path.
    public static $loaded = false;

    // once every 2 seconds
    public function onTimer()
    {
        // The store path of the coupling, for the runtime that cannot use the
        // one in config.php.
        //
        // config.php refuses by exiting, which is right for a request or a
        // cron run and wrong here: the game server is this container's
        // foreground process, so exiting stops the container and the observer
        // inside it, and a ring missing a member is halted by every member
        // that remains and can be cleared by none of them. Stopping the work
        // would have made the stop permanent.
        //
        // So this stops the work without stopping the process. Nothing new is
        // admitted, nothing already connected is acted on, and none of the
        // sweeps below run -- they write to the database, and a halt is not
        // the moment to act on state gathered by a server that may be the
        // thing that is wrong. The process stays up, the observer beside it
        // keeps reporting, and the moment the ring is clear the next tick
        // starts the work again on its own.
        $was = self::$halted;
        $settings = \observer_gate_settings();
        self::$halted = is_string($settings)
            ? $settings                                 // an environment the gate cannot read
            : \observer_gate_reason($settings);

        if (self::$halted !== null) {
            if ($was === null) {
                output('--- STOPPED BY THE OBSERVER NETWORK --- ' . self::$halted);
            }
            return;
        }
        if ($was !== null) {
            output('--- the observer network is clear, working again ---');
        }

        // The work a halt at startup postponed.
        //
        // Every sweep below reads state this builds, so it has to come first,
        // and it has to come after the gate check above: loading up during a
        // halt is the thing being prevented. A server that came up while the
        // ring said stop reaches this on the first clear tick.
        if (!self::$loaded) {
            global $server_id, $pdo;

            // And the connection the halt postponed with it. A process that
            // started while the ring said stop never opened one, because
            // opening it is touching the database; every query below goes
            // through this global, so it is established here or not at all.
            if ($pdo === null) {
                try {
                    $pdo = \pdo_connect();
                } catch (\Exception $e) {
                    // Still unreachable. Every sweep below this needs the
                    // database too, so there is nothing useful to do this
                    // tick -- and nothing worth ending the process for. Try
                    // again on the next one.
                    return;
                }
            }

            \begin_loadup($server_id);
            self::$loaded = true;
            output('--- loaded up ---');
        }

        TemporaryItems::removeExpired();
        ServerBans::removeExpired();
        Mutes::removeExpired();
        LoiterDetector::check();
        Game::tickAllReconnects();
        \socialBansRemoveExpired();
        \privateServerCheckStatus();
    }

    // Stops admitting.
    //
    // The connection is accepted by the kernel before any of this runs -- that
    // is what a listening socket does -- so the earliest the application can
    // act is here, and what it does is close it again without a byte being
    // read. The control listener inherits this, deliberately: a halted server
    // is no more willing to take instructions than it is to take players.
    public function onAccept($client = null)
    {
        if (self::$halted !== null && $client !== null) {
            $client->close();
            $client->onDisconnect();
        }
    }
}

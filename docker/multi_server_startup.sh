#!/bin/sh

# Who each process below runs as, said once.
#
# This container starts as root for one reason: so that it can stop being root.
# The observer runs as pr2obs and the work as www-data, and because they are
# different users the work cannot write the files its own observer publishes,
# nor signal the observer beside it. Mode 0755 on a pr2obs-owned store is
# exactly "read yes, write no" -- and the gate still reads it on every request.
#
# The last line execs, which replaces this shell. Nothing root is left running.

# The observer, as its own process on the same host as the work it watches.
#
# Not a tick inside the work's own loop: if that loop wedges, a tick inside it
# goes silent with it and reports nothing. A separate process still dies when
# the container does, which is what substrate dependency asks for, while
# reporting specifically when only the work has failed.
#
# -d auto_prepend_file= is not optional. The prepend that puts config.php in
# front of every PHP process would put the whole application in front of this
# one too: env.php, the database connection, the S3 client. An observer that
# loads the application shares code with the other observers through the back
# door, and can be stopped by a bug in the very thing it is watching.
setpriv --reuid=pr2obs --regid=pr2obs --clear-groups php -d auto_prepend_file= /pr2/observers/multi/run.php &

# The first-write barrier: the game server does not start until the observer
# above has written a heartbeat the gate accepts and nothing anywhere has
# halted.
#
# At the first moment of a deployment nothing has written anything, so the rule
# "stop unless an observer is alive" would stop every deployment on the way up.
# This is the stricter of the two ways out of that: rather than have the work
# tolerate an absent heartbeat for a while, the observer running becomes a
# precondition of working at all.
#
# It waits for as long as it takes, which is what keeps the observer above
# alive while it waits -- and what makes this container recoverable. The game
# server exits on a refusal at boot, the restart policy brings the container
# back, and it arrives here and waits with its observer running rather than
# dying in a loop with the ring a member short.
#
# Run with the prepend disabled for the same reason as the observer, and one
# more: config.php refuses by exiting, so a barrier behind it would exit
# instead of waiting, which is the one thing it is for.
setpriv --reuid=www-data --regid=www-data --clear-groups php -d auto_prepend_file= /pr2/common/observer_gate_wait.php

# the game server is the container's foreground process, so if it exits the container
# exits and the observer goes with it.
exec setpriv --reuid=www-data --regid=www-data --clear-groups php /pr2/multiplayer_server/pr2.php 1 true

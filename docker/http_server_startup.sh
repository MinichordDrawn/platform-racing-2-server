#!/bin/sh

# The data directories and the symlinks that reach them from the served tree
# are made when the image is built, not here. Nothing this script does writes
# into /pr2/http_server.

# The observer, as its own process on the same host as the work it watches.
#
# Not a tick inside Apache: if the work wedges, a tick inside it goes silent
# with it and reports nothing. A separate process still dies when the container
# does, which is what substrate dependency asks for, while reporting
# specifically when only the work has failed.
#
# Apache is the container's foreground process, so if Apache exits the
# container exits and the observer goes with it. The other direction -- the
# work stopping when the observer does -- is the barrier below and the gate in
# config.php.
#
# -d auto_prepend_file= is not optional. The prepend that puts config.php in
# front of every PHP process would put the whole application in front of
# this one too: env.php, the database connection, the S3 client. An
# observer that loads the application shares code with the other
# observers through the back door, and can be stopped by a bug in the
# very thing it is watching.
php -d auto_prepend_file= /pr2/observers/web/run.php &

# The first-write barrier: nothing works until the observer above has written a
# heartbeat the gate accepts and nothing anywhere has halted.
#
# At the first moment of a deployment nothing has written anything, so the rule
# "stop unless an observer is alive" would stop every deployment on the way up.
# This is the stricter of the two ways out of that: rather than have the work
# tolerate an absent heartbeat for a while, the observer running becomes a
# precondition of working at all.
#
# It waits for as long as it takes, which is what keeps the observer above
# alive while it waits. A barrier that timed out would stop this container,
# stop that observer with it, and leave the ring a member short -- which is a
# halt every remaining member raises and none of them can lift.
#
# Run with the prepend disabled for the same reason as the observer, and one
# more: config.php refuses by exiting, so a barrier behind it would exit
# instead of waiting, which is the one thing it is for.
php -d auto_prepend_file= /pr2/common/observer_gate_wait.php

# The two warm-up runs generate the server status and level list files so the
# site is not empty before the scheduler's first minute. Both are idempotent
# and both run as the unprivileged user this container runs as.
#
# They are the same jobs cron runs and go the same way: through the wrapper,
# which reads the observer network itself and records a refusal as a run rather
# than vanishing.
php -d auto_prepend_file= /pr2/common/cron/run.php minute
php -d auto_prepend_file= /pr2/common/cron/run.php hourly

exec apache2-foreground

#!/bin/sh

# The data directories and the symlinks that reach them from the served tree
# are made when the image is built, not here. Nothing this script does writes
# into /pr2/http_server.
#
# The two warm-up runs generate the server status and level list files so the
# site is not empty before the scheduler's first minute. Both are idempotent
# and both run as the unprivileged user this container runs as.
php /pr2/common/cron/minute.php
php /pr2/common/cron/hourly.php

# The observer, as its own process on the same host as the work it watches.
#
# Not a tick inside Apache: if the work wedges, a tick inside it goes silent
# with it and reports nothing. A separate process still dies when the container
# does, which is what substrate dependency asks for, while reporting
# specifically when only the work has failed.
#
# Apache is the container's foreground process, so if Apache exits the
# container exits and the observer goes with it. That is the intended coupling
# in this direction. The other direction -- the work stopping when the observer
# does -- is a later step, and until it exists an observer that dies is caught
# by its peers seeing its heartbeat stop advancing.
# -d auto_prepend_file= is not optional. The prepend that puts config.php in
# front of every PHP process would put the whole application in front of
# this one too: env.php, the database connection, the S3 client. An
# observer that loads the application shares code with the other
# observers through the back door, and can be stopped by a bug in the
# very thing it is watching.
php -d auto_prepend_file= /pr2/observers/web/run.php &

exec apache2-foreground

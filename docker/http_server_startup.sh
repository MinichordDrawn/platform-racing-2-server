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

exec apache2-foreground

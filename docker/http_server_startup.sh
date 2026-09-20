#!/bin/sh

# Everything the application writes lives under /pr2/data, outside the tree
# the image ships. That keeps the code in a running container constant, which
# is what makes a hash of it worth taking.
#
# The served tree reaches each one through a symlink, so every URL the client
# already asks for is unchanged. A symlink is also the one thing that can sit
# in the served tree without changing it: its content is the target path, and
# the target path does not vary.
for d in levels replays files emblems; do
    mkdir -p "/pr2/data/$d"
    chown www-data:www-data "/pr2/data/$d"
    chmod 0775 "/pr2/data/$d"
    if [ ! -e "/pr2/http_server/$d" ]; then
        ln -s "/pr2/data/$d" "/pr2/http_server/$d"
    fi
done

php /pr2/common/cron/minute.php
php /pr2/common/cron/hourly.php
exec apache2-foreground

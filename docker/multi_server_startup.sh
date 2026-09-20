#!/bin/sh

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
php -d auto_prepend_file= /pr2/observers/multi/run.php &

# the game server is the container's foreground process, so if it exits the container
# exits and the observer goes with it.
exec php /pr2/multiplayer_server/pr2.php 1 true

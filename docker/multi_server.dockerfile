# Start from an official php image
FROM php:8.2-cli

# Install extensions
RUN docker-php-ext-install pdo_mysql sockets pcntl

# Copy in php code
COPY config.php /pr2/
COPY common/ /pr2/common
COPY common/env.example.php /pr2/common/env.php
COPY functions/ /pr2/functions
COPY multiplayer_server/ /pr2/multiplayer_server
COPY vend/ /pr2/vend
# Only this container's own observer. It shares no code with any other
# observer and loads nothing from the application.
COPY observers/multi/ /pr2/observers/multi
COPY docker/prepend_file.ini $PHP_INI_DIR/conf.d/

# This image has no served tree at all, so everything it reads and writes is
# data. The directories are created here so that whichever container first
# mounts the data volume brings the right ownership with it.
RUN mkdir -p /pr2/data/levels /pr2/data/replays /pr2/data/files /pr2/data/emblems \
    && chown -R www-data:www-data /pr2/data

# The game server binds 9160, which needs no privilege, so nothing here has
# any reason to run as root.
# The store tree (SPEC 2). The mount points are created here and owned by the
# user the observer runs as, so a named volume mounted over one inherits that
# ownership rather than arriving owned by root.
#
# heartbeat/ is deliberately not created. A store root with no heartbeat folder
# is how a reader concludes "never ran here"; pre-creating an empty one would
# turn that into "ran, and wrote nothing", which is a different claim.
# /pr2/shared is the application's cache volume -- vault.json,
# coins_options.json, last-pm.txt and the traces the scheduled work
# leaves. It is created here and owned by the app user for the same
# reason the store tree is: a named volume mounted over a path takes
# that path's ownership from the image, and a root-owned volume is
# unwritable to a container that no longer runs as root.
RUN mkdir -p /pr2/shared && chown -R www-data:www-data /pr2/shared

RUN mkdir -p /stores/web/copy /stores/web/copy-super /stores/web/halts \
             /stores/multi/copy /stores/multi/copy-super /stores/multi/halts \
             /stores/policy/copy /stores/policy/copy-super /stores/policy/halts \
             /stores/super/halts \
             /stores/super/copy-web /stores/super/copy-multi /stores/super/copy-policy \
    && chown -R www-data:www-data /stores

USER www-data

COPY docker/multi_server_startup.sh /multi_server_startup.sh

# The manifest of what this image contains, taken from the code that went into
# it and shipped inside it.
#
# The observer used to walk its own container at start-up and call that the
# baseline, which meant a tree altered before the observer started baselined as
# intended: `code-unchanged` was really saying "nothing changed while I was
# watching". Taking it here closes the gap between the image being built and
# the observer starting.
#
# It runs as root, so the file is root-owned, and 0444 leaves it unwritable by
# the unprivileged user the work runs as. It must be the last thing that
# touches /pr2, or it describes a tree the image does not ship.
#
# -d auto_prepend_file= for the usual reason: config.php would otherwise run in
# front of this and refuse, and this is a build step, not a request.
# Taken as root, so the file is root-owned and the unprivileged user the
# work runs as cannot rewrite it, then straight back to that user.
USER root
RUN php -d auto_prepend_file= -r 'require "/pr2/observers/multi/container.php"; \
        $e = get_loaded_extensions(); sort($e, SORT_STRING); \
        file_put_contents("/pr2/.container-baseline", json_encode(array( \
            "code" => \pr2obs\multi\code_manifest(), "extensions" => $e))); ' \
    && chmod 0444 /pr2/.container-baseline
USER www-data

# Run the gameserver, with its observer alongside it
ENTRYPOINT []
CMD ["/multi_server_startup.sh"]

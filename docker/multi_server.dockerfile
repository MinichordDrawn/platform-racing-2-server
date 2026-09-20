# Start from an official php image
FROM php:8.2-cli

# Install extensions
RUN docker-php-ext-install pdo_mysql sockets

# Copy in php code
COPY config.php /pr2/
COPY common/ /pr2/common
COPY common/env.example.php /pr2/common/env.php
COPY functions/ /pr2/functions
COPY multiplayer_server/ /pr2/multiplayer_server
COPY vend/ /pr2/vend
COPY docker/prepend_file.ini $PHP_INI_DIR/conf.d/

# This image has no served tree at all, so everything it reads and writes is
# data. The directories are created here so that whichever container first
# mounts the data volume brings the right ownership with it.
RUN mkdir -p /pr2/data/levels /pr2/data/replays /pr2/data/files /pr2/data/emblems \
    && chown -R www-data:www-data /pr2/data

# The game server binds 9160, which needs no privilege, so nothing here has
# any reason to run as root.
USER www-data

# Run the gameserver
ENTRYPOINT ["php", "pr2/multiplayer_server/pr2.php", "1", "true"]

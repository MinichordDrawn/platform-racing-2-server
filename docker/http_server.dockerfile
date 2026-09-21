FROM php:8.2-apache

# Copy in php code
COPY config.php /pr2/
COPY composer.json /pr2/
COPY composer.lock /pr2/
COPY common/ /pr2/common
COPY functions/ /pr2/functions
COPY http_server/ /pr2/http_server
COPY vend/ /pr2/vend
# Only this container's own observer. It shares no code with any other
# observer and loads nothing from the application, so there is nothing
# else to bring.
COPY observers/web/ /pr2/observers/web
COPY common/env.example.php /pr2/common/env.php
COPY docker/http_server_startup.sh /http_server_startup.sh

# Copy in custom config
COPY docker/pr2hub_proxy.conf /etc/apache2/conf-available/pr2hub_proxy.conf

# Use the default production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Move web root
ENV APACHE_DOCUMENT_ROOT=/pr2/http_server
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Listen where an unprivileged process is allowed to bind. The host still
# publishes 80, so every address the client dials is unchanged, and the user
# this container runs as stops being decided by the port number.
ENV APACHE_LISTEN_PORT=8080
RUN sed -ri "s/^Listen 80\$/Listen ${APACHE_LISTEN_PORT}/" /etc/apache2/ports.conf \
    && sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${APACHE_LISTEN_PORT}>/" /etc/apache2/sites-available/*.conf

# Install system dependencies
RUN apt-get update && apt-get install -y \
    zip \
    cron

ENV PR2HUB_PROXY_URL=http://pr2hub-proxy:8080
ENV MULTI_INTERNAL_HOST=multi
ENV MULTI_INTERNAL_PORT=9160

# Install extensions
RUN docker-php-ext-install pdo_mysql pcntl

# Install pecl extensions
RUN pear config-set php_ini "$PHP_INI_DIR/php.ini" \
    && pecl install apcu-5.1.23 \
    && docker-php-ext-enable apcu

# Install composer dependencies
RUN cd /pr2 \
    && curl -sS https://getcomposer.org/installer | php \
    && php composer.phar install --no-dev --optimize-autoloader

# Put config.php in front of every PHP process -- but only from here on.
#
# This is what carries the refusal on unchanged secrets to every request and
# every cron run, and it is deliberately the last thing installed. pecl and
# composer above are themselves PHP programs, and at build time env.php is
# the shipped example by construction, because the line above copies it
# there. Installed any earlier, the check fires during the build and the
# image cannot be built at all.
#
# The check is right and stays exactly as it is. What moved is where it runs.
COPY docker/prepend_file.ini $PHP_INI_DIR/conf.d/

# Create a cron file that runs the schedules
COPY docker/minute-cron /etc/cron.d/minute-cron
# Ensure LF endings (Windows checkouts can break cron) and correct perms
RUN sed -i 's/\r$//' /etc/cron.d/minute-cron \
    && chmod 0644 /etc/cron.d/minute-cron

# Job output goes to a real file the unprivileged jobs can write, which the
# cron container tails to its own stdout.
#
# The usual trick is to symlink this at /proc/1/fd/1 so job output lands on the
# container log directly. That descriptor belongs to PID 1 and is mode 0200, so
# it works only while the jobs run as root -- and the moment they dropped to
# www-data, the redirection in every crontab line failed and cron stopped
# running the commands at all.
RUN touch /var/log/cron.log \
    && chown www-data:www-data /var/log/cron.log \
    && chmod 0664 /var/log/cron.log

COPY docker/cron_startup.sh /cron_startup.sh

# Apache's logs are real files, not the image's symlinks to the container's
# stdio.
#
# Those symlinks point at /dev/stderr, which resolves to this container's own
# stderr -- and that belongs to root, because the container starts as root in
# order to drop. Apache re-opens its log *by path* rather than writing the
# descriptor it inherited, so once it is www-data it cannot open it and refuses
# to start: "could not open error log file /dev/stderr".
#
# Permission is checked when a file is opened, not when it is written. So the
# way out is the one the scheduler already uses for exactly this reason: a real
# file the unprivileged user owns, and something started before the drop that
# forwards it to the container's output over a descriptor it inherited.
RUN rm -f /var/log/apache2/error.log /var/log/apache2/access.log           /var/log/apache2/other_vhosts_access.log     && touch /var/log/apache2/error.log /var/log/apache2/access.log     && chown www-data:www-data /var/log/apache2/error.log /var/log/apache2/access.log     && chmod 0664 /var/log/apache2/error.log /var/log/apache2/access.log

# Enable reverse proxy support for same-origin PR2Hub and WebSocket forwarding.
RUN a2enmod proxy proxy_http proxy_wstunnel env \
    && a2enconf pr2hub_proxy

# Everything the application writes lives under /pr2/data, outside the tree
# this image ships. The served tree reaches each one through a symlink made
# here rather than at startup, so the tree is complete and constant from the
# moment the image is built -- which is what makes a hash of it mean
# something. A symlink is the one thing that can sit in the served tree
# without making it vary: its content is the target path, and that is fixed.
RUN set -eux; \
    for d in levels replays files emblems; do \
        mkdir -p "/pr2/data/$d"; \
        ln -s "/pr2/data/$d" "/pr2/http_server/$d"; \
    done; \
    chown -R www-data:www-data /pr2/data

# Apache runs unprivileged, so the directories it writes at runtime have to
# belong to the user it runs as.
RUN mkdir -p /var/run/apache2 /var/lock/apache2 \
    && chown -R www-data:www-data /var/log/apache2 /var/run/apache2 /var/lock/apache2

# The safe default. The scheduler is a separate service from the same image
# and overrides this, because cron has to start as root in order to drop to
# this user for the jobs themselves.
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

# The observer's own user.
#
# The observer and the work it watches share this container, and they shared a
# user: a PHP request could create, overwrite or delete any file its own
# observer published, and could signal the observer beside it. Every peer test
# is a property of the files, so forged bytes in the right shape are bytes no
# reader can tell from the real ones -- and signing does not help while the two
# share a user, because a key the observer can read is a key the work can read.
#
# A different uid is what makes "read yes, write no" true: the store tree below
# belongs to this user at mode 0755, so the work reads it -- the gate reads it
# on every request -- and cannot write it, and cannot signal it either.
#
# The id is pinned because every container's observer writes into the shared
# halts and copy directories, so they must all be the same user.
RUN adduser --system --no-create-home --uid 10002 --group pr2obs

RUN mkdir -p /stores/web/copy /stores/web/copy-super /stores/web/halts \
             /stores/multi/copy /stores/multi/copy-super /stores/multi/halts \
             /stores/policy/copy /stores/policy/copy-super /stores/policy/halts \
             /stores/super/halts \
             /stores/super/copy-web /stores/super/copy-multi /stores/super/copy-policy \
    && chown -R pr2obs:pr2obs /stores

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
RUN php -d auto_prepend_file= -r 'require "/pr2/observers/web/container.php"; \
        $e = get_loaded_extensions(); sort($e, SORT_STRING); \
        file_put_contents("/pr2/.container-baseline", json_encode(array( \
            "code" => \pr2obs\web\code_manifest(), "extensions" => $e))); ' \
    && chmod 0444 /pr2/.container-baseline

# Root, and only so that the entrypoint can stop being root.
#
# The startup script launches the observer as pr2obs and then execs the work as
# www-data, which replaces this shell -- so the running container holds two
# processes and neither of them is root. Compose states the same thing beside
# the two capabilities that dropping needs.
USER root

ENTRYPOINT []
CMD ["/http_server_startup.sh"]

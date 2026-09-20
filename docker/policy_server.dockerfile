# Start from an official php image.
#
# 8.2, matching web and multi. This container was on 7.3, which has had
# no security patches since December 2021 and whose image is archived --
# and which the vendored socket daemon no longer suits. socket_create()
# returns a resource on PHP 7 and a Socket object on PHP 8, and
# SocketDaemon calls spl_object_id() on it, so on 7.3 every server
# created warned and the identity it keyed the server by was not one.
# multi has run the same daemon on 8.2 throughout.
FROM php:8.2-cli

# Copy in php code
COPY config.php /pr2/
COPY common/ /pr2/common
COPY common/env.example.php /pr2/common/env.php
COPY policy_server/ /pr2/policy_server
COPY vend/ /pr2/vend
# Only this container's own observer. It shares no code with any other
# observer and loads nothing from the application.
COPY observers/policy/ /pr2/observers/policy

# Copy in custom config
COPY docker/prepend_file.ini $PHP_INI_DIR/conf.d/

# install extensions
RUN docker-php-ext-install pdo_mysql sockets pcntl

# The Flash policy port is 843, which no unprivileged process may bind. The
# host publishes 843 and maps it here, so the client dials what it always
# did and this process needs no privilege to answer.
ENV POLICY_PORT=8843

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

COPY docker/policy_server_startup.sh /policy_server_startup.sh

# Run the policy server, with its observer alongside it
CMD ["/policy_server_startup.sh"]

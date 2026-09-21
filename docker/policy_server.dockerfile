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

USER www-data

COPY docker/policy_server_startup.sh /policy_server_startup.sh

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
RUN php -d auto_prepend_file= -r 'require "/pr2/observers/policy/container.php"; \
        $e = get_loaded_extensions(); sort($e, SORT_STRING); \
        file_put_contents("/pr2/.container-baseline", json_encode(array( \
            "code" => \pr2obs\policy\code_manifest(), "extensions" => $e))); ' \
    && chmod 0444 /pr2/.container-baseline
# Root, and only so that the entrypoint can stop being root.
#
# The startup script launches the observer as pr2obs and then execs the work as
# www-data, which replaces this shell -- so the running container holds two
# processes and neither of them is root. Compose states the same thing beside
# the two capabilities that dropping needs.
USER root

# Run the policy server, with its observer alongside it
CMD ["/policy_server_startup.sh"]

# The super observer.
#
# This image holds the observer and nothing else. There is no application in
# it: no web server, no game server, no database client, no configuration. The
# rule that an observer cannot be stopped by a bug in the thing it is watching
# is true here by construction rather than by discipline -- there is nothing
# else in the container to go wrong.
#
# It is also the member most worth moving off this host later. Doing so is the
# only thing that would raise the ceiling the design describes, and an image
# with no dependencies is the easiest thing to move.
FROM php:8.2-cli

# The one extension this image installs, and the only thing in it that is
# not the observer itself.
#
# The observer handles a termination signal so that a member stopped on
# purpose records the stop rather than reading as a death. Installing the
# handler needs this, and without it the handler is never installed, the
# signal kills the process outright, and the mechanism is dead code that
# says nothing about being dead. Every observer declares it, so an image
# that loses it faults rather than going quiet.
RUN docker-php-ext-install pcntl

# The store tree (SPEC 2). Mount points created here and owned by the user the
# observer runs as, so a named volume mounted over one inherits that ownership
# rather than arriving owned by root. No heartbeat directories: a store root
# without one is how a reader concludes nothing ever ran there.
RUN mkdir -p /stores/web/copy /stores/web/copy-super /stores/web/halts \
             /stores/multi/copy /stores/multi/copy-super /stores/multi/halts \
             /stores/policy/copy /stores/policy/copy-super /stores/policy/halts \
             /stores/super/halts \
             /stores/super/copy-web /stores/super/copy-multi /stores/super/copy-policy \
    && chown -R www-data:www-data /stores

COPY observers/super/ /pr2/observers/super

USER www-data

# The manifest of what this image contains, taken from the code that went into
# it and shipped inside it. See the note on the other three images: the
# observer used to walk its own container at start-up and call that the
# baseline, so a tree altered before it started baselined as intended.
#
# It runs as root, so the file is root-owned, and 0444 leaves it unwritable by
# the unprivileged user this observer runs as. It must be the last thing that
# touches /pr2.
# Taken as root, so the file is root-owned and the unprivileged user the
# work runs as cannot rewrite it, then straight back to that user.
USER root
RUN php -r 'require "/pr2/observers/super/container.php";         $e = get_loaded_extensions(); sort($e, SORT_STRING);         file_put_contents("/pr2/.container-baseline", json_encode(array(             "code" => \pr2obs\super\code_manifest(), "extensions" => $e))); '     && chmod 0444 /pr2/.container-baseline
USER www-data

# -d auto_prepend_file= is belt and braces here, since this image installs no
# prepend at all. It is kept so that every observer in the deployment is
# started the same way, and so the rule can be checked in one place.
CMD ["php", "-d", "auto_prepend_file=", "/pr2/observers/super/run.php"]

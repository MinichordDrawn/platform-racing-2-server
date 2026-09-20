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

# -d auto_prepend_file= is belt and braces here, since this image installs no
# prepend at all. It is kept so that every observer in the deployment is
# started the same way, and so the rule can be checked in one place.
CMD ["php", "-d", "auto_prepend_file=", "/pr2/observers/super/run.php"]

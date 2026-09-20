#!/bin/sh

# Job output reaches the container's log through a file the jobs can write.
#
# The usual container trick is to symlink /var/log/cron.log to /proc/1/fd/1, so
# that anything a job prints lands on the container's stdout. That descriptor
# belongs to PID 1 and is mode 0200, so it works only while the jobs run as
# root. When they dropped to www-data the `>> /var/log/cron.log` in every
# crontab line failed, and cron never ran the command at all -- the jobs
# themselves were fine, the redirection was not.
#
# Nothing noticed for five steps of the build order. The observer's trace check
# is what found it: the minute job's trace went stale, and the ring halted and
# said so.
#
# So the log is a real file owned by the user the jobs run as, and this tails
# it to the container's stdout, which is a thing only root can do here.
# The gate's configuration has to reach the jobs, and cron will not carry it.
#
# cron builds a minimal environment for every job it runs -- it does not pass
# the container's. So the wrapper each schedule goes through could not read
# where the store tree is or which observer watches this host, and refused
# every run. The deployment did no scheduled work at all and looked healthy
# while doing it, because each refused run left a perfectly good trace.
#
# A crontab may carry NAME=value lines, and they apply to the jobs in that
# file, so the values are prepended to the shipped one here. Each is checked
# first: a value with a newline in it would be a value that adds a crontab
# entry, and one with a space would silently change the entry that follows. A
# value this cannot vouch for stops the container rather than being quoted,
# escaped or trimmed into something that looks close enough.
CRONTAB=/etc/cron.d/minute-cron
ENVLINES=""
for name in OBSERVER_STORES OBSERVER_LOCAL OBSERVER_LOCAL_MAX_AGE_SECONDS OBSERVER_SUPER_MAX_AGE_SECONDS; do
    eval "value=\${$name:-}"
    if [ -z "$value" ]; then
        echo "cron: $name is not set; the schedules would be refused every run" >&2
        exit 2
    fi
    case "$value" in
        *[!A-Za-z0-9_/.:-]*)
            echo "cron: $name holds a value this cannot put in a crontab" >&2
            exit 2
            ;;
    esac
    ENVLINES="$ENVLINES$name=$value
"
done

printf '%s' "$ENVLINES" | cat - "$CRONTAB" > "$CRONTAB.new"
mv "$CRONTAB.new" "$CRONTAB"
chmod 0644 "$CRONTAB"

# The log file is created by the image, owned by the user the jobs run as.
#
# It used to be created here, and could not be: this container is root with
# every capability dropped except SETUID and SETGID, and overriding a file's
# permissions or changing its owner are themselves capabilities. touch, chown
# and chmod all failed, every time, and printed three lines saying so above the
# thing anyone reading this log came to see. The image does it at build time
# where root still has everything, which is the right place for it anyway.
tail -F /var/log/cron.log &

exec cron -f

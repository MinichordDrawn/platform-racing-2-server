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
touch /var/log/cron.log
chown www-data:www-data /var/log/cron.log
chmod 0664 /var/log/cron.log

tail -F /var/log/cron.log &

exec cron -f

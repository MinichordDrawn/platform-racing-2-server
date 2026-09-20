#!/bin/bash
#
# Create the database schema.
#
# This runs unprivileged, so it writes its configuration under the home
# directory of the user it runs as rather than at the filesystem root, and it
# passes that path to Liquibase rather than relying on the working directory
# happening to be the one Liquibase searches.
set -eu

/scripts/wait-for-it.sh -t 360 mysql:3306

# Rewritten rather than appended: a container that runs twice would otherwise
# accumulate duplicate keys. Created private, because it holds the database
# password.
PROPS="${HOME:-/tmp}/liquibase.properties"
umask 077
{
    echo "driver: ${LIQUIBASE_DRIVER}"
    echo "url: ${LIQUIBASE_URL}"
    echo "username: ${LIQUIBASE_USERNAME}"
    echo "password: ${LIQUIBASE_PASSWORD}"
    echo "changeLogFile: ${LIQUIBASE_CHANGELOG}"
} > "$PROPS"

liquibase --defaultsFile="$PROPS" "$@"

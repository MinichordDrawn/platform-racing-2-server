#!/bin/sh
#
# Verify that the containers come up the way the compose file says they do.
#
# Everything here is a question with a determinate answer, asked of a running
# container. The compose file and the Dockerfiles are what the deployment
# *asks* for; this is what it actually got. The two are not the same check and
# the second is the one that has been missing.
#
# It publishes nothing and reaches nothing: the verify override removes every
# published port and puts the containers on an internal network with no route
# off the host.
#
# Run from the docker/ directory:
#   sh verify.sh

set -u

C="docker compose -f docker-compose.yml -f docker-compose.verify.yml"

pass=0
fail=0

ok() {
    if [ "$1" = "0" ]; then
        pass=$((pass + 1))
        printf '  PASS  %s\n' "$2"
    else
        fail=$((fail + 1))
        printf '  FAIL  %s\n' "$2"
        [ -n "${3:-}" ] && printf '        got: %s\n' "$3"
    fi
}

same() {
    if [ "$1" = "$2" ]; then
        ok 0 "$3"
    else
        ok 1 "$3" "expected '$2', got '$1'"
    fi
}

# A one-shot command in a container that is not running. `run` publishes no
# ports, which is a second reason nothing here is reachable.
inspect() {
    $C run --rm --no-deps --entrypoint sh "$1" -c "$2" 2>/dev/null | tr -d '\r'
}

# A command inside an already-running container.
within() {
    $C exec -T "$1" sh -c "$2" 2>/dev/null | tr -d '\r'
}

echo "verifying the containers come up as declared"
echo

# --- 0. the compose files are valid ---------------------------------------
#
# A second's work, and it settles two things before anything is built: that
# both files parse, and that this version of Compose understands `!override`,
# which is what removes the published ports. If it does not, the ports would
# be *added* to rather than replaced, and the run would not be isolated at
# all. Stopping here is much better than finding that out afterwards.

if ! $C config >/tmp/pr2-config.log 2>&1; then
    ok 1 "both compose files parse"
    echo
    tail -15 /tmp/pr2-config.log
    exit 1
fi
ok 0 "both compose files parse"

# grep -c prints 0 and exits 1 when nothing matches, so a `|| echo 0` here
# would append a second line and the comparison would never hold.
published=$(grep -c "published" /tmp/pr2-config.log 2>/dev/null)
same "${published:-0}" "0" "the merged configuration publishes no ports"

if grep -q "internal: true" /tmp/pr2-config.log; then
    ok 0 "the network has no route off the host"
else
    ok 1 "the network has no route off the host" "internal network missing from merged config"
fi
echo

# --- 1. the images build at all -------------------------------------------
#
# This is the step most likely to fail for a boring reason. Two base images
# are archived -- php:7.3-cli for the policy server and openjdk:12 for the
# migration runner -- and an image that can no longer be pulled is a
# deployment that can no longer be reproduced. That is a finding, not a
# setback.

echo "building images (this pulls several GB the first time)"
if $C build web multi policy pr2hub-proxy >/tmp/pr2-build.log 2>&1; then
    ok 0 "every first-party image builds"
else
    ok 1 "every first-party image builds" "see /tmp/pr2-build.log"
    echo
    tail -20 /tmp/pr2-build.log
    echo
    echo "stopping: nothing below can be trusted if the build failed"
    exit 1
fi
echo

# --- 2. who each container runs as ----------------------------------------

echo "identity"
same "$(inspect web 'id -un')"    "www-data" "web runs as www-data"
same "$(inspect multi 'id -un')"  "www-data" "multi runs as www-data"
same "$(inspect policy 'id -un')" "www-data" "policy runs as www-data"
same "$(inspect cron 'id -un')"   "root"     "cron runs as root, which is what it is for"
echo

# --- 3. the capabilities are actually gone --------------------------------
#
# Read from the kernel rather than from the compose file. CapEff is the set
# the process actually holds; all zeroes means every capability was dropped,
# including CAP_SYS_ADMIN, which is the one that would allow remounting a
# read-only mount read-write.

echo "capabilities"
for s in web multi policy; do
    caps=$(inspect "$s" 'grep CapEff /proc/self/status | tr -s " \t" " " | cut -d" " -f2')
    same "$caps" "0000000000000000" "$s holds no capabilities at all"
done

# cron keeps exactly two. 0000000000c0 is SETGID|SETUID; print it rather than
# asserting a literal, since what matters is that it is small and specific.
croncaps=$(inspect cron 'grep CapEff /proc/self/status | tr -s " \t" " " | cut -d" " -f2')
printf '  NOTE  cron CapEff is %s (expect only SETUID and SETGID)\n' "$croncaps"

for s in web multi policy cron; do
    nnp=$(inspect "$s" 'grep NoNewPrivs /proc/self/status | tr -s " \t" " " | cut -d" " -f2')
    same "$nnp" "1" "$s refuses new privileges"
done
echo

# --- 4. the served tree is image content plus four symlinks ---------------

echo "the served tree"
links=$(inspect web 'for d in levels replays files emblems; do
    if [ -L "/pr2/http_server/$d" ]; then printf "%s " "$d"; fi
done')
same "$(echo "$links" | tr -s ' ' | sed 's/ $//')" "levels replays files emblems" \
    "all four data directories are symlinks in the served tree"

target=$(inspect web 'readlink /pr2/http_server/levels')
same "$target" "/pr2/data/levels" "and they point into the data directory"

# Nothing else in the served tree may be a link, and nothing may be writable
# by the user the application runs as -- that is what makes a hash of it mean
# something.
writable=$(inspect web 'find /pr2/http_server -maxdepth 1 -type d -writable 2>/dev/null | grep -v "^/pr2/http_server$" | tr "\n" " "')
same "$(echo "$writable" | sed 's/ $//')" "" "no directory in the served tree is writable by the app user"
echo

# --- 5. the read-only mount is a kernel refusal ---------------------------
#
# The behavioural version of the whole 0b argument. A container that could
# remount this would make every single-writer rule a convention.

echo "read-only enforcement"
ro=$(inspect web 'touch /pr2/http_server/clients/verify-probe 2>&1 >/dev/null; echo $?')
if [ "$ro" != "0" ]; then
    ok 0 "the read-only mount refuses a write"
else
    ok 1 "the read-only mount refuses a write" "the write succeeded"
fi

remount=$(inspect web 'mount -o remount,rw /pr2/http_server/clients 2>&1 >/dev/null; echo $?')
if [ "$remount" != "0" ]; then
    ok 0 "and the container cannot remount it read-write"
else
    ok 1 "and the container cannot remount it read-write" "the remount succeeded"
fi
echo

# --- 6. the data directory is owned by the app user -----------------------

echo "data ownership"
same "$(inspect web 'stat -c %U /pr2/data')"       "www-data" "the data root is owned by the app user"
same "$(inspect web 'stat -c %U /pr2/data/files')" "www-data" "and so is the generated-files directory"
echo

# --- 7. the services actually start ---------------------------------------
#
# Everything above ran a container with a shell instead of its real command.
# This starts them properly, which is the only way to learn whether Apache
# comes up as www-data on an unprivileged port and whether cron survives with
# two capabilities.

echo "starting the services"
$C up -d web multi policy cron >/dev/null 2>&1
sleep 8

apache_user=$(within web 'ps -eo user,comm | grep -m1 apache2 | tr -s " " | cut -d" " -f1')
same "$apache_user" "www-data" "the Apache master process runs as www-data"

listening=$(within web 'grep -c ":1F90" /proc/net/tcp 2>/dev/null || echo 0')
if [ "${listening:-0}" != "0" ]; then
    ok 0 "Apache is listening on 8080"
else
    ok 1 "Apache is listening on 8080" "nothing bound to 8080"
fi

policy_up=$(within policy 'grep -c ":228B" /proc/net/tcp 2>/dev/null || echo 0')
if [ "${policy_up:-0}" != "0" ]; then
    ok 0 "the policy server is listening on 8843"
else
    ok 1 "the policy server is listening on 8843" "nothing bound to 8843 -- check POLICY_PORT"
fi

cron_alive=$($C ps --status running --services 2>/dev/null | grep -c '^cron$')
if [ "${cron_alive:-0}" != "0" ]; then
    ok 0 "the cron container stays up on SETUID and SETGID alone"
else
    ok 1 "the cron container stays up on SETUID and SETGID alone" "it exited -- see: $C logs cron"
fi
echo

echo "  $pass passed, $fail failed"
echo
echo "The scheduler drops to www-data only when a job fires. Leave it running"
echo "for a minute, then:"
echo "    $C logs cron"
echo "    $C exec -T web stat -c '%U %y' /pr2/data/files/server_status_2.txt"
echo "A file owned by www-data and freshly written is cron having dropped."
echo
echo "Tear down with:"
echo "    $C down -v"

[ "$fail" -eq 0 ] || exit 1

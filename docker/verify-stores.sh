#!/bin/sh
#
# Prove the store tree's write matrix by attempting a write at every path in
# every container.
#
# The compose file *declares* the matrix and a test checks that it does. This
# asks the kernel. They are different questions and the second one is the
# guarantee: the entire observer network rests on one observer being unable to
# forge another's state, and "an observer writes only its own folder" enforced
# by a helper the observer itself calls is no obstacle at all to an observer
# whose code has been rewritten. Here the write is refused by the mount, and
# the rule is true rather than agreed.
#
# A rw result also proves ownership, not only the flag -- and since the
# observer and the work now run as different users inside one container,
# ownership is doing more of the work than it used to. The observer owns the
# store tree and the work does not, so the second half of this script is the
# half that proves the work cannot forge what its own observer publishes.
#
# It publishes nothing and reaches nothing -- the verify override removes every
# published port and puts the containers on a network with no route off the
# host -- and `run` publishes no ports of its own either.
#
# Run from the docker/ directory:
#   sh verify-stores.sh

set -u

C="docker compose -f docker-compose.yml -f docker-compose.verify.yml"

# path | the identities permitted to write it (SPEC 2)
MATRIX="
/stores/web|web
/stores/web/copy|policy
/stores/web/halts|multi,policy,super
/stores/multi|multi
/stores/multi/copy|web
/stores/multi/halts|web,policy,super
/stores/policy|policy
/stores/policy/copy|multi
/stores/policy/halts|web,multi,super
/stores/super|super
/stores/super/halts|web,multi,policy
/stores/super/copy-web|web
/stores/super/copy-multi|multi
/stores/super/copy-policy|policy
/stores/web/copy-super|super
/stores/multi/copy-super|super
/stores/policy/copy-super|super
"

pass=0
fail=0

echo "proving the store write matrix"
echo

# Two positions per container, not one.
#
# Three of these hold an observer and the work it watches, and until now they
# shared a user -- so the work could write every file its own observer
# published. They are separate users now, which means the matrix has two
# halves and the second is the point:
#
#   as the observer (uid 10002)   the matrix below, path by path
#   as the work     (uid 33)      read-only everywhere, no exceptions
#
# The second half is enforced by file ownership rather than by a mount flag.
# That is a weaker kind of enforcement in one specific way -- a root process
# inside the container could override it -- which is why the entrypoints exec
# away from root and leave none running. Both halves are asked of the kernel
# here rather than assumed from the compose file.
probe_paths='
        for p in /stores/web /stores/web/copy /stores/web/copy-super /stores/web/halts \
                 /stores/multi /stores/multi/copy /stores/multi/copy-super /stores/multi/halts \
                 /stores/policy /stores/policy/copy /stores/policy/copy-super /stores/policy/halts \
                 /stores/super /stores/super/halts \
                 /stores/super/copy-web /stores/super/copy-multi /stores/super/copy-policy; do
            if [ ! -d "$p" ]; then
                echo "missing $p"
            elif touch "$p/.probe" 2>/dev/null; then
                rm -f "$p/.probe" 2>/dev/null
                echo "rw $p"
            else
                echo "ro $p"
            fi
        done
'

# --- the observer's position ----------------------------------------------
#
# One container per service rather than one per path: forty-two containers
# would take minutes and prove nothing extra.
for svc in web multi policy super; do
    echo "  $svc, as the observer"

    probe=$($C run --rm --no-deps --user 10002:10002 --entrypoint sh "$svc" -c "$probe_paths" 2>/dev/null | tr -d '\r')

    echo "$MATRIX" | while IFS='|' read -r path writers; do
        [ -z "$path" ] && continue

        want=ro
        for w in $(echo "$writers" | tr ',' ' '); do
            [ "$w" = "$svc" ] && want=rw
        done

        got=$(echo "$probe" | awk -v p="$path" '$2 == p {print $1}')
        [ -z "$got" ] && got="(no result)"

        if [ "$got" = "$want" ]; then
            printf '    PASS  %-32s %s\n' "$path" "$want"
        else
            printf '    FAIL  %-32s expected %s, got %s\n' "$path" "$want" "$got"
        fi
    done
    echo
done

# --- the work's position, and every other reader --------------------------
#
# None of these may write anywhere in the tree. web, multi and policy are the
# work beside an observer; cron runs the scheduled jobs and holds no observer;
# pr2hub-proxy is the one reader not written in PHP, so its result is also the
# only proof that the Go gate reads a tree it cannot alter.
for entry in "web:33:33" "multi:33:33" "policy:33:33" "cron:" "pr2hub-proxy:"; do
    svc=${entry%%:*}
    as=${entry#*:}

    if [ -n "$as" ]; then
        echo "  $svc, as the work"
        probe=$($C run --rm --no-deps --user "$as" --entrypoint sh "$svc" -c "$probe_paths" 2>/dev/null | tr -d '\r')
    else
        echo "  $svc, which holds no observer"
        probe=$($C run --rm --no-deps --entrypoint sh "$svc" -c "$probe_paths" 2>/dev/null | tr -d '\r')
    fi

    echo "$MATRIX" | while IFS='|' read -r path writers; do
        [ -z "$path" ] && continue

        got=$(echo "$probe" | awk -v p="$path" '$2 == p {print $1}')
        [ -z "$got" ] && got="(no result)"

        if [ "$got" = "ro" ]; then
            printf '    PASS  %-32s %s\n' "$path" "ro"
        else
            printf '    FAIL  %-32s expected ro, got %s\n' "$path" "$got"
        fi
    done
    echo
done
echo "For totals, pipe through: grep -c '^    PASS' and grep -c '^    FAIL'."
echo "Or read the lines above: every path should match its column in SPEC 2."

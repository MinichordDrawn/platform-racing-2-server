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
# A rw result also proves ownership, not only the flag: these containers run as
# www-data, so a writable mount whose volume arrived owned by root would show
# up here as refused.
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

# cron is here as a reader. It holds no observer and writes nothing into the
# tree, but it runs work, and work reads the tree before it works -- so the
# tree is mounted in it and it is held to the matrix from the other side:
# every path read-only, no exceptions. It is also the one container that runs
# as root, which makes the result worth having rather than assuming: a
# read-only mount is refused by the kernel whoever asks, and this is where that
# stops being a claim.
for svc in web multi policy super cron; do
    echo "  $svc"

    # One container per service rather than one per path: forty-two containers
    # would take minutes and prove nothing extra.
    probe=$($C run --rm --no-deps --entrypoint sh "$svc" -c '
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
    ' 2>/dev/null | tr -d '\r')

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

# The while loop above runs in a subshell, so the counters do not survive it.
# Count from the output instead, which is what a reader of this script would
# check anyway.
# Counted by matching the indented result lines rather than the words, because
# a summary that says "grep for FAIL" is a summary that shows up in the grep.
echo "For totals, pipe through: grep -c '^    PASS' and grep -c '^    FAIL'."
echo "Or read the lines above: every path should match its column in SPEC 2."

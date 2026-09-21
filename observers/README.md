# The observer network

Four small programs that watch the deployment and each other. When any one of
them finds something wrong, everything stops serving until every one of them
agrees it is fine again.

That is the whole idea. The rest of this file is the detail.

## The four

Each observer lives in its own folder here and runs as its own process, in its
own container. They are separate copies rather than one shared library on
purpose: a bug in one should not be a bug in all four, and a compromised one
should not be able to edit the others.

| | Runs beside | Watches, besides the other three |
|---|---|---|
| **web** | the web server | the Apache process and port 8080, the document root, the `proxy` modules, and the traces the scheduled jobs write |
| **multi** | the game server | the `pr2.php` process and both its ports, 9160 for players and 9161 for the control channel |
| **policy** | the Flash policy daemon | the `run_policy.php` process and port 8843 |
| **super** | nothing | only the other three. It has no work in its container at all |

**Every member watches every other member.** Four members, twelve one-way
edges, every pair mutual. There is no chain and no hierarchy.

**`super` is the coordinator**, and differs in two ways. It sees more: nine
copy folders against everyone else's two, which is a whole-view comparison no
other member can make. And its absence alone stops everything: if its
heartbeat goes stale, every gate refuses immediately, without waiting for any
member to decide anything.

## What a member does, once per cycle

Roughly three times a second. The cadence, six seconds, is a *ceiling* rather
than a pace: it is the longest a cycle may take and still count.

**About each of the other three**

- Is its store readable, and is there a heartbeat folder with something in it
- Has its heartbeat changed recently enough
- Does its chain hang together, each entry naming the one before it
- Is the copy it wrote the same as the original it came from

**About itself**

- Can it write its own store
- Is its own store still owned only by it, and not writable by the work beside it
- Did its last cycle finish inside the cadence
- Does its store hold only the files it is supposed to hold
- Is its own record of when it started observing readable

**About its container**

- Is the code the same as when the image was built
- Is PHP the expected version, with the extensions it declares
- Is `env.php` a real configuration and not the shipped example
- Is debug mode off and PayPal pointed at the live endpoint
- Is the work process running and answering on its ports

**About the scheduled work** (`web` only, because the jobs run beside it)

Each of the four schedules writes a trace when it runs. `web` reads them and
asks: did it run recently enough, did it finish, did it complete every task it
declares, and for the two tasks where zero rows means an empty table, did it
change anything.

| | Runs every | Allowed to be late by | Must finish within |
|---|---|---|---|
| minute | 1 minute | 30s | 30s |
| hourly | 1 hour | 5 minutes | 10 minutes |
| daily | 1 day | 10 minutes | 1 hour |
| weekly | 1 week | 1 hour | 2 hours |

Those last two columns are provisional. `schedules.php` says so: they want
measuring against real runs, because a deadline set below a job's honest
running time reports a healthy job as dying part way through, every time.

## What happens when something is wrong

1. The member that found it writes a **fault** file in its own store, and a
   **halt** file, and drops a copy of that halt into every other member's
   `halts/` folder.
2. Every other member sees the halt next cycle and passes it on.
3. Everything stops serving. HTTP returns 503, the scheduled jobs record that
   they were refused rather than silently skipping, the game server stops
   letting anyone in, the policy daemon stops answering, and the PR2Hub proxy
   answers the same 503 from its own separate reader.

**Stopping is one member's decision. Starting again is everyone's.** A member
clears only when no store holds a fault and no halt is in force anywhere. So a
member that deletes its own halt has changed nothing, because the other three
still hold theirs.

Nothing is stopped from *starting*. Containers come up, processes run, the
observers keep cycling. What stops is the work.

## Where it keeps things

```
/stores/
  web/         heartbeat/  the last four, newest wins
               fault       what this member currently finds wrong
               halt        this member says stop
               halts/      halts other members have delivered here
               since       when this member first ran
               copy/       policy's heartbeat, for web to check
               copy-super/ super's heartbeat, for web to check
  multi/       the same
  policy/      the same
  super/       heartbeat, fault, halt, halts, since,
               and copy-web, copy-multi, copy-policy
```

Every path has exactly one writer, and that is enforced by how the volumes are
mounted rather than by the code agreeing to behave.

Outside its own store, a member writes in three kinds of place, and it is the
only writer of each:

- the `halts/` slot named after it, in each of the other three
- the one peer's `copy/` folder it is the author for
- its own `copy-<name>` folder in `super`'s store

`super` is the exception to the middle one: instead of one peer's `copy/` it
writes `copy-super/` in all three of the others. That is what corroborates its
own store, which matters most for the member whose unreachability stops
everything and who alone decides the all-clear.

Inside the three containers that hold work, the observer and the work run as
**different users**, and the store belongs to the observer. The game server
cannot write what its own observer publishes.

## Looking at it

```
cd docker
ring-status
```

Live view of the members, the edges, the copies and anything halted. It only
reads. `ring-status --once` for a single frame.

## Two things worth knowing

**The cadence is not the cycle time.** Cycles run as fast as they finish, with
a floor of 300ms. A count of cycles is therefore not a measure of time, which
is why `since` exists and is written once and never rewritten.

**A check that cannot run has not passed.** Everywhere in here, being unable to
answer a question counts as a bad answer. An unreadable store, a heartbeat that
will not parse, a clock that cannot be read: all of them stop the work rather
than being skipped.

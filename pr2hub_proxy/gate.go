package main

// What this proxy reads before it serves anything.
//
// Every other way into this deployment passes through config.php, which asks
// the observer network whether it may work and refuses if the answer is no.
// This one did not. `/pr2hub/` is a ProxyPass in the web tier's vhost, so
// mod_proxy answers it and PHP never runs -- which meant that while every
// other path returned 503, this one went on serving from its cache and went on
// making outbound requests to a third party. A halt that leaves a door open is
// not a halt.
//
// So this is a sixth independent reader of the same bytes, written from the
// same specification as common/observer_gate.php and sharing no code with it.
// That is the arrangement the network already uses everywhere else: four
// observers that share nothing with each other, and a gate that shares nothing
// with any of them. Two readers that agree are evidence; one reader called
// twice is not.
//
// **What is different here, and why.** The gate in PHP runs inside a container
// that holds an observer, so it reads a local heartbeat as well. This
// container holds none and is no member of the ring, so there is no local
// heartbeat to read and no `OBSERVER_LOCAL` to name. What is left is the pair
// that needs no local observer: the super observer's heartbeat, which expires
// on its own if anything stops writing it, and the stop signals of all four
// members, which no amount of reading can be talked out of. Nothing here can
// be made fresher by the thing being judged -- this container writes into the
// store tree at no path at all.
//
// **It fails closed at every step.** A store that is not there, a directory
// that cannot be listed, a heartbeat that does not parse, a window the
// deployment never stated: each of them stops the proxy serving. There is no
// reading of any of them under which carrying on is the safer choice.
//
// **What it does not close.** The same thing the PHP gate does not close: it
// binds honest code only. A proxy binary that has been replaced with one that
// does not look carries on regardless, and nothing inside this process can
// prevent that.

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"time"
)

// The ring, named rather than discovered -- the same four, in the same
// spelling, as observer_gate_members(). A listing of the store tree that comes
// back short is indistinguishable from a tree with nothing wrong in it, and
// "the member whose store went missing" is precisely what this exists to
// catch.
func gateMembers() []string {
	return []string{"web", "multi", "policy", "super"}
}

// A heartbeat is bounded (SPEC 3.1) and its size is checked before anything
// reads its content, so an oversized file never reaches the parser.
const gateMaxHeartbeatBytes = 8192

// SPEC 3.1: the marker is the first key, in a fixed spelling, with no
// whitespace before or within it. A file classified by its first bytes cannot
// be mistaken for a file of another kind even if it has lost its tail.
const gateHeartbeatMarker = `{"kind":"heartbeat",`

var (
	gateHeartbeatName = regexp.MustCompile(`^\d{10}\.hb\z`)
	gateWholeNumber   = regexp.MustCompile(`^\d+\z`)
	gateTimestamp     = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z`)
)

// The settings, from a lookup of the environment.
//
// Returns the settings, or a string saying what is wrong with them. Nothing is
// defaulted except the store root, which is a path rather than a judgement: a
// gate that defaulted its window would be deciding, in code, how long this may
// serve unobserved, and the number would live where no review of a deployment
// would ever see it. The observers refuse on the same grounds.
func gateSettingsFrom(env func(string) string) (config, string) {
	cfg := config{GateStores: env("OBSERVER_STORES")}
	if cfg.GateStores == "" {
		cfg.GateStores = "/stores"
	}

	const name = "OBSERVER_SUPER_MAX_AGE_SECONDS"
	raw := env(name)
	if !gateWholeNumber.MatchString(raw) {
		return cfg, name + " must be set to a whole number of seconds between 1 and 3600"
	}
	seconds, err := strconv.Atoi(raw)
	if err != nil || seconds < 1 || seconds > 3600 {
		return cfg, name + " must be set to a whole number of seconds between 1 and 3600"
	}

	cfg.GateSuperMaxAge = time.Duration(seconds) * time.Second
	return cfg, ""
}

// A timestamp as SPEC 3.1 fixes it: exactly `YYYY-MM-DDTHH:MM:SSZ`, UTC, whole
// seconds. Anything else is not a timestamp, and a reader that guessed at one
// would be deciding freshness from a string it did not understand.
func gateTime(text string) (time.Time, bool) {
	if !gateTimestamp.MatchString(text) {
		return time.Time{}, false
	}
	at, err := time.Parse("2006-01-02T15:04:05Z", text)
	if err != nil {
		return time.Time{}, false
	}
	return at, true
}

// Is this member's observer alive?
//
// Returns the empty string if it is, or a short reason if it is not.
// Everything that is not a well-formed, current heartbeat of this member's own
// is a reason, including the ones that look like accidents: there is no
// reading of a truncated or misplaced heartbeat under which the work should
// keep going.
func gateAlive(stores, who string, maxAge time.Duration, now time.Time) string {
	dir := filepath.Join(stores, who, "heartbeat")

	names, err := os.ReadDir(dir)
	if err != nil {
		return who + " has no heartbeat folder"
	}

	// The current heartbeat is the highest sequence. The zero-padded names
	// make lexical order numeric order (SPEC 3.2), and a staging name is not a
	// heartbeat: accepting one would mean serving on a publication that has
	// not finished.
	latest := ""
	for _, n := range names {
		name := n.Name()
		if gateHeartbeatName.MatchString(name) && name > latest {
			latest = name
		}
	}
	if latest == "" {
		return who + " has never written a heartbeat"
	}

	path := filepath.Join(dir, latest)
	info, err := os.Stat(path)
	if err != nil {
		return who + "'s heartbeat cannot be read"
	}
	if info.Size() > gateMaxHeartbeatBytes {
		return who + "'s heartbeat is larger than a heartbeat can be"
	}

	raw, err := os.ReadFile(path)
	if err != nil {
		return who + "'s heartbeat cannot be read"
	}

	// SPEC 3.1, classification before parsing.
	if !bytes.HasPrefix(raw, []byte(gateHeartbeatMarker)) {
		return who + "'s current heartbeat does not begin as a heartbeat must"
	}

	// UseNumber, so that a version written as 1.0 is not quietly read as the
	// integer 1. The PHP reader distinguishes them by type and this one has to
	// agree with it.
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var hb map[string]interface{}
	if err := decoder.Decode(&hb); err != nil {
		return who + "'s heartbeat does not parse"
	}

	version, ok := hb["version"].(json.Number)
	if !ok {
		return who + "'s heartbeat has no usable version"
	}
	observer, ok := hb["observer"].(string)
	if !ok {
		return who + "'s heartbeat has no usable observer"
	}
	timestamp, ok := hb["timestamp"].(string)
	if !ok {
		return who + "'s heartbeat has no usable timestamp"
	}
	stop, ok := hb["stop"].(bool)
	if !ok {
		return who + "'s heartbeat has no usable stop"
	}

	if version.String() != "1" {
		return who + "'s heartbeat is of a format this deployment does not read"
	}
	if observer != who {
		// A heartbeat in one member's folder signed by another is a copied or
		// misplaced file, and the store it was found in has no current
		// heartbeat of its own.
		return "the heartbeat in " + who + "'s folder was not written by " + who
	}
	if stop {
		// A deliberate stop is still a stop. Nothing is watching that host.
		return who + " has stopped"
	}

	written, ok := gateTime(timestamp)
	if !ok {
		return who + "'s heartbeat is not dated"
	}

	age := now.Sub(written)
	if age > maxAge {
		return who + "'s heartbeat is " + strconv.Itoa(int(age.Seconds())) + " seconds old"
	}
	// A clock that disagrees by more than the window disagrees whichever way it
	// runs. Treating a future timestamp as merely fresh would let a wrong
	// clock, or a written file, hold the gate open indefinitely.
	if age < -maxAge {
		return who + "'s heartbeat is dated " + strconv.Itoa(int(-age.Seconds())) + " seconds from now"
	}

	return ""
}

// The decision. Returns the empty string if the proxy may serve, or a short
// reason if it may not.
//
// It reads and it decides; it writes nothing and it repairs nothing, which is
// the division every reader of this tree keeps.
func gateReason(cfg config, now time.Time) string {
	// The absence half, and the one read that matters most here: an observer
	// that dies or is stopped simply stops writing, and what this needs
	// expires without anyone having to notice or deliver anything. Nothing in
	// this container can refresh it.
	if reason := gateAlive(cfg.GateStores, "super", cfg.GateSuperMaxAge, now); reason != "" {
		return reason
	}

	// The positive half: anything any member found, in any of the three places
	// a member says it. This container writes into the store tree at no path
	// at all, so none of it can be written away from here.
	for _, m := range gateMembers() {
		root := filepath.Join(cfg.GateStores, m)

		info, err := os.Stat(root)
		if err != nil || !info.IsDir() {
			return "the store of " + m + " is not there"
		}
		if _, err := os.Stat(filepath.Join(root, "halt")); err == nil {
			return m + " has halted"
		}
		// A fault is read as well as a halt. It is strictly earlier: a member
		// writes its own fault the moment one of its own assertions fails, and
		// the halt that follows takes another member a cycle to find and
		// write. It cannot make a halt unrecoverable, because it is the same
		// condition: the ring stands down only when no store holds a fault.
		if _, err := os.Stat(filepath.Join(root, "fault")); err == nil {
			return m + " is faulted"
		}

		delivered, err := os.ReadDir(filepath.Join(root, "halts"))
		if err != nil {
			return "the halts directory of " + m + " cannot be read"
		}
		for _, n := range delivered {
			return "a halt found by " + n.Name() + " has been delivered to " + m
		}
	}

	return ""
}

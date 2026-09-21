package main

import (
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// A store tree with nothing wrong in it. Only the super observer's heartbeat
// is read -- this container holds no observer of its own and is no member, so
// there is no local heartbeat for it to consult.
func cleanStore(t *testing.T, now time.Time) string {
	t.Helper()

	root := t.TempDir()
	for _, m := range gateMembers() {
		for _, d := range []string{"heartbeat", "halts"} {
			if err := os.MkdirAll(filepath.Join(root, m, d), 0o755); err != nil {
				t.Fatal(err)
			}
		}
	}
	writeHeartbeat(t, root, "super", "super", 1, now, false)
	return root
}

func writeHeartbeat(t *testing.T, root, dir, observer string, seq int, at time.Time, stop bool) {
	t.Helper()

	body := fmt.Sprintf(
		`{"kind":"heartbeat","version":1,"observer":%q,"timestamp":%q,"stop":%t}`,
		observer,
		at.UTC().Format("2006-01-02T15:04:05Z"),
		stop,
	)
	writeHeartbeatBytes(t, root, dir, seq, body)
}

func writeHeartbeatBytes(t *testing.T, root, dir string, seq int, body string) {
	t.Helper()

	path := filepath.Join(root, dir, "heartbeat", fmt.Sprintf("%010d.hb", seq))
	if err := os.WriteFile(path, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}

func testGate(root string) config {
	return config{
		GateStores:      root,
		GateSuperMaxAge: 20 * time.Second,
	}
}

func TestGateAllowsWhenTheRingIsClean(t *testing.T) {
	now := time.Now().UTC()
	root := cleanStore(t, now)

	if reason := gateReason(testGate(root), now); reason != "" {
		t.Fatalf("a clean ring was refused: %s", reason)
	}
}

// Every member's stop signal, one at a time, in a tree that is otherwise
// clean. Each is written by a different writer into a different path, so
// testing one of them proves nothing about the others.
func TestGateRefusesOnEveryStopSignal(t *testing.T) {
	signals := []struct {
		name  string
		write func(t *testing.T, root, member string)
	}{
		{"halt", func(t *testing.T, root, member string) {
			touch(t, filepath.Join(root, member, "halt"))
		}},
		{"fault", func(t *testing.T, root, member string) {
			touch(t, filepath.Join(root, member, "fault"))
		}},
		{"a halt delivered by a peer", func(t *testing.T, root, member string) {
			touch(t, filepath.Join(root, member, "halts", "found-by-policy"))
		}},
		{"the store gone", func(t *testing.T, root, member string) {
			if err := os.RemoveAll(filepath.Join(root, member)); err != nil {
				t.Fatal(err)
			}
		}},
		{"the halts directory gone", func(t *testing.T, root, member string) {
			if err := os.RemoveAll(filepath.Join(root, member, "halts")); err != nil {
				t.Fatal(err)
			}
		}},
	}

	for _, sig := range signals {
		for _, member := range gateMembers() {
			t.Run(sig.name+"/"+member, func(t *testing.T) {
				now := time.Now().UTC()
				root := cleanStore(t, now)
				sig.write(t, root, member)

				if reason := gateReason(testGate(root), now); reason == "" {
					t.Fatalf("%s on %s did not stop the proxy", sig.name, member)
				}
			})
		}
	}
}

// Everything that is not a well-formed, current heartbeat of the super
// observer's own. There is no reading of any of these under which the proxy
// should keep serving.
func TestGateRefusesEveryUnusableSuperHeartbeat(t *testing.T) {
	cases := []struct {
		name    string
		corrupt func(t *testing.T, root string, now time.Time)
	}{
		{"never written", func(t *testing.T, root string, now time.Time) {
			if err := os.Remove(filepath.Join(root, "super", "heartbeat", "0000000001.hb")); err != nil {
				t.Fatal(err)
			}
		}},
		{"no heartbeat folder", func(t *testing.T, root string, now time.Time) {
			if err := os.RemoveAll(filepath.Join(root, "super", "heartbeat")); err != nil {
				t.Fatal(err)
			}
		}},
		{"stale", func(t *testing.T, root string, now time.Time) {
			writeHeartbeat(t, root, "super", "super", 2, now.Add(-60*time.Second), false)
		}},
		{"dated from the future", func(t *testing.T, root string, now time.Time) {
			writeHeartbeat(t, root, "super", "super", 2, now.Add(60*time.Second), false)
		}},
		{"stopped", func(t *testing.T, root string, now time.Time) {
			writeHeartbeat(t, root, "super", "super", 2, now, true)
		}},
		{"signed by another", func(t *testing.T, root string, now time.Time) {
			writeHeartbeat(t, root, "super", "web", 2, now, false)
		}},
		{"a staging name is not a heartbeat", func(t *testing.T, root string, now time.Time) {
			if err := os.Remove(filepath.Join(root, "super", "heartbeat", "0000000001.hb")); err != nil {
				t.Fatal(err)
			}
			path := filepath.Join(root, "super", "heartbeat", "0000000002.hb.staging")
			if err := os.WriteFile(path, []byte(`{"kind":"heartbeat","version":1,"observer":"super","timestamp":"2026-01-01T00:00:00Z","stop":false}`), 0o644); err != nil {
				t.Fatal(err)
			}
		}},
		{"does not begin as a heartbeat must", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2, ` {"kind":"heartbeat","version":1,"observer":"super","timestamp":"2026-01-01T00:00:00Z","stop":false}`)
		}},
		{"does not parse", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2, `{"kind":"heartbeat","version":1,`)
		}},
		{"of another format", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2, fmt.Sprintf(
				`{"kind":"heartbeat","version":2,"observer":"super","timestamp":%q,"stop":false}`,
				now.Format("2006-01-02T15:04:05Z")))
		}},
		{"stop is not a boolean", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2, fmt.Sprintf(
				`{"kind":"heartbeat","version":1,"observer":"super","timestamp":%q,"stop":"false"}`,
				now.Format("2006-01-02T15:04:05Z")))
		}},
		{"not dated", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2,
				`{"kind":"heartbeat","version":1,"observer":"super","timestamp":"yesterday","stop":false}`)
		}},
		{"larger than a heartbeat can be", func(t *testing.T, root string, now time.Time) {
			writeHeartbeatBytes(t, root, "super", 2, fmt.Sprintf(
				`{"kind":"heartbeat","version":1,"observer":"super","timestamp":%q,"stop":false,"pad":%q}`,
				now.Format("2006-01-02T15:04:05Z"), strings.Repeat("x", gateMaxHeartbeatBytes)))
		}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			now := time.Now().UTC()
			root := cleanStore(t, now)
			tc.corrupt(t, root, now)

			if reason := gateReason(testGate(root), now); reason == "" {
				t.Fatalf("a super heartbeat that was %s did not stop the proxy", tc.name)
			}
		})
	}
}

// A window the deployment did not state is not a window. Nothing here is
// defaulted, for the same reason the observers default none of it: the number
// decides how long this may serve unobserved, and it belongs where a review of
// the deployment would see it.
func TestGateSettingsRefuseWhatTheDeploymentDidNotState(t *testing.T) {
	cases := []struct {
		name string
		env  map[string]string
	}{
		{"nothing set", map[string]string{}},
		{"no window", map[string]string{"OBSERVER_STORES": "/stores"}},
		{"an empty window", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": ""}},
		{"a window that is not a number", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": "twenty"}},
		{"a window of zero", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": "0"}},
		{"a negative window", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": "-20"}},
		{"a window beyond an hour", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": "3601"}},
		{"a window with something after it", map[string]string{"OBSERVER_SUPER_MAX_AGE_SECONDS": "20s"}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := gateSettingsFrom(func(key string) string { return tc.env[key] })
			if err == "" {
				t.Fatalf("%s was accepted", tc.name)
			}
		})
	}
}

func TestGateSettingsAcceptAStatedWindow(t *testing.T) {
	cfg, err := gateSettingsFrom(func(key string) string {
		return map[string]string{
			"OBSERVER_STORES":                "/stores",
			"OBSERVER_SUPER_MAX_AGE_SECONDS": "20",
		}[key]
	})
	if err != "" {
		t.Fatalf("a stated window was refused: %s", err)
	}
	if cfg.GateStores != "/stores" || cfg.GateSuperMaxAge != 20*time.Second {
		t.Fatalf("unexpected settings %+v", cfg)
	}
}

// The whole point of the exercise: a halted ring stops the proxy serving, on
// every route, the same way it stops every PHP path.
func TestProxyRefusesEveryRouteWhenTheGateSaysStop(t *testing.T) {
	requests := []struct {
		method string
		target string
		body   string
	}{
		{http.MethodGet, "/files/lists/newest/1", ""},
		{http.MethodGet, "/levels/123.txt?version=5", ""},
		{http.MethodGet, "/level_data.php?level_id=7", ""},
		{http.MethodPost, "/search_levels.php", "mode=user&search_str=bls1999"},
		{http.MethodGet, "/nonsense", ""},
		// Liveness included, because Apache maps /pr2hub/ onto this whole path
		// space and publishes it. A path that answered 200 while every other
		// path answered 503 would be the one thing on the origin telling a
		// stranger that the deployment is stopped rather than broken -- which
		// is exactly what both refusal paths are written not to say.
		{http.MethodGet, "/healthz", ""},
	}

	for _, tc := range requests {
		t.Run(tc.method+" "+tc.target, func(t *testing.T) {
			now := time.Now().UTC()
			root := cleanStore(t, now)
			touch(t, filepath.Join(root, "web", "halt"))

			cfg := testGate(root)
			cfg.UpstreamBase = "http://127.0.0.1:1"
			cfg.ListTTLs = map[string]time.Duration{"newest": time.Minute}
			cfg.CacheDir = t.TempDir()

			rec := httptest.NewRecorder()
			newServer(cfg).ServeHTTP(rec, httptest.NewRequest(tc.method, tc.target, strings.NewReader(tc.body)))

			if rec.Code != http.StatusServiceUnavailable {
				t.Fatalf("expected 503, got %d", rec.Code)
			}
			if rec.Header().Get("Retry-After") == "" {
				t.Fatal("a refusal with no Retry-After")
			}
			if got := rec.Body.String(); got != "The server is not running.\n" {
				t.Fatalf("unexpected refusal body %q", got)
			}
		})
	}
}

// Whoever asked is told nothing about the deployment. The same division the
// PHP gate keeps, and for the same reason: a stopped server should not explain
// to a stranger which part of it stopped.
func TestRefusalSaysNothingAboutTheDeployment(t *testing.T) {
	now := time.Now().UTC()
	root := cleanStore(t, now)
	touch(t, filepath.Join(root, "multi", "halts", "found-by-policy"))

	cfg := testGate(root)
	cfg.CacheDir = t.TempDir()

	rec := httptest.NewRecorder()
	newServer(cfg).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/files/lists/newest/1", nil))

	for _, leak := range []string{"multi", "policy", "halt", root} {
		if strings.Contains(rec.Body.String(), leak) {
			t.Fatalf("the refusal told a stranger about %q: %q", leak, rec.Body.String())
		}
	}
}

// Nothing is answered ahead of the gate.
//
// There used to be a liveness route here, exempted on the grounds that
// something has to be able to tell a stopped proxy from a dead one. Two things
// were wrong with that. Apache maps /pr2hub/ onto this proxy's whole path
// space with no restriction, and the web tier publishes its ports, so the
// route was reachable from outside rather than from a supervisor; and nothing
// in the deployment ever called it. What it did was answer 200 during a halt
// while every other path answered 503, on the game's own origin -- an exemption
// with no caller, telling a stranger precisely what the refusal withholds.
//
// If liveness is wanted later it belongs somewhere that is not a published
// path: a container healthcheck runs inside the container and needs no route.
func TestNothingIsAnsweredAheadOfTheGate(t *testing.T) {
	src, err := os.ReadFile("main.go")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(src), "healthz") {
		t.Fatal("a liveness route survives in main.go")
	}
}

// A proxy whose settings do not say what the window is serves nothing. It
// still starts, so the refusal is visible as a 503 with a reason in the log
// rather than as a container that will not come up.
func TestProxyRefusesWhenItsSettingsAreUnusable(t *testing.T) {
	cfg := config{GateSettingsErr: "OBSERVER_SUPER_MAX_AGE_SECONDS must be set"}
	cfg.CacheDir = t.TempDir()

	rec := httptest.NewRecorder()
	newServer(cfg).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/files/lists/newest/1", nil))

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503, got %d", rec.Code)
	}
}

func touch(t *testing.T, path string) {
	t.Helper()

	if err := os.WriteFile(path, []byte(""), 0o644); err != nil {
		t.Fatal(err)
	}
}

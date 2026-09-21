package main

import (
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// Everything below is about the two boundaries this proxy sits on: what PR2Hub
// is allowed to put on the game's own origin, and what a stranger is allowed to
// tell it about itself.

func servingConfig(t *testing.T, upstream string) config {
	t.Helper()

	now := time.Now().UTC()
	cfg := testGate(cleanStore(t, now))
	cfg.CacheDir = t.TempDir()
	cfg.UpstreamBase = upstream
	cfg.Timeout = 5 * time.Second
	cfg.ListTTLs = map[string]time.Duration{"newest": time.Minute}
	cfg.SearchTTL = time.Minute
	cfg.LevelTTL = time.Hour
	cfg.LevelDataTTL = time.Minute
	return cfg
}

// Apache serves this proxy at /pr2hub/ on the game's own origin, so whatever
// comes back from PR2Hub arrives at the client as the game's own bytes. An
// upstream that says text/html would be saying it about this deployment's
// origin, not its own.
func TestUpstreamDoesNotChooseTheContentType(t *testing.T) {
	statuses := []int{http.StatusOK, http.StatusInternalServerError}

	for _, status := range statuses {
		t.Run(http.StatusText(status), func(t *testing.T) {
			upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "text/html; charset=utf-8")
				w.WriteHeader(status)
				_, _ = w.Write([]byte("<b>hello</b>"))
			}))
			defer upstream.Close()

			rec := httptest.NewRecorder()
			newServer(servingConfig(t, upstream.URL)).ServeHTTP(
				rec, httptest.NewRequest(http.MethodGet, "/files/lists/newest/1", nil))

			if got := rec.Header().Get("Content-Type"); got != proxyContentType {
				t.Fatalf("upstream chose the content type: %q", got)
			}
			if got := rec.Header().Get("X-Content-Type-Options"); got != "nosniff" {
				t.Fatalf("nothing stops the browser sniffing it: %q", got)
			}
		})
	}
}

// The same on the way back out of the cache, because a response served from
// the cache is the same bytes on the same origin.
func TestACachedResponseDoesNotCarryTheUpstreamsContentType(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/svg+xml")
		_, _ = w.Write([]byte("levels"))
	}))
	defer upstream.Close()

	cfg := servingConfig(t, upstream.URL)
	s := newServer(cfg)

	for _, want := range []string{"MISS", "HIT"} {
		rec := httptest.NewRecorder()
		s.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/files/lists/newest/1", nil))

		if got := rec.Header().Get("X-Proxy-Cache"); got != want {
			t.Fatalf("expected a %s, got %q", want, got)
		}
		if got := rec.Header().Get("Content-Type"); got != proxyContentType {
			t.Fatalf("on a %s the upstream chose the content type: %q", want, got)
		}
		if got := rec.Header().Get("X-Content-Type-Options"); got != "nosniff" {
			t.Fatalf("on a %s nothing stops the browser sniffing it", want)
		}
	}
}

// A refusal this proxy writes itself is on the same origin as everything else
// it serves.
func TestARefusedRequestIsAlsoDeclaredText(t *testing.T) {
	cfg := servingConfig(t, "http://127.0.0.1:1")

	rec := httptest.NewRecorder()
	newServer(cfg).ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/files/lists/newest/0", nil))

	if got := rec.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Fatalf("a rejection can be sniffed: %q", got)
	}
}

// There is no way to switch off the check that establishes the upstream is
// PR2Hub. A flag that turns verification off is a flag an attacker sets, and
// this design refuses that shape everywhere else.
func TestTheUpstreamIsAlwaysVerified(t *testing.T) {
	t.Setenv("PROXY_INSECURE_SKIP_VERIFY", "1")
	t.Setenv("OBSERVER_SUPER_MAX_AGE_SECONDS", "20")

	s := newServer(loadConfig())
	transport, ok := s.httpClient.Transport.(*http.Transport)
	if !ok {
		t.Fatal("the client has no transport to inspect")
	}
	if transport.TLSClientConfig != nil && transport.TLSClientConfig.InsecureSkipVerify {
		t.Fatal("an environment variable turned off verification of the upstream")
	}
}

// Apache appends the address it saw to whatever X-Forwarded-For arrived, so
// the last element is Apache's own observation and every element before it is
// the client's to write. Reading the first element let a stranger name
// themselves -- which is the rate limiter's key and the log's attribution.
func TestTheClientDoesNotGetToNameItself(t *testing.T) {
	cases := []struct {
		name    string
		headers map[string]string
		remote  string
		want    string
	}{
		{
			"what Apache appended wins over what the client sent",
			map[string]string{"X-Forwarded-For": "9.9.9.9, 10.0.0.5"},
			"172.18.0.2:53000",
			"10.0.0.5",
		},
		{
			"a single element is Apache's own",
			map[string]string{"X-Forwarded-For": "10.0.0.5"},
			"172.18.0.2:53000",
			"10.0.0.5",
		},
		{
			"a header nothing in this deployment sets is not evidence",
			map[string]string{"X-Real-IP": "9.9.9.9"},
			"172.18.0.2:53000",
			"172.18.0.2",
		},
		{
			"neither is one that is not an address",
			map[string]string{"X-Forwarded-For": "9.9.9.9, not-an-address"},
			"172.18.0.2:53000",
			"172.18.0.2",
		},
		{
			"nor an empty one",
			map[string]string{"X-Forwarded-For": " , "},
			"172.18.0.2:53000",
			"172.18.0.2",
		},
		{
			"with nothing forwarded at all, the peer is the client",
			map[string]string{},
			"172.18.0.2:53000",
			"172.18.0.2",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest(http.MethodGet, "/files/lists/newest/1", nil)
			req.RemoteAddr = tc.remote
			for k, v := range tc.headers {
				req.Header.Set(k, v)
			}

			if got := clientIP(req); got != tc.want {
				t.Fatalf("expected %q, got %q", tc.want, got)
			}
		})
	}
}

// And the rate limiter therefore cannot be stepped around by varying a header.
func TestTheRateLimitCannotBeResetByAHeader(t *testing.T) {
	s := newServer(servingConfig(t, "http://127.0.0.1:1"))
	route := &proxyRoute{Kind: routeKindSearch}
	now := time.Now()

	allowed := 0
	for i := 0; i < 40; i++ {
		req := httptest.NewRequest(http.MethodPost, "/search_levels.php", strings.NewReader(""))
		req.RemoteAddr = "172.18.0.2:53000"
		// A different claimed origin every time, behind the one real peer.
		req.Header.Set("X-Forwarded-For", strings.Repeat("9", i%9+1)+".1.1.1, 10.0.0.5")
		if s.allowRequest(clientIP(req), route, now) {
			allowed++
		}
	}

	if allowed > 15 {
		t.Fatalf("%d of 40 requests were allowed through one window", allowed)
	}
}

// The janitor is work, and work stops when the ring says stop.
//
// It deletes expired level-cache entries on a timer of its own, which ran
// regardless of gate state -- the one action in this process that continued
// through a halt. It touches no customer data and is not a way in, so it was
// recorded rather than ranked; it is fixed because "everything is prevented
// from working" is either true of this file or it is not.
func TestTheJanitorStopsWhenTheRingSaysStop(t *testing.T) {
	stale := &cacheEntry{
		StatusCode: 200,
		BodyBase64: "",
		FetchedAt:  time.Now().UTC().Add(-2 * time.Hour),
		ExpiresAt:  time.Now().UTC().Add(-time.Hour),
	}

	for _, tc := range []struct {
		name    string
		halt    bool
		survive bool
	}{
		{"a clean ring sweeps", false, false},
		{"a halted ring does not", true, true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			now := time.Now().UTC()
			root := cleanStore(t, now)
			if tc.halt {
				touch(t, filepath.Join(root, "policy", "halt"))
			}

			cfg := testGate(root)
			cfg.CacheDir = t.TempDir()
			s := newServer(cfg)

			if err := s.cache.Save(routeKindLevels, "levels:1:", stale); err != nil {
				t.Fatal(err)
			}

			s.janitorSweep(now)

			_, found, err := s.cache.Get(routeKindLevels, "levels:1:")
			if err != nil {
				t.Fatal(err)
			}
			if found != tc.survive {
				t.Fatalf("expected survival=%t, got %t", tc.survive, found)
			}
		})
	}
}

package main

import (
	"bytes"
	"log"
	"net/http"
	"net/url"
	"os"
	"strings"
	"testing"
	"time"
)

func testConfig() config {
	return config{
		UpstreamBase: "https://pr2hub.com",
		ListTTLs: map[string]time.Duration{
			"campaign":  time.Minute,
			"best":      time.Minute,
			"best_week": time.Minute,
			"newest":    time.Minute,
		},
		SearchTTL:    time.Minute,
		LevelTTL:     time.Hour,
		LevelDataTTL: time.Minute,
	}
}

func TestBuildListRoute(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/files/lists/newest/1", nil)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	if route.Kind != routeKindLists {
		t.Fatalf("expected routeKindLists, got %q", route.Kind)
	}
	if route.CacheKey != "lists:newest:1" {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if !route.AllowStale {
		t.Fatal("expected stale to be allowed for list route")
	}
}

func TestBuildSearchRouteNormalizesForm(t *testing.T) {
	body := strings.NewReader("search_str=test&page=2&mode=user&dir=desc&order=date")
	req, err := http.NewRequest(http.MethodPost, "http://trapwork.org/search_levels.php", body)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	expected := url.Values{
		"dir":        []string{"desc"},
		"mode":       []string{"user"},
		"order":      []string{"date"},
		"page":       []string{"2"},
		"search_str": []string{"test"},
	}.Encode()

	if route.CacheKey != "search:"+expected {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if string(route.UpstreamBody) != expected {
		t.Fatalf("unexpected upstream body %q", string(route.UpstreamBody))
	}
	if !route.AllowStale {
		t.Fatal("expected stale to be allowed for search route")
	}
}

func TestBuildSearchRouteIgnoresTokenAndRand(t *testing.T) {
	body := strings.NewReader("order=date&token=abc123&page=1&rand=8652729&dir=desc&mode=user&search_str=bls1999")
	req, err := http.NewRequest(http.MethodPost, "http://trapwork.org/search_levels.php", body)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	expected := url.Values{
		"dir":        []string{"desc"},
		"mode":       []string{"user"},
		"order":      []string{"date"},
		"page":       []string{"1"},
		"search_str": []string{"bls1999"},
	}.Encode()

	if route.CacheKey != "search:"+expected {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if string(route.UpstreamBody) != expected {
		t.Fatalf("unexpected upstream body %q", string(route.UpstreamBody))
	}
}

func TestBuildLevelRouteDisablesStale(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/levels/123.txt?version=5", nil)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	if route.Kind != routeKindLevels {
		t.Fatalf("expected routeKindLevels, got %q", route.Kind)
	}
	if route.AllowStale {
		t.Fatal("expected stale to be disabled for level route")
	}
	if route.UpstreamURL != "https://pr2hub.com/levels/123.txt?version=5" {
		t.Fatalf("unexpected upstream URL %q", route.UpstreamURL)
	}
}

func TestBuildLevelRouteIgnoresTokenAndRandQuery(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/levels/6472117.txt?version=4&rand=6295465&token=abc123", nil)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	if route.CacheKey != "levels:6472117:4" {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if route.UpstreamURL != "https://pr2hub.com/levels/6472117.txt?version=4" {
		t.Fatalf("unexpected upstream URL %q", route.UpstreamURL)
	}
}

func TestBuildListRouteIgnoresTokenAndRandQuery(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/files/lists/newest/1?rand=5463708&token=abc123", nil)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	if route.CacheKey != "lists:newest:1" {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if route.UpstreamURL != "https://pr2hub.com/files/lists/newest/1" {
		t.Fatalf("unexpected upstream URL %q", route.UpstreamURL)
	}
}

func TestBuildLevelDataRouteIgnoresTokenAndRandQuery(t *testing.T) {
	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/level_data.php?token=abc123&rand=5493332&level_id=6504283", nil)
	if err != nil {
		t.Fatal(err)
	}

	route, err := buildRoute(req, testConfig())
	if err != nil {
		t.Fatalf("buildRoute returned error: %v", err)
	}

	if route.CacheKey != "level_data:6504283" {
		t.Fatalf("unexpected cache key %q", route.CacheKey)
	}
	if route.UpstreamURL != "https://pr2hub.com/level_data.php?level_id=6504283" {
		t.Fatalf("unexpected upstream URL %q", route.UpstreamURL)
	}
}

// The token is the session credential this package sets at login, and the
// proxy is mounted on the game's own origin, so every route below is reached
// with a live one attached. The four tests above establish that it does not
// reach the upstream or the cache key. These establish the destinations that
// were missed.

func TestLogLineDoesNotCarryTheToken(t *testing.T) {
	routes := []struct {
		name string
		url  string
	}{
		{"lists", "http://trapwork.org/files/lists/newest/1?token=s3cr3t&rand=99"},
		{"levels", "http://trapwork.org/levels/123.txt?version=5&token=s3cr3t&rand=99"},
		{"level_data", "http://trapwork.org/level_data.php?level_id=7&token=s3cr3t&rand=99"},
	}

	for _, tc := range routes {
		t.Run(tc.name, func(t *testing.T) {
			var buf bytes.Buffer
			log.SetOutput(&buf)
			defer log.SetOutput(os.Stderr)

			req, err := http.NewRequest(http.MethodGet, tc.url, nil)
			if err != nil {
				t.Fatal(err)
			}
			route, err := buildRoute(req, testConfig())
			if err != nil {
				t.Fatalf("buildRoute returned error: %v", err)
			}

			s := newServer(testConfig())
			s.logRequest(req, route, "HIT", http.StatusOK, 0, time.Now(), nil)

			if strings.Contains(buf.String(), "s3cr3t") {
				t.Fatalf("the log line carries the session token: %s", buf.String())
			}
			if !strings.Contains(buf.String(), tc.name) {
				t.Fatalf("the log line no longer says which route was served: %s", buf.String())
			}
		})
	}
}

// A path is decoded by the time it reaches the logger, so an encoded newline
// in it arrives as a real one. Logging it unquoted would let a stranger write
// whole lines of their own into the record.
func TestLogLineCannotBeForgedThroughThePath(t *testing.T) {
	var buf bytes.Buffer
	log.SetOutput(&buf)
	defer log.SetOutput(os.Stderr)

	req, err := http.NewRequest(http.MethodGet, "http://trapwork.org/nope%0aGET%20/forged", nil)
	if err != nil {
		t.Fatal(err)
	}

	s := newServer(testConfig())
	s.logRequest(req, nil, "REJECTED", http.StatusNotFound, 0, time.Now(), nil)

	if strings.Count(strings.TrimSuffix(buf.String(), "\n"), "\n") != 0 {
		t.Fatalf("one request wrote more than one line: %q", buf.String())
	}
}

// The drop was conditional on there being something else in the form. A body
// carrying nothing but the token fell through to the raw bytes, which then
// became both the upstream body and the cache key -- so the credential went to
// a third party and was hashed into a filename on a shared volume.
func TestBuildSearchRouteDropsTokenWhenItIsTheOnlyField(t *testing.T) {
	bodies := []string{
		"token=s3cr3t",
		"token=s3cr3t&rand=8652729",
		"rand=8652729&token=s3cr3t",
	}

	for _, body := range bodies {
		t.Run(body, func(t *testing.T) {
			req, err := http.NewRequest(http.MethodPost, "http://trapwork.org/search_levels.php", strings.NewReader(body))
			if err != nil {
				t.Fatal(err)
			}

			route, err := buildRoute(req, testConfig())
			if err != nil {
				t.Fatalf("buildRoute returned error: %v", err)
			}

			if strings.Contains(route.CacheKey, "s3cr3t") {
				t.Fatalf("the token is in the cache key: %q", route.CacheKey)
			}
			if strings.Contains(string(route.UpstreamBody), "s3cr3t") {
				t.Fatalf("the token is in the upstream body: %q", string(route.UpstreamBody))
			}
			if strings.Contains(route.NormalizedKey, "s3cr3t") {
				t.Fatalf("the token is in the normalized key: %q", route.NormalizedKey)
			}
		})
	}
}

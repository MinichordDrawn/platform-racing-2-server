package main

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	searchBodyLimit = 8 * 1024
	defaultTTL      = 2 * time.Minute

	// What this proxy serves, declared here rather than taken from the
	// upstream's answer.
	//
	// Apache maps /pr2hub/ onto the game's own origin, so a Content-Type from
	// PR2Hub is a claim about this deployment's origin rather than its own,
	// and every one of the four routes carries a PR2 text format. Repeating
	// the upstream's header let a third party decide how bytes on this origin
	// would be interpreted; declaring it means the answer does not depend on
	// who answered.
	proxyContentType = "text/plain; charset=utf-8"
)

var (
	levelRoutePattern = regexp.MustCompile(`^/levels/([1-9][0-9]*)\.txt$`)
	versionPattern    = regexp.MustCompile(`^[0-9]*$`)
)

type routeKind string

const (
	routeKindLists     routeKind = "lists"
	routeKindSearch    routeKind = "search"
	routeKindLevels    routeKind = "levels"
	routeKindLevelData routeKind = "level_data"
)

type cacheEntry struct {
	StatusCode  int       `json:"status_code"`
	BodyBase64  string    `json:"body_base64"`
	FetchedAt   time.Time `json:"fetched_at"`
	ExpiresAt   time.Time `json:"expires_at"`
}

func (e *cacheEntry) Body() ([]byte, error) {
	return base64.StdEncoding.DecodeString(e.BodyBase64)
}

func (e *cacheEntry) IsFresh(now time.Time) bool {
	return now.Before(e.ExpiresAt)
}

type cacheStore struct {
	baseDir string
}

func newCacheStore(baseDir string) *cacheStore {
	return &cacheStore{baseDir: baseDir}
}

func (s *cacheStore) Get(kind routeKind, key string) (*cacheEntry, bool, error) {
	path := s.pathFor(kind, key)
	data, err := os.ReadFile(path)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil, false, nil
		}
		return nil, false, err
	}

	var entry cacheEntry
	if err := json.Unmarshal(data, &entry); err != nil {
		_ = os.Remove(path)
		return nil, false, nil
	}

	if _, err := entry.Body(); err != nil {
		_ = os.Remove(path)
		return nil, false, nil
	}

	return &entry, true, nil
}

func (s *cacheStore) Save(kind routeKind, key string, entry *cacheEntry) error {
	path := s.pathFor(kind, key)
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}

	data, err := json.Marshal(entry)
	if err != nil {
		return err
	}

	tmpPath := path + ".tmp"
	if err := os.WriteFile(tmpPath, data, 0o644); err != nil {
		return err
	}
	return os.Rename(tmpPath, path)
}

func (s *cacheStore) Delete(kind routeKind, key string) error {
	path := s.pathFor(kind, key)
	err := os.Remove(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	return err
}

func (s *cacheStore) DeleteExpiredLevelEntries(now time.Time) error {
	dir := filepath.Join(s.baseDir, string(routeKindLevels))
	entries, err := os.ReadDir(dir)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return nil
		}
		return err
	}

	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}

		path := filepath.Join(dir, entry.Name())
		data, readErr := os.ReadFile(path)
		if readErr != nil {
			continue
		}

		var cached cacheEntry
		if err := json.Unmarshal(data, &cached); err != nil {
			_ = os.Remove(path)
			continue
		}

		if !cached.ExpiresAt.IsZero() && !now.Before(cached.ExpiresAt) {
			_ = os.Remove(path)
		}
	}

	return nil
}

func (s *cacheStore) pathFor(kind routeKind, key string) string {
	sum := sha256.Sum256([]byte(key))
	filename := hex.EncodeToString(sum[:]) + ".json"
	return filepath.Join(s.baseDir, string(kind), filename)
}

type config struct {
	ListenAddr         string
	CacheDir           string
	UpstreamBase       string
	UserAgent          string
	Timeout            time.Duration
	ListTTLs           map[string]time.Duration
	SearchTTL          time.Duration
	LevelTTL           time.Duration
	LevelDataTTL       time.Duration

	// What the observer network is asked, before anything here serves. See
	// gate.go. An unusable setting is carried rather than fatal, so a
	// misconfigured proxy refuses every request with a reason in the log
	// instead of becoming a container that will not start.
	GateStores      string
	GateSuperMaxAge time.Duration
	GateSettingsErr string
}

func loadConfig() config {
	gate, gateErr := gateSettingsFrom(os.Getenv)

	return config{
		GateStores:         gate.GateStores,
		GateSuperMaxAge:    gate.GateSuperMaxAge,
		GateSettingsErr:    gateErr,
		ListenAddr:         envOr("PROXY_LISTEN_ADDR", ":8080"),
		CacheDir:           envOr("PROXY_CACHE_DIR", "/cache"),
		UpstreamBase:       strings.TrimRight(envOr("PROXY_UPSTREAM_BASE", "https://pr2hub.com"), "/"),
		UserAgent:          envOr("PROXY_USER_AGENT", "trapwork-pr2hub-proxy/1.0"),
		Timeout:            durationEnvOr("PROXY_TIMEOUT", 10*time.Second),
		ListTTLs: map[string]time.Duration{
			"campaign":  durationEnvOr("PROXY_CAMPAIGN_TTL", 30*time.Minute),
			"best":      durationEnvOr("PROXY_BEST_TTL", 15*time.Minute),
			"best_week": durationEnvOr("PROXY_BEST_WEEK_TTL", 3*time.Minute),
			"newest":    durationEnvOr("PROXY_NEWEST_TTL", 45*time.Second),
		},
		SearchTTL:    durationEnvOr("PROXY_SEARCH_TTL", 90*time.Second),
		LevelTTL:     durationEnvOr("PROXY_LEVEL_TTL", 24*time.Hour),
		LevelDataTTL: durationEnvOr("PROXY_LEVEL_DATA_TTL", 2*time.Minute),
	}
}

func envOr(key, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}
	return fallback
}

func durationEnvOr(key string, fallback time.Duration) time.Duration {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	dur, err := time.ParseDuration(value)
	if err != nil {
		return fallback
	}
	return dur
}

type proxyRoute struct {
	Kind          routeKind
	Method        string
	CacheKey      string
	CacheTTL      time.Duration
	AllowStale    bool
	UpstreamURL   string
	UpstreamBody  []byte
	ContentType   string
	RouteLabel    string
	NormalizedKey string
}

func buildRoute(r *http.Request, cfg config) (*proxyRoute, error) {
	switch {
	case strings.HasPrefix(r.URL.Path, "/files/lists/"):
		return buildListRoute(r, cfg)
	case r.URL.Path == "/search_levels.php":
		return buildSearchRoute(r, cfg)
	case strings.HasPrefix(r.URL.Path, "/levels/"):
		return buildLevelRoute(r, cfg)
	case r.URL.Path == "/level_data.php":
		return buildLevelDataRoute(r, cfg)
	default:
		return nil, newHTTPError(http.StatusNotFound, "unknown proxy route")
	}
}

func buildListRoute(r *http.Request, cfg config) (*proxyRoute, error) {
	if r.Method != http.MethodGet {
		return nil, newHTTPError(http.StatusMethodNotAllowed, "method not allowed")
	}

	trimmed := strings.Trim(strings.TrimPrefix(r.URL.Path, "/"), "/")
	parts := strings.Split(trimmed, "/")
	if len(parts) != 4 || parts[0] != "files" || parts[1] != "lists" {
		return nil, newHTTPError(http.StatusNotFound, "unknown list route")
	}

	mode := parts[2]
	page := parts[3]
	ttl, ok := cfg.ListTTLs[mode]
	if !ok {
		return nil, newHTTPError(http.StatusBadRequest, "invalid list mode")
	}

	if _, err := parsePositiveInt(page); err != nil {
		return nil, newHTTPError(http.StatusBadRequest, "invalid list page")
	}

	if err := validateAllowedQueryKeys(r.URL.Query(), "token", "rand"); err != nil {
		return nil, err
	}

	return &proxyRoute{
		Kind:          routeKindLists,
		Method:        http.MethodGet,
		CacheKey:      fmt.Sprintf("lists:%s:%s", mode, page),
		CacheTTL:      ttl,
		AllowStale:    true,
		UpstreamURL:   fmt.Sprintf("%s/files/lists/%s/%s", cfg.UpstreamBase, mode, page),
		RouteLabel:    "files/lists",
		NormalizedKey: fmt.Sprintf("%s/%s", mode, page),
	}, nil
}

func buildSearchRoute(r *http.Request, cfg config) (*proxyRoute, error) {
	if r.Method != http.MethodPost {
		return nil, newHTTPError(http.StatusMethodNotAllowed, "method not allowed")
	}

	if rawQuery := r.URL.RawQuery; rawQuery != "" {
		return nil, newHTTPError(http.StatusBadRequest, "unexpected query parameters")
	}

	body, err := readLimitedBody(r.Body, searchBodyLimit)
	if err != nil {
		return nil, err
	}

	values, err := url.ParseQuery(string(body))
	if err != nil {
		return nil, newHTTPError(http.StatusBadRequest, "invalid form body")
	}

	const (
		modeKey      = "mode"
		searchStrKey = "search_str"
		orderKey     = "order"
		dirKey       = "dir"
		pageKey      = "page"
		tokenKey     = "token"
		randKey      = "rand"
	)

	allowed := map[string]bool{
		modeKey:      true,
		searchStrKey: true,
		orderKey:     true,
		dirKey:       true,
		pageKey:      true,
		tokenKey:     true,
		randKey:      true,
	}

	normalized := url.Values{}
	for key, vals := range values {
		if !allowed[key] {
			return nil, newHTTPError(http.StatusBadRequest, "unexpected form field")
		}
		if len(vals) != 1 {
			return nil, newHTTPError(http.StatusBadRequest, "duplicate form field")
		}
		if key == tokenKey || key == randKey {
			continue
		}
		normalized.Set(key, vals[0])
	}

	if pageValue := normalized.Get(pageKey); pageValue != "" {
		if _, err := parsePositiveInt(pageValue); err != nil {
			return nil, newHTTPError(http.StatusBadRequest, "invalid search page")
		}
	}

	// Whatever survived the allowlist, and nothing else. There used to be a
	// fallback to the raw body here for the case where nothing survived, which
	// made the drop above conditional on the form carrying something other than
	// a token: a body of nothing but `token` fell through to the raw bytes, and
	// those bytes then became the upstream body and the cache key. An empty
	// search is a real request with no criteria, so the empty encoding is the
	// honest key for it, and every such request shares the one cache entry
	// because upstream they are the same request.
	encoded := normalized.Encode()

	return &proxyRoute{
		Kind:          routeKindSearch,
		Method:        http.MethodPost,
		CacheKey:      "search:" + encoded,
		CacheTTL:      cfg.SearchTTL,
		AllowStale:    true,
		UpstreamURL:   cfg.UpstreamBase + "/search_levels.php",
		UpstreamBody:  []byte(encoded),
		ContentType:   "application/x-www-form-urlencoded",
		RouteLabel:    "search_levels.php",
		NormalizedKey: encoded,
	}, nil
}

func buildLevelRoute(r *http.Request, cfg config) (*proxyRoute, error) {
	if r.Method != http.MethodGet {
		return nil, newHTTPError(http.StatusMethodNotAllowed, "method not allowed")
	}

	match := levelRoutePattern.FindStringSubmatch(r.URL.Path)
	if match == nil {
		return nil, newHTTPError(http.StatusNotFound, "unknown level route")
	}

	levelID := match[1]
	queryValues := r.URL.Query()
	if err := validateAllowedQueryKeys(queryValues, "version", "token", "rand"); err != nil {
		return nil, err
	}

	version := queryValues.Get("version")
	if !versionPattern.MatchString(version) {
		return nil, newHTTPError(http.StatusBadRequest, "invalid level version")
	}

	upstreamURL := fmt.Sprintf("%s/levels/%s.txt", cfg.UpstreamBase, levelID)
	if version != "" {
		query := url.Values{}
		query.Set("version", version)
		upstreamURL += "?" + query.Encode()
	}

	return &proxyRoute{
		Kind:          routeKindLevels,
		Method:        http.MethodGet,
		CacheKey:      fmt.Sprintf("levels:%s:%s", levelID, version),
		CacheTTL:      cfg.LevelTTL,
		AllowStale:    false,
		UpstreamURL:   upstreamURL,
		RouteLabel:    "levels",
		NormalizedKey: fmt.Sprintf("%s@%s", levelID, version),
	}, nil
}

func buildLevelDataRoute(r *http.Request, cfg config) (*proxyRoute, error) {
	if r.Method != http.MethodGet {
		return nil, newHTTPError(http.StatusMethodNotAllowed, "method not allowed")
	}

	query := r.URL.Query()
	if err := validateAllowedQueryKeys(query, "level_id", "token", "rand"); err != nil {
		return nil, err
	}

	levelID := query.Get("level_id")
	if _, err := parsePositiveInt(levelID); err != nil {
		return nil, newHTTPError(http.StatusBadRequest, "invalid level_id")
	}

	upstreamQuery := url.Values{}
	upstreamQuery.Set("level_id", levelID)

	return &proxyRoute{
		Kind:          routeKindLevelData,
		Method:        http.MethodGet,
		CacheKey:      "level_data:" + levelID,
		CacheTTL:      cfg.LevelDataTTL,
		AllowStale:    true,
		UpstreamURL:   cfg.UpstreamBase + "/level_data.php?" + upstreamQuery.Encode(),
		RouteLabel:    "level_data.php",
		NormalizedKey: levelID,
	}, nil
}

func parsePositiveInt(value string) (int, error) {
	if value == "" {
		return 0, errors.New("empty")
	}
	num, err := strconv.Atoi(value)
	if err != nil || num <= 0 {
		return 0, errors.New("invalid")
	}
	return num, nil
}

func validateAllowedQueryKeys(values url.Values, allowedKeys ...string) error {
	if len(values) == 0 {
		return nil
	}

	allowed := map[string]bool{}
	for _, key := range allowedKeys {
		allowed[key] = true
	}

	for key, vals := range values {
		if !allowed[key] {
			return newHTTPError(http.StatusBadRequest, "unexpected query parameters")
		}
		if len(vals) != 1 {
			return newHTTPError(http.StatusBadRequest, "duplicate query parameter")
		}
	}

	return nil
}

func readLimitedBody(body io.ReadCloser, limit int64) ([]byte, error) {
	defer body.Close()
	data, err := io.ReadAll(io.LimitReader(body, limit+1))
	if err != nil {
		return nil, newHTTPError(http.StatusBadRequest, "could not read request body")
	}
	if int64(len(data)) > limit {
		return nil, newHTTPError(http.StatusRequestEntityTooLarge, "request body too large")
	}
	return data, nil
}

type httpError struct {
	Status  int
	Message string
}

func newHTTPError(status int, message string) *httpError {
	return &httpError{Status: status, Message: message}
}

func (e *httpError) Error() string {
	return e.Message
}

type upstreamError struct {
	StatusCode int
	Body       []byte
	Err        error
}

func (e *upstreamError) Error() string {
	if e.Err != nil {
		return e.Err.Error()
	}
	if e.StatusCode > 0 {
		return fmt.Sprintf("upstream returned status %d", e.StatusCode)
	}
	return "upstream request failed"
}

func (e *upstreamError) Unwrap() error {
	return e.Err
}

func allowsStaleFallback(err error) bool {
	var upErr *upstreamError
	if errors.As(err, &upErr) {
		if upErr.StatusCode == http.StatusTooManyRequests || upErr.StatusCode >= 500 {
			return true
		}
		return upErr.StatusCode == 0 && upErr.Err != nil
	}
	return err != nil
}

type fetchResult struct {
	Entry          *cacheEntry
	UpstreamStatus int
}

type fetchGroup struct {
	mu    sync.Mutex
	calls map[string]*fetchCall
}

type fetchCall struct {
	wg     sync.WaitGroup
	result *fetchResult
	err    error
}

func newFetchGroup() *fetchGroup {
	return &fetchGroup{calls: make(map[string]*fetchCall)}
}

func (g *fetchGroup) Do(key string, fn func() (*fetchResult, error)) (*fetchResult, error) {
	g.mu.Lock()
	if call, ok := g.calls[key]; ok {
		g.mu.Unlock()
		call.wg.Wait()
		return call.result, call.err
	}

	call := &fetchCall{}
	call.wg.Add(1)
	g.calls[key] = call
	g.mu.Unlock()

	call.result, call.err = fn()
	call.wg.Done()

	g.mu.Lock()
	delete(g.calls, key)
	g.mu.Unlock()

	return call.result, call.err
}

type rateLimiter struct {
	mu      sync.Mutex
	windows map[string]*rateWindow
}

type rateWindow struct {
	start time.Time
	count int
}

type rateLimit struct {
	Window time.Duration
	Limit  int
}

func newRateLimiter() *rateLimiter {
	return &rateLimiter{windows: make(map[string]*rateWindow)}
}

func (l *rateLimiter) Allow(key string, cfg rateLimit, now time.Time) bool {
	l.mu.Lock()
	defer l.mu.Unlock()

	window, ok := l.windows[key]
	if !ok || now.Sub(window.start) >= cfg.Window {
		l.windows[key] = &rateWindow{start: now, count: 1}
		return true
	}

	if window.count >= cfg.Limit {
		return false
	}

	window.count++
	return true
}

type server struct {
	cfg         config
	cache       *cacheStore
	httpClient  *http.Client
	fetches     *fetchGroup
	rateLimiter *rateLimiter
}

func newServer(cfg config) *server {
	// No TLSClientConfig at all, so the upstream is verified the way Go
	// verifies by default. There used to be a PROXY_INSECURE_SKIP_VERIFY here
	// that turned that off from the environment -- a switch that disabled the
	// only thing establishing the upstream is PR2Hub, which is a switch an
	// attacker sets. Nothing in this deployment set it, and the verification
	// override reaches a dead address over plain HTTP, so nothing needed it.
	transport := &http.Transport{
		Proxy: http.ProxyFromEnvironment,
	}

	return &server{
		cfg:   cfg,
		cache: newCacheStore(cfg.CacheDir),
		httpClient: &http.Client{
			Timeout:   cfg.Timeout,
			Transport: transport,
		},
		fetches:     newFetchGroup(),
		rateLimiter: newRateLimiter(),
	}
}

func (s *server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	start := time.Now()

	// The observer network first, before anything else at all: before the
	// route is parsed and before a byte of the request body is read. A halted
	// deployment answers one way on every path here, and says nothing about
	// which path was asked for.
	//
	// There is no exemption. A liveness route used to sit above this, on the
	// reasoning that something must be able to tell a stopped proxy from a
	// dead one -- but Apache maps /pr2hub/ onto this proxy's whole path space
	// and the web tier publishes its ports, so it was reachable by anyone
	// rather than by a supervisor, and nothing in the deployment called it.
	// What it did was answer 200 during a halt while every other path answered
	// 503, which told a stranger exactly what both refusal paths are written
	// to withhold. Liveness, if it is wanted, belongs in a container
	// healthcheck that runs inside the container and needs no published path.
	if reason := s.gateReason(time.Now()); reason != "" {
		s.refuseForGate(w, r, reason, start)
		return
	}

	route, err := buildRoute(r, s.cfg)
	if err != nil {
		s.respondRouteError(w, r, start, route, err)
		return
	}

	if !s.allowRequest(clientIP(r), route, start) {
		http.Error(w, "rate limit exceeded", http.StatusTooManyRequests)
		s.logRequest(r, route, "REJECTED", http.StatusTooManyRequests, 0, start, nil)
		return
	}

	now := time.Now()
	cached, cachedFound, err := s.cache.Get(route.Kind, route.CacheKey)
	if err != nil {
		http.Error(w, "cache read failed", http.StatusInternalServerError)
		s.logRequest(r, route, "ERROR", http.StatusInternalServerError, 0, start, err)
		return
	}

	if cachedFound && cached.IsFresh(now) {
		s.writeCachedResponse(w, cached, "HIT", route.CacheKey, 0)
		s.logRequest(r, route, "HIT", cached.StatusCode, 0, start, nil)
		return
	}

	result, fetchErr := s.fetches.Do(route.CacheKey, func() (*fetchResult, error) {
		return s.fetchAndMaybeCache(route)
	})

	if fetchErr == nil {
		s.writeCachedResponse(w, result.Entry, "MISS", route.CacheKey, result.UpstreamStatus)
		s.logRequest(r, route, "MISS", result.Entry.StatusCode, result.UpstreamStatus, start, nil)
		return
	}

	if cachedFound && route.AllowStale && allowsStaleFallback(fetchErr) {
		upstreamStatus := extractUpstreamStatus(fetchErr)
		s.writeCachedResponse(w, cached, "STALE", route.CacheKey, upstreamStatus)
		s.logRequest(r, route, "STALE", cached.StatusCode, upstreamStatus, start, fetchErr)
		return
	}

	if cachedFound && !route.AllowStale {
		_ = s.cache.Delete(route.Kind, route.CacheKey)
	}

	s.respondUpstreamError(w, r, route, fetchErr, start)
}

func routeLabelFromErrRoute(route *proxyRoute) string {
	if route == nil {
		return "unknown"
	}
	return route.RouteLabel
}

// What the deployment is asked before it serves.
//
// Returns the empty string if the proxy may work, or a short reason if it may
// not. Settings that do not say what the window is are themselves a reason:
// there is no window to fall back on, and serving on an unstated one would be
// this code deciding how long it may run unobserved.
func (s *server) gateReason(now time.Time) string {
	if s.cfg.GateSettingsErr != "" {
		return s.cfg.GateSettingsErr
	}
	return gateReason(s.cfg, now)
}

// How a refusal reaches whoever asked.
//
// Byte for byte what config.php's gate returns, because it is not a similar
// refusal, it is the same refusal arriving through a different door: a client
// that learns what a stopped deployment looks like should not have to learn it
// twice. Whoever asked is told nothing; whoever runs it gets the reason in the
// log.
func (s *server) refuseForGate(w http.ResponseWriter, r *http.Request, reason string, start time.Time) {
	w.Header().Set("Content-Type", proxyContentType)
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Retry-After", "30")
	w.WriteHeader(http.StatusServiceUnavailable)
	_, _ = w.Write([]byte("The server is not running.\n"))

	log.Printf("observer gate: refusing to work. %s", reason)
	s.logRequest(r, nil, "HALTED", http.StatusServiceUnavailable, 0, start, nil)
}

func normalizedKeyFromRoute(route *proxyRoute) string {
	if route == nil {
		return ""
	}
	return route.NormalizedKey
}

func (s *server) allowRequest(ip string, route *proxyRoute, now time.Time) bool {
	var cfg rateLimit
	switch route.Kind {
	case routeKindSearch:
		cfg = rateLimit{Window: 30 * time.Second, Limit: 15}
	case routeKindLists:
		cfg = rateLimit{Window: 30 * time.Second, Limit: 30}
	case routeKindLevels:
		cfg = rateLimit{Window: 30 * time.Second, Limit: 30}
	case routeKindLevelData:
		cfg = rateLimit{Window: 30 * time.Second, Limit: 20}
	default:
		cfg = rateLimit{Window: 30 * time.Second, Limit: 20}
	}
	return s.rateLimiter.Allow(ip+":"+string(route.Kind), cfg, now)
}

// Who asked, as far as anything here can establish it.
//
// This proxy publishes no port and sits on an internal network, so the only
// route to it is Apache -- and mod_proxy *appends* the address it saw to
// whatever X-Forwarded-For arrived with the request. The last element is
// therefore Apache's own observation, and every element before it is the
// client's to write.
//
// Reading the first element let a stranger name themselves. That name is the
// rate limiter's key and the log's attribution, so a fresh name on every
// request was a fresh allowance on every request, and a record of whatever it
// was told. Reading the last element makes forging it require already being
// inside the network rather than merely being able to set a header.
//
// X-Real-IP is not read at all. Nothing in this deployment sets it, so a value
// found in it came from the client and from nobody else.
func clientIP(r *http.Request) string {
	if forwarded := r.Header.Get("X-Forwarded-For"); forwarded != "" {
		parts := strings.Split(forwarded, ",")
		last := strings.TrimSpace(parts[len(parts)-1])
		// An address, or it is not evidence of anything.
		if net.ParseIP(last) != nil {
			return last
		}
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err == nil && host != "" {
		return host
	}
	return r.RemoteAddr
}

func (s *server) fetchAndMaybeCache(route *proxyRoute) (*fetchResult, error) {
	req, err := http.NewRequest(route.Method, route.UpstreamURL, strings.NewReader(string(route.UpstreamBody)))
	if err != nil {
		return nil, &upstreamError{Err: err}
	}

	req.Header.Set("User-Agent", s.cfg.UserAgent)
	if route.ContentType != "" {
		req.Header.Set("Content-Type", route.ContentType)
	}

	resp, err := s.httpClient.Do(req)
	if err != nil {
		return nil, &upstreamError{Err: err}
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, &upstreamError{StatusCode: resp.StatusCode, Err: err}
	}

	if resp.StatusCode != http.StatusOK {
		return nil, &upstreamError{
			StatusCode: resp.StatusCode,
			Body:       body,
		}
	}

	now := time.Now().UTC()
	entry := &cacheEntry{
		StatusCode: resp.StatusCode,
		BodyBase64: base64.StdEncoding.EncodeToString(body),
		FetchedAt:   now,
		ExpiresAt:   now.Add(route.CacheTTL),
	}
	if err := s.cache.Save(route.Kind, route.CacheKey, entry); err != nil {
		return nil, err
	}

	return &fetchResult{
		Entry:          entry,
		UpstreamStatus: resp.StatusCode,
	}, nil
}

func (s *server) writeCachedResponse(w http.ResponseWriter, entry *cacheEntry, cacheStatus, cacheKey string, upstreamStatus int) {
	body, err := entry.Body()
	if err != nil {
		http.Error(w, "cached body decode failed", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", proxyContentType)
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("X-Proxy-Cache", cacheStatus)
	w.Header().Set("X-Proxy-Cache-Key", shortHash(cacheKey))
	if upstreamStatus > 0 {
		w.Header().Set("X-Proxy-Upstream-Status", strconv.Itoa(upstreamStatus))
	}
	w.WriteHeader(entry.StatusCode)
	_, _ = w.Write(body)
}

func shortHash(value string) string {
	sum := sha256.Sum256([]byte(value))
	return hex.EncodeToString(sum[:8])
}

func extractUpstreamStatus(err error) int {
	var upErr *upstreamError
	if errors.As(err, &upErr) {
		return upErr.StatusCode
	}
	return 0
}

func (s *server) respondRouteError(w http.ResponseWriter, r *http.Request, start time.Time, route *proxyRoute, err error) {
	var httpErr *httpError
	if errors.As(err, &httpErr) {
		http.Error(w, httpErr.Message, httpErr.Status)
		s.logRequest(r, route, "REJECTED", httpErr.Status, 0, start, err)
		return
	}
	http.Error(w, "request rejected", http.StatusBadRequest)
	s.logRequest(r, route, "REJECTED", http.StatusBadRequest, 0, start, err)
}

func (s *server) respondUpstreamError(w http.ResponseWriter, r *http.Request, route *proxyRoute, err error, start time.Time) {
	var upErr *upstreamError
	if errors.As(err, &upErr) {
		status := upErr.StatusCode
		if status == 0 {
			status = http.StatusBadGateway
		}

		w.Header().Set("Content-Type", proxyContentType)
		w.Header().Set("X-Content-Type-Options", "nosniff")
		w.Header().Set("X-Proxy-Cache", "MISS")
		if upErr.StatusCode > 0 {
			w.Header().Set("X-Proxy-Upstream-Status", strconv.Itoa(upErr.StatusCode))
		}
		w.WriteHeader(status)
		if len(upErr.Body) > 0 {
			_, _ = w.Write(upErr.Body)
		} else if upErr.Err != nil {
			_, _ = w.Write([]byte(upErr.Err.Error()))
		} else {
			_, _ = w.Write([]byte("upstream request failed"))
		}

		s.logRequest(r, route, "ERROR", status, upErr.StatusCode, start, upErr.Err)
		return
	}

	http.Error(w, "upstream request failed", http.StatusBadGateway)
	s.logRequest(r, route, "ERROR", http.StatusBadGateway, 0, start, err)
}

func (s *server) logRequest(r *http.Request, route *proxyRoute, cacheStatus string, statusCode int, upstreamStatus int, start time.Time, reqErr error) {
	// The path and the normalised key, never the request as it arrived.
	//
	// Every GET route here allows a `token` query parameter, and `token` is the
	// session credential this package sets at login. Logging the request URI
	// wrote a live one into the container log on every request -- the same
	// disclosure the route builders are careful to keep out of the upstream
	// body and the cache key, arriving at a different destination.
	//
	// What replaces it is the value the route builder already produced: the
	// normalised key holds exactly the fields that survived the allowlist, so
	// it cannot carry a credential without the upstream body carrying one too.
	// The path is quoted because it is percent-decoded by the time it gets
	// here, and an encoded newline in it would otherwise let a stranger write
	// lines of their own into the log.
	duration := time.Since(start).Milliseconds()
	line := fmt.Sprintf(
		"%s %q key=%q route=%s cache=%s status=%d upstream=%d ip=%s duration_ms=%d",
		r.Method,
		r.URL.Path,
		normalizedKeyFromRoute(route),
		routeLabelFromErrRoute(route),
		cacheStatus,
		statusCode,
		upstreamStatus,
		clientIP(r),
		duration,
	)
	if reqErr != nil {
		line += fmt.Sprintf(" err=%q", reqErr.Error())
	}
	log.Print(line)
}

func startJanitor(ctx context.Context, store *cacheStore) {
	ticker := time.NewTicker(30 * time.Minute)
	go func() {
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if err := store.DeleteExpiredLevelEntries(time.Now().UTC()); err != nil {
					log.Printf("level cache cleanup failed: %v", err)
				}
			}
		}
	}()
}

func main() {
	cfg := loadConfig()
	if err := os.MkdirAll(cfg.CacheDir, 0o755); err != nil {
		log.Fatalf("create cache dir: %v", err)
	}

	srv := newServer(cfg)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	startJanitor(ctx, srv.cache)

	log.Printf("starting PR2Hub proxy on %s (upstream=%s)", cfg.ListenAddr, cfg.UpstreamBase)
	if err := http.ListenAndServe(cfg.ListenAddr, srv); err != nil {
		log.Fatal(err)
	}
}

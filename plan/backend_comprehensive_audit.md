# Backend Comprehensive Audit — GET `/api/v1/logistik/senjata` 404

**Date**: 2026-08-05
**Auditor**: Senior Backend Auditor (DEBUG MODE)
**Scope**: Route config, Controller, CORS, CI3 Router core

---

## 1. Route Status

### 1.1 Current Senjata Routes (lines 84–88)

```php
84: $route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';
85: $route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';
86: $route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
87: $route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';
88: $route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';
```

### 1.2 PHP Array Structure After Parsing

Two **separate** top-level keys in `$route`:

```php
$route = [
    'api/v1/logistik/senjata' => [
        'POST'    => 'logistik/senjata_post',
        'GET'     => 'logistik/senjata_get',
        'OPTIONS' => 'logistik/senjata_options',
    ],
    'api/v1/logistik/senjata/(:any)' => [
        'PUT'     => 'logistik/senjata_put/$1',
        'OPTIONS' => 'logistik/senjata_options',
    ],
];
```

**No key collision.** The strings `api/v1/logistik/senjata` and `api/v1/logistik/senjata/(:any)` are different PHP array keys. No overwrite.

### 1.3 CI3 Router Execution Trace (GET request to `/api/v1/logistik/senjata`)

Tracing `system/core/Router.php → _parse_routes()`:

| Step | Variable | Value |
|------|----------|-------|
| URI | `$uri` | `api/v1/logistik/senjata` (4 segments) |
| Verb | `$http_verb` | `get` |
| Iter 1 | `$key` = `api/v1/logistik/senjata` | Value is array → `array_change_key_case()` → `isset($val['get'])` = **TRUE** |
| | `$val` resolved | `logistik/senjata_get` |
| | `$key` regex | `#^api/v1/logistik/senjata$#` (no wildcards, exact match) |
| | `preg_match()` | **MATCHES** `api/v1/logistik/senjata` ✅ |
| | `_set_request()` | `['logistik', 'senjata_get']` → class=`Logistik`, method=`senjata_get` |
| | | → **RETURN** (route found) |

**The wildcard route `api/v1/logistik/senjata/(:any)` is NEVER evaluated for GET requests** because route 1 already matched and returned. Even if iterated first, the wildcard route's verb check (`isset($val['get'])`) fails → `continue`.

### 1.4 Route Order Analysis

CI3 iterates `$this->routes` in PHP insertion order (guaranteed in PHP 7+). The exact-match route is defined at line 84-85 BEFORE the wildcard PUT route at line 86. Even if order were reversed, the verb-based gate prevents false matches.

### 1.5 Verdict: **NO ROUTE CONFLICT**

---

## 2. Controller Status

### 2.1 `__construct()` — CORS Preflight

```php
Lines 6-23:
 8:  header("Access-Control-Allow-Origin: *");
 9:  header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
10:  header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Authorization");
11:  header("Access-Control-Allow-Credentials: false");
12:  if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
13:      http_response_code(200);
14:      exit();
15:  }
```

- CORS headers set at lines 8–11 (BEFORE any logic) ✅
- OPTIONS interceptor at line 12–14 (BEFORE token validation, session, JWT loading) ✅
- For GET requests, OPTIONS check passes through → normal flow continues ✅

### 2.2 `senjata_get()` Integrity Check

- Method exists at **line 169** ✅
- Properly opened with `{` at line 170, closed with `}` at line 234 ✅
- Not nested inside any other method or block ✅
- Not accidentally deleted or modified ✅
- Called via route resolution: `logistik/senjata_get` → `Logistik::senjata_get()` ✅

### 2.3 `senjata_put()` — No Interference

- Separate method starting at line 243
- Has its own JWT auth, content-type validation, and DB logic
- No shared mutable state, no side effects on GET
- Method signature: `public function senjata_put($senjata_id)` — parameter passed by CI3 route `$1`

### 2.4 Minor Finding: Dead OPTIONS Route

Lines 87–88 route OPTIONS to `logistik/senjata_options`, but no `senjata_options()` method exists in the controller. **Harmless** because `__construct()` calls `exit()` on OPTIONS before routing resolves — the dispatcher never reaches the method call.

### 2.5 Verdict: **CONTROLLER IS INTACT**

---

## 3. Supporting Infrastructure Audit

| Component | Status | Notes |
|-----------|--------|-------|
| `.htaccess` | ✅ Normal | Standard CI3 rewrite, Authorization header passthrough |
| `config/routes.php` | ✅ Correct | No syntax errors, no route conflicts |
| `config/hooks.php` | ✅ Empty | No hooks enabled |
| `config/autoload.php` | ✅ Standard | database, email, session, url, file |
| `config/config.php` | ✅ Standard | `enable_query_strings=FALSE`, `uri_protocol=REQUEST_URI`, `index_page=''` |
| Environment routes | ✅ None | `application/config/{ENV}/routes.php` does not exist |
| `system/core/Router.php` | ✅ Stock CI3 | Version 3.x (2019–2022), verb-based routing correct |

---

## 4. Hypothesis

### 4.1 What We Ruled Out

- ❌ Route collision between exact and wildcard keys
- ❌ CI3 verb-based routing mis-match
- ❌ `senjata_get()` deleted or corrupted
- ❌ CORS preflight interfering with GET
- ❌ Config or hook-level interference

### 4.2 The Backend's Role

**The backend routing and controller code is structurally correct.** For a GET request to `/api/v1/logistik/senjata`, the CI3 Router resolves:

```
URI: api/v1/logistik/senjata
→ Route[api/v1/logistik/senjata][GET] → logistik/senjata_get
→ Controller: Logistik, Method: senjata_get
→ 200 with JSON inventory list
```

No backend routing logic produces a 404 for this path.

### 4.3 Most Likely Root Causes of the 404

| # | Hypothesis | Likelihood | Diagnostic |
|---|------------|------------|------------|
| 1 | **Client (Flutter) sending wrong URL** — e.g. `senjata/` with trailing slash, or `senjata/{id}` with an ID (there is no GET `senjata/(:any)` route) | High | Inspect the raw HTTP request from Flutter (URL, method, headers) |
| 2 | **Apache/Nginx not restarted** after routes.php changed; old route cache in effect | Medium | Restart web server; verify `routes.php` modification time |
| 3 | **PHP opcache** serving stale compiled routes.php | Medium | `opcache_reset()` or restart PHP-FPM |
| 4 | **CI3 `uri_protocol` mismatch** — REQUEST_URI vs PATH_INFO on some server configs can strip segments | Low | Try `PATH_INFO` or `AUTO`; check `$_SERVER['REQUEST_URI']` |
| 5 | **`base_url` subfolder** — config has `http://localhost:8080/sindomon_api` but `.htaccess` uses `RewriteBase /`; mismatch could cause URI parsing issues if the web server docroot isn't the project root | Low | Verify server document root; ensure `/sindomon_api` alias or vhost is correct |

### 4.4 Recommended Next Step (Diagnostic)

1. Enable CI3 logging: set `$config['log_threshold'] = 4;` in `config.php`
2. Add temporary debug to `Router.php` `_parse_routes()` to log `$uri`, `$http_verb`, and each route key evaluated
3. Capture the **exact** HTTP request from Flutter (URL, method, headers, body) via curl or browser dev tools
4. Verify the 404 response comes from CI3's own 404 mechanism (check response body for CI3 404 page) vs Apache/nginx 404 (generic server page)

---

## 5. Conclusion

**Backend status: CLEAN.** No routing conflict, no controller corruption, no configuration error. The GET route `api/v1/logistik/senjata` resolves correctly to `Logistik::senjata_get()`. The PUT route addition did not overwrite, shadow, or interfere with the GET route in any way verified by the CI3 source code audit.

The 404 is most likely either a **client-side request error** (wrong URL/method sent from Flutter) or a **server-side caching issue** (stale opcache or web server not restarted).

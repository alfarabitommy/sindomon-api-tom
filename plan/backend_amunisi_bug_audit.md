# Backend Amunisi OPTIONS 404 — Bug Audit

**Date:** 2025-07-15  
**Auditor:** CI3 Backend Auditor (DEBUG MODE)  
**Trigger:** Flutter app `OPTIONS /api/v1/logistik/amunisi?` → `404 Not Found`

---

## 1. Route & Method Verification

### 1.1 Route Definition (`application/config/routes.php`)

| Check | Status | Detail |
|-------|--------|--------|
| `$route['api/v1/logistik/amunisi']['OPTIONS']` | ✅ **EXISTS** | Line 93: `= 'logistik/amunisi_options'` |
| `$route['api/v1/logistik/amunisi']['POST']` | ✅ EXISTS | Line 91: `= 'logistik/amunisi_post'` |
| `$route['api/v1/logistik/amunisi']['GET']` | ✅ EXISTS | Line 92: `= 'logistik/amunisi_get'` |
| `$route['api/v1/logistik/amunisi/(:any)']['OPTIONS']` | ❌ **MISSING** | No wildcard OPTIONS route for amunisi |

**Comparison with `senjata`** (fully covered):

```php
// routes.php lines 89-90 — senjata has BOTH:
$route['api/v1/logistik/senjata']['OPTIONS']        = 'logistik/senjata_options';   // exact
$route['api/v1/logistik/senjata/(:any)']['OPTIONS']  = 'logistik/senjata_options';   // wildcard

// routes.php line 93 — amunisi only has exact:
$route['api/v1/logistik/amunisi']['OPTIONS']         = 'logistik/amunisi_options';   // exact only
// ❌ NO (:any) wildcard OPTIONS route for amunisi
```

**Verdict:** The exact-match OPTIONS route IS present. An `OPTIONS /api/v1/logistik/amunisi` (exact) should match. However, any OPTIONS request to a sub-resource like `/api/v1/logistik/amunisi/123` would NOT have a matching OPTIONS route.

### 1.2 Controller Method (`application/controllers/Logistik.php`)

| Check | Status | Detail |
|-------|--------|--------|
| `amunisi_options()` method exists | ✅ **EXISTS** | Lines 764-767 |
| Method signature accepts params | N/A | No params needed (exact route match) |
| Method returns 200 | ✅ | `http_response_code(200); exit;` |

**Controller method** (lines 757-767):

```php
/**
 * OPTIONS /api/v1/logistik/amunisi
 *
 * CORS preflight. Route exists so CI3 passes the pre-dispatch
 * method_exists() gate and instantiates the controller, letting
 * __construct() emit CORS headers.
 */
public function amunisi_options() {
    http_response_code(200);
    exit;
}
```

**Verdict:** The method IS present. The method signature has zero required parameters, matching the exact route `logistik/amunisi_options` with no back-reference.

### 1.3 Constructor-Level OPTIONS Catch (Defense-in-Depth)

`Logistik::__construct()` (lines 12-14) also catches ALL OPTIONS requests:

```php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

This means **if the controller is instantiated at all**, any OPTIONS request returns 200 before reaching the routed method — even if no explicit OPTIONS route existed (e.g., if `_parse_routes()` falls through to `_set_request` with the default segments).

---

## 2. CI3 Routing Analysis: Trailing `?` in URI

### 2.1 The Request

```
OPTIONS /api/v1/logistik/amunisi? HTTP/1.1
```

The trailing `?` with an empty query string.

### 2.2 CI3 URI Parsing Flow

```
$_SERVER['REQUEST_URI'] = "/api/v1/logistik/amunisi?"
        │
        ▼
URI::_parse_request_uri()           [system/core/URI.php:207]
  parse_url('http://dummy/api/v1/logistik/amunisi?')
    → path:  "/api/v1/logistik/amunisi"
    → query: ""  (empty string)
        │
        ▼
URI::_set_uri_string("/api/v1/logistik/amunisi")
  trim(..., '/') → "api/v1/logistik/amunisi"
  segments = ['api', 'v1', 'logistik', 'amunisi']
        │
        ▼
Router::_parse_routes()             [system/core/Router.php:371]
  $uri = "api/v1/logistik/amunisi"
  $http_verb = "options"
        │
        ▼
Route key "api/v1/logistik/amunisi" → array with 'OPTIONS' key exists
  $val['options'] = 'logistik/amunisi_options'
        │
        ▼
preg_match('#^api/v1/logistik/amunisi$#', "api/v1/logistik/amunisi")
  → MATCH ✅
        │
        ▼
_set_request(['logistik', 'amunisi_options'])
  → class='logistik', method='amunisi_options'
```

### 2.3 Key Configuration

| Config | Value | File:Line |
|--------|-------|-----------|
| `uri_protocol` | `REQUEST_URI` | `config.php:55` |
| `enable_query_strings` | `FALSE` | `config.php:187` |
| `permitted_uri_chars` | `a-z 0-9~%.:_\-` | `config.php:163` |

- `uri_protocol = 'REQUEST_URI'` ensures CI3 uses `_parse_request_uri()` (not `_parse_query_string()` or `PATH_INFO`).
- `enable_query_strings = FALSE` ensures segment-based routing is active — routes in `routes.php` are matched against URI segments.
- `permitted_uri_chars` does NOT include `?` — **this is irrelevant** because `parse_url()` strips the `?` before `filter_uri()` ever sees the segments.

### 2.4 Conclusion: Trailing `?` Should NOT Cause 404

CI3's `parse_url('http://dummy' . $_SERVER['REQUEST_URI'])` on line 207 of `URI.php` correctly decomposes the URL into path and query components. A bare `?` at the end produces an empty query string and the path `/api/v1/logistik/amunisi`. The URI segments are clean: `['api', 'v1', 'logistik', 'amunisi']`. The regex `#^api/v1/logistik/amunisi$#` matches exactly.

**The trailing `?` is NOT the root cause of the 404.**

---

## 3. Potential Alternative Causes

If the 404 persists despite the route and method being present, investigate:

### 3.1 Trailing Slash Before `?`

If the actual URL is `/api/v1/logistik/amunisi/?` (trailing slash before `?`), the URI becomes `api/v1/logistik/amunisi/` which does NOT match the route `api/v1/logistik/amunisi` (no trailing slash).

- **Test:** `curl -X OPTIONS http://localhost:8080/api/v1/logistik/amunisi?` vs `curl -X OPTIONS http://localhost:8080/api/v1/logistik/amunisi/?`

### 3.2 Flutter Dio Client URL Construction

Flutter's Dio HTTP client may be appending query parameters to the URL. If the URL is constructed as:
```dart
Uri.parse('$baseUrl/api/v1/logistik/amunisi?')
```
Dio might URL-encode the `?` or treat it differently. The browser's CORS preflight would use the exact URL, including any query parameters.

### 3.3 Sub-resource OPTIONS (Missing Wildcard Route)

If the Flutter app requests OPTIONS for `/api/v1/logistik/amunisi/123` (with an ID segment), there is NO matching route (unlike senjata which has `(:any)` covered). This would fall through to `_set_request(['api','v1','logistik','amunisi','123'])` → class=`Api`, method=not found → 404.

**Fix:** Add a wildcard OPTIONS route for amunisi (see §4).

### 3.4 PHP Built-in Server Behavior

With `php -S localhost:8080 tests/router.php`, the test router does:
```php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
```
This also correctly strips `?`. No issue here.

---

## 4. Recommendations

### 4.1 (Recommended) Add Wildcard OPTIONS Route

For consistency with `senjata` and defense-in-depth, add:

```php
// In application/config/routes.php, after line 93:
$route['api/v1/logistik/amunisi/(:any)']['OPTIONS'] = 'logistik/amunisi_options';
```

Then update the controller method to accept an optional parameter:

```php
// In application/controllers/Logistik.php, line 764:
public function amunisi_options($id = null) {
    http_response_code(200);
    exit;
}
```

### 4.2 (Optional) Add Wildcard to Constructor CORS Catch

The `__construct()` already catches ALL OPTIONS methods if the controller is instantiated. But for sub-resource routes where no route exists at all, the controller is never loaded. Adding the wildcard route (4.1) is the safer fix.

### 4.3 Verify with curl

```bash
# Test exact route
curl -v -X OPTIONS http://localhost:8080/api/v1/logistik/amunisi

# Test trailing ?
curl -v -X OPTIONS "http://localhost:8080/api/v1/logistik/amunisi?"

# Test trailing slash + ?
curl -v -X OPTIONS "http://localhost:8080/api/v1/logistik/amunisi/?"

# Test sub-resource (should currently 404)
curl -v -X OPTIONS http://localhost:8080/api/v1/logistik/amunisi/123
```

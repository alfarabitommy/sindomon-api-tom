# Backend Amunisi DELETE Verification

**Date**: 2026-08-06
**Target**: `DELETE /api/v1/logistik/amunisi/32`
**Symptom**: Returns 404 HTML (CI3 default 404 page), not routed to controller.

---

## 1. Routes Extract

File: `application/config/routes.php`, lines 91–95:

```php
$route['api/v1/logistik/amunisi']['POST'] = 'logistik/amunisi_post';             // line 91
$route['api/v1/logistik/amunisi']['GET'] = 'logistik/amunisi_get';               // line 92
$route['api/v1/logistik/amunisi']['OPTIONS'] = 'logistik/amunisi_options';       // line 93
$route['api/v1/logistik/amunisi/(:any)']['OPTIONS'] = 'logistik/amunisi_options'; // line 94
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1'; // line 95
```

For comparison, working `senjata` DELETE (line 88):

```php
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
```

**Verdict**: Route syntax is identical to the working `senjata_delete` pattern. No typo. Correct HTTP verb key (`DELETE`). Correct controller path (`logistik`). Correct method name (`amunisi_delete`). Correct backreference (`$1`).

---

## 2. Method Extract

File: `application/controllers/Logistik.php`, line 580:

```php
/**
 * DELETE /api/v1/logistik/amunisi/(:any)
 *
 * Hapus batch amunisi. ID dibaca dari URL segment.
 * Auth: JWT (polda_id untuk jurisdiksi)
 */
public function amunisi_delete($batch_id)
```

Working counterpart at line 750:

```php
public function senjata_delete($senjata_id)
```

**Verdict**: Method exists. Signature matches route definition. Same pattern as `senjata_delete`. No typo in method name (`amunisi_delete`).

---

## 3. Root Cause Analysis

### 3.1 PHP Route Simulation — PASSED

A PHP script replicating CI3's `_parse_routes` logic confirmed:

```
Route array after merge (lines 94+95):
  ["api/v1/logistik/amunisi/(:any)"] => [
    "OPTIONS" => "logistik/amunisi_options",
    "DELETE"  => "logistik/amunisi_delete/$1"
  ]

Checking DELETE against URI "api/v1/logistik/amunisi/32":
  verb lookup: logistik/amunisi_delete/$1
  regex: #^api/v1/logistik/amunisi/([^/]+)$#
  MATCH! → segments: ["logistik", "amunisi_delete", "32"]
```

The route **does** resolve correctly in isolation.

### 3.2 CI3 Router Logic — VERIFIED

- `CI_Router::_set_routing()` loads `routes.php` → sets `$this->routes` (line 177)
- `CI_Router::_parse_routes()` iterates routes, handles HTTP verb arrays via `array_change_key_case` (line 385), wildcard-to-regex conversion (line 397), and backreference replacement (lines 412-414)
- No environment-specific `routes.php` override exists (glob returned empty)
- No `_remap` method in Logistik controller
- No route cache files found

### 3.3 `404_override` — CONFIRMS ROUTER BYPASS

```php
// line 118 of routes.php
$route['404_override'] = '';
```

CI3 uses its **built-in HTML 404** when no route matches. The caller receives an HTML 404 — NOT the JSON 404 that `amunisi_delete()` would emit at line 603. This proves **the Router never dispatched to the controller method**.

### 3.4 Local Code is Correct — Live Environment is Suspect

The route and method are correctly defined in the local codebase. The 404 must originate from the **live server environment**, not from the application code.

**Likely causes (in priority order)**:

| # | Cause | Evidence |
|---|-------|----------|
| 1 | **Stale deployment** — `routes.php` or `Logistik.php` not synced to live server | Code works locally; 404 means route never matched |
| 2 | **PHP OPcache not flushed** — cached bytecode from before the fix is still served | CI3 does not cache routes; OPcache would serve old `routes.php` |
| 3 | **Server-level DELETE block** — nginx/Apache reverse proxy is stripping or rejecting DELETE method before it reaches CI3 | Some reverse proxies default to allowing only GET/POST/HEAD |
| 4 | **`$_SERVER['REQUEST_METHOD']` mismatch** — FastCGI/PHP-FPM configuration may not populate `REQUEST_METHOD` correctly for DELETE | Rare but possible with misconfigured PHP-FPM pools |

### 3.5 Diagnostic Commands for Live Server

Run these on the live server to isolate the issue:

```bash
# 1. Verify deployed routes.php matches local
md5sum application/config/routes.php
# Compare against local: md5sum application/config/routes.php

# 2. Verify deployed Logistik.php matches local
md5sum application/controllers/Logistik.php
# Compare against local: md5sum application/controllers/Logistik.php

# 3. Check if PHP OPcache has stale file
php -r "var_dump(opcache_get_status());" | grep -A5 routes.php

# 4. Test DELETE directly, bypassing any reverse proxy
curl -v -X DELETE "http://127.0.0.1:PORT/api/v1/logistik/amunisi/32"

# 5. Dump $_SERVER['REQUEST_METHOD'] — add to routes.php temporarily:
# file_put_contents('/tmp/ci3_debug.log', 
#   date('c') . ' REQUEST_METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? 'NOT_SET') . PHP_EOL, 
#   FILE_APPEND);
```

---

## Summary

| Item | Status |
|------|--------|
| Route definition (`routes.php:95`) | ✅ Correct |
| Method existence (`Logistik.php:580`) | ✅ Correct |
| Method signature match | ✅ Correct |
| PHP route simulation | ✅ Passes |
| Environment-specific override | ✅ None |
| CI3 Router logic | ✅ Verified correct |
| **Live server deployment** | ❌ **SUSPECT** |

The code is not the problem. The deployment pipeline or server configuration is.

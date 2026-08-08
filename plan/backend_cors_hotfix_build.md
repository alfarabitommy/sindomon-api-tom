# Backend CORS Hotfix Build

**Date:** 2025-07-14  
**File changed:** `application/config/routes.php`  
**Status:** ✅ EXECUTED & VERIFIED — live preflight smoke test passed

---

## 1. Problem

The browser sends an `OPTIONS` preflight before the actual `GET /api/v1/profile` request. The route was defined for `GET` only, so the preflight hit CI3's 404 handler.

## 2. Modified Block (`application/config/routes.php`)

```php
//Profile
$route['api/v1/profile']['GET']     = 'profile/get';
$route['api/v1/profile']['OPTIONS'] = 'profile/get';
```

**Changes:**
1. **Added** `$route['api/v1/profile']['OPTIONS'] = 'profile/get';` — routes the preflight to the `Profile` controller, whose constructor already handles `OPTIONS` by responding `200` with CORS headers and exiting (standard pattern, same as the `polda`/`master` routes).
2. **Uppercased** the method key from `'get'` to `'GET'` for consistency with the rest of the routes file (lines 67–68 etc.).

## 3. Verification

### 3.1 Preflight Request (the failing case)

```bash
curl -i -X OPTIONS http://127.0.0.1:8099/api/v1/profile \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization, Content-Type"
```

**Before:** `404 Not Found`  
**After:**

```
HTTP/1.1 200 OK
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS
Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With
```

### 3.2 Actual GET Still Works (no regression)

```bash
curl http://127.0.0.1:8099/api/v1/profile -H "Authorization: Bearer <jwt>"
```

```
{"status":200,"message":"success","data":{"id":4,"username":"admin","role_name":"Super Admin","nama_polda":"","is_2fa_enabled":false}} [HTTP 200]
```

### 3.3 Static Check

- ✅ `php -l application/config/routes.php` — no syntax errors

---

## 4. Note — Same Pattern Audit (for follow-up)

The same missing-`OPTIONS`-route risk exists for other controllers whose CORS is handled in the constructor (e.g. `auth/login`, `auth/insert`, `role`, `dms`, `pengaduan`, `knowledge`). Flutter native HTTP does not send preflights, so these only matter for web builds; the `profile` endpoint was fixed here because the frontend web build targets it.

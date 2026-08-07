# SDM Module CORS Audit Report

**Date:** 2025-07-16  
**Symptom:** Flutter app gets `Failed to fetch` / `404 Not Found` on `OPTIONS /api/v1/sdm/personil`  
**Root Cause:** Missing `['OPTIONS']` route entries in `application/config/routes.php` for the entire SDM module.

---

## 1. Route Status

All SDM routes currently defined (lines 109–115 of `routes.php`):

| Route | Method | Defined? | OPTIONS? |
|-------|--------|----------|----------|
| `api/v1/sdm/org-tree` | GET | ✅ | ❌ **MISSING** |
| `api/v1/sdm/personil` | GET | ✅ | ❌ **MISSING** |
| `api/v1/sdm/personil` | POST | ✅ | ❌ **MISSING** |
| `api/v1/sdm/personil/(:any)` | PUT | ✅ | ❌ **MISSING** |
| `api/v1/sdm/personil/(:any)` | DELETE | ✅ | ❌ **MISSING** |
| `api/v1/sdm/hukum` | POST | ✅ | ❌ **MISSING** |

**Summary: 0 out of 6 SDM endpoints have an OPTIONS route.** This contrasts with the Logistik module, where every endpoint has a corresponding `['OPTIONS']` route (e.g. `logistik/senjata_options`, `logistik/amunisi_options`, etc.).

Since CI3's router never dispatches an unmatched HTTP method to the controller, the `__construct()` OPTIONS short-circuit in `Sdm.php` is **never reached** — the router returns 404 before the controller even loads.

---

## 2. Controller Status

**File:** `application/controllers/Sdm.php` (744 lines)

### Constructor — OPTIONS short-circuit: ✅ PRESENT

```php
// Lines 14-17
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

This is identical to the Logistik controller pattern. No code changes needed in the controller.

### Dedicated `_options` methods: ❌ NOT PRESENT (and NOT NEEDED)

Because the constructor already handles ALL OPTIONS requests with a `200` exit, no dedicated `personil_options()`, `org_tree_options()`, or `hukum_options()` methods are required. The route entries can point to **any** existing public method — the constructor short-circuits before the method body executes.

Pattern used by `polda` (line 68 of routes.php):
```php
$route['api/v1/polda']['OPTIONS'] = 'polda/get';  // reuses GET method, safe because __construct exits first
```

---

## 3. Action Plan

### Single file to edit: `application/config/routes.php`

**Insert the following block** immediately after the existing SDM section (after line 115: `$route['api/v1/sdm/hukum']['POST'] = 'sdm/hukum_post';`):

```php
// CORS preflight - SDM
$route['api/v1/sdm/org-tree']['OPTIONS']          = 'sdm/org_tree_get';
$route['api/v1/sdm/personil']['OPTIONS']           = 'sdm/personil_get';
$route['api/v1/sdm/personil/(:any)']['OPTIONS']    = 'sdm/personil_get/$1';
$route['api/v1/sdm/hukum']['OPTIONS']              = 'sdm/hukum_post';
```

**Rationale for each route:**

| Route entry | Points to | Why |
|-------------|-----------|-----|
| `org-tree OPTIONS` | `sdm/org_tree_get` | Only GET exists; constructor exits before DB query |
| `personil OPTIONS` (base) | `sdm/personil_get` | Constructor exits before auth/DB logic |
| `personil/(:any) OPTIONS` | `sdm/personil_get/$1` | Covers PUT + DELETE preflight on `/personil/123`; the `$1` param is ignored since constructor exits |
| `hukum OPTIONS` | `sdm/hukum_post` | Only POST exists; constructor exits before payload parsing |

### Why this works

1. CI3 router matches `OPTIONS /api/v1/sdm/personil` → dispatches to `Sdm::personil_get()`
2. `Sdm::__construct()` runs, sees `$_SERVER['REQUEST_METHOD'] == 'OPTIONS'`
3. Sets `http_response_code(200)`, sends CORS headers, and `exit()`s
4. `personil_get()` body **never executes** — no auth, no DB, no side effects

### No controller changes required

`Sdm.php` already has the correct CORS headers and OPTIONS short-circuit in `__construct()`. **Zero controller edits.**

### Estimated changes

| File | Lines added | Risk |
|------|------------|------|
| `application/config/routes.php` | 4 lines | ⚠️ Low — pure routing, no business logic |

### Verification

After applying, test with:
```bash
curl -X OPTIONS http://localhost:8080/api/v1/sdm/personil -i
# Expected: HTTP/1.1 200 OK + CORS headers, empty body

curl -X OPTIONS http://localhost:8080/api/v1/sdm/personil/abc123 -i
# Expected: HTTP/1.1 200 OK + CORS headers

curl -X OPTIONS http://localhost:8080/api/v1/sdm/org-tree -i
# Expected: HTTP/1.1 200 OK + CORS headers

curl -X OPTIONS http://localhost:8080/api/v1/sdm/hukum -i
# Expected: HTTP/1.1 200 OK + CORS headers
```

---

## 4. Broader Observations (not blocking)

The following modules also appear to lack OPTIONS routes in `routes.php` and may cause similar CORS failures if the Flutter app sends preflight requests to them:

| Module | Routes missing OPTIONS |
|--------|----------------------|
| DMS (`/dms/surat/*`) | Lines 81–84 — no OPTIONS for any DMS route |
| Kamtibmas (`/kamtibmas/laporan`) | Line 80 — no OPTIONS |
| Pengaduan (`/pengaduan/tiket/*`) | Lines 76–77 — no OPTIONS |
| Knowledge (`/knowledge/dokumen`) | Line 79 — no OPTIONS |

These can be addressed in a follow-up sweep if the Flutter app hits them.

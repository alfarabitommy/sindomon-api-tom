# Master Data CORS Preflight Audit

**Date:** 2025-01-20  
**Root cause:** CI3's router does `method_exists()` on the target controller before instantiation. If no `OPTIONS` route maps a URL to a method, the framework returns a **404** without ever running `__construct()`, so the built-in CORS short-circuit there never fires.

---

## 1. Route Status — `/api/v1/master/*` + related endpoints

| # | Route URL | Verbs Defined | OPTIONS? | Status |
|---|-----------|---------------|----------|--------|
| 1 | `api/v1/master/polda` | GET, POST | ❌ | **MISSING — needs base `OPTIONS` route** |
| 2 | `api/v1/master/polda/(:num)` | PUT, DELETE | ❌ | **MISSING — needs wildcard `OPTIONS` route** |
| 3 | `api/v1/master/polres` | GET, POST | ❌ | **MISSING — needs base `OPTIONS` route** |
| 4 | `api/v1/master/polres/(:num)` | PUT, DELETE | ❌ | **MISSING — needs wildcard `OPTIONS` route** |
| 5 | `api/v1/master/wilayah` | GET | ❌ | **MISSING — needs base `OPTIONS` route** |
| 6 | `api/v1/master/kategori-senjata` | GET, OPTIONS, POST | ✅ | OK |
| 7 | `api/v1/master/kategori-senjata/(:num)` | PUT, DELETE, OPTIONS | ✅ | OK |
| 8 | `api/v1/pangkat` | GET | ❌ | **MISSING — needs base `OPTIONS` route** |
| 9 | `api/v1/jabatan` | GET | ❌ | **MISSING — needs base `OPTIONS` route** |

> **Note:** Resources #8 (`pangkat`) and #9 (`jabatan`) live at `/api/v1/pangkat` and `/api/v1/jabatan` (not under `/api/v1/master/`), but their logic resides in `Master.php`, so they are included in this audit.

---

## 2. Controller Status — `application/controllers/Master.php`

The constructor already contains a global OPTIONS short-circuit (lines 14–17):

```php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

This means a dummy `_options` method **only needs to exist** — the constructor handles the 200 response before auth runs. The method body can be empty (or mirror `kategori_senjata_options`).

| # | Method | Exists? | Comments |
|---|--------|---------|----------|
| 1 | `polda_options()` | ❌ | MISSING |
| 2 | `polres_options()` | ❌ | MISSING |
| 3 | `wilayah_options()` | ❌ | MISSING |
| 4 | `pangkat_options()` | ❌ | MISSING |
| 5 | `jabatan_options()` | ❌ | MISSING |
| 6 | `kategori_senjata_options($id = null)` | ✅ | Reference implementation (line 797) |

---

## 3. Action Plan

### Phase A — Routes (file: `application/config/routes.php`)

Insert **7 new OPTIONS routes** before the `404_override` line (around line 130):

```php
// CORS preflight — Master Data
$route['api/v1/master/polda']['OPTIONS']            = 'master/polda_options';
$route['api/v1/master/polda/(:num)']['OPTIONS']     = 'master/polda_options/$1';
$route['api/v1/master/polres']['OPTIONS']           = 'master/polres_options';
$route['api/v1/master/polres/(:num)']['OPTIONS']    = 'master/polres_options/$1';
$route['api/v1/master/wilayah']['OPTIONS']          = 'master/wilayah_options';
$route['api/v1/pangkat']['OPTIONS']                 = 'master/pangkat_options';
$route['api/v1/jabatan']['OPTIONS']                 = 'master/jabatan_options';
```

### Phase B — Controller Dummies (file: `application/controllers/Master.php`)

Insert **5 dummy methods** (body can be empty — constructor handles the response). Place them near `kategori_senjata_options()` (around line 800) for discoverability:

```php
public function polda_options($id = null)  { /* handled by __construct */ }
public function polres_options($id = null) { /* handled by __construct */ }
public function wilayah_options()          { /* handled by __construct */ }
public function pangkat_options()          { /* handled by __construct */ }
public function jabatan_options()          { /* handled by __construct */ }
```

> **$id = null** on `polda_options` and `polres_options` lets a single method serve both the base and `(:num)` OPTIONS routes, matching the `kategori_senjata_options` pattern.

---

## Appendix: Why the constructor CORS trap doesn't save us

CI3's routing flow for an OPTIONS request:

```
Request: OPTIONS /api/v1/master/polres
  → Router looks for $route['api/v1/master/polres']['OPTIONS']
  → NOT FOUND (only GET & POST are defined)
  → CI3 returns 404 ("Page not found")
  → Controller is NEVER instantiated
  → __construct() CORS headers NEVER emitted
  → Browser sees 404 → "Failed to fetch"
```

Contrast with a properly-routed endpoint:

```
Request: OPTIONS /api/v1/master/kategori-senjata
  → Router finds $route['...']['OPTIONS'] = 'master/kategori_senjata_options'
  → CI3 checks method_exists('Master', 'kategori_senjata_options') → TRUE
  → Instantiates Master controller
  → __construct() emits CORS headers + HTTP 200 + exit
  → Browser sees 200 → proceeds with real request
```

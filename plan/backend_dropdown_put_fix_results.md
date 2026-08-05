# Fix Results: Dropdown CORS Preflight + PUT Helper

**Date:** 2026-08-05  
**Mode:** CODE/EXECUTE

---

## 1. Execution Summary

### Files Modified

| File | Change |
|---|---|
| `application/config/routes.php` | Added `['OPTIONS']` route siblings for `/api/v1/master/kategori-senjata` and `/api/v1/polda` |
| `application/controllers/Logistik.php` | Injected defensive `$this->load->helper('base64_file')` at top of `senjata_put()` |

### Validation

```
php -l application/config/routes.php       → No syntax errors detected
php -l application/controllers/Logistik.php → No syntax errors detected
```

---

## 2. Code Diff Proof

### 2.1 routes.php — New OPTIONS Routes

Legacy Polda block (line 67–68):

```php
// Polda (legacy — used by Flutter app)
$route['api/v1/polda']['GET']                      = 'polda/get';
$route['api/v1/polda']['OPTIONS']                  = 'polda/get';   // ← ADDED
```

Kategori Senjata block (line 107–108):

```php
// Master / Kategori Senjata (Logistik master data)
$route['api/v1/master/kategori-senjata']['GET']           = 'master/kategori_senjata_get';
$route['api/v1/master/kategori-senjata']['OPTIONS']       = 'master/kategori_senjata_get';   // ← ADDED
```

### 2.2 Why routing OPTIONS to `_get` is safe

Both `Master.php` and `Polda.php` `__construct()` intercept `OPTIONS` before any method dispatch:

```php
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

So the OPTIONS preflight emits the CORS headers and exits with 200 inside the constructor. The mapped `_get` method body is never executed. The route entry exists purely to satisfy CI3's `method_exists()` router gate (`system/core/CodeIgniter.php` ~line 423), which previously returned 404 before the controller was even instantiated.

### 2.3 Logistik.php — Defensive Helper Load in `senjata_put()`

Line 243–245:

```php
public function senjata_put($senjata_id)
{
    $this->load->helper('base64_file');   // ← ADDED (defensive; also loaded in __construct line 22)
```

This guarantees `save_base64_file()` (line 340) is defined at call time regardless of any constructor execution edge case.

---

## 3. Post-Fix Behavior

| Request | Before | After |
|---|---|---|
| `OPTIONS /api/v1/polda` | 404 → no CORS headers → browser blocks | 200 + `Access-Control-Allow-*` headers (from `Polda::__construct`) |
| `OPTIONS /api/v1/master/kategori-senjata` | 404 → no CORS headers → browser blocks | 200 + CORS headers (from `Master::__construct`) |
| `PUT /api/v1/logistik/senjata/(:any)` with `foto_fisik` | possible Fatal Error: undefined function | `save_base64_file()` defined → photo saved |

---

## 4. Note / Open Items

- The audit identified the **same OPTIONS gap** on other GET-only dropdown routes (`master/polda`, `master/wilayah`, `master/polres`, `pangkat`, `jabatan`). Per mission scope only the two requested routes were added. If those endpoints are consumed cross-origin from a browser, add the matching `['OPTIONS']` sibling routes the same way.
- Root fix for the preflight is the route entry; the `__construct()` interceptor (already present) handles header emission and `exit`.

# Backend Audit: Dropdown CORS + PUT Helper

**Date:** 2026-08-05  
**Scope:** `routes.php`, `Master.php`, `Polda.php`, `Logistik.php`, `autoload.php`, `base64_file_helper.php`

---

## 1. Dropdown CORS Status

### 1.1 Endpoint Map

| HTTP Method | URI | Controller::Method | routes.php Line |
|---|---|---|---|
| GET | `/api/v1/master/kategori-senjata` | `Master::kategori_senjata_get` | 106 |
| GET | `/api/v1/master/polda` | `Master::polda_get` | 69 |
| GET | `/api/v1/polda` | `Polda::get` | 67 |
| GET | `/api/v1/master/wilayah` | `Master::wilayah_get` | 101 |
| GET | `/api/v1/master/polres` | `Master::polres_get` | 73 |
| GET | `/api/v1/pangkat` | `Master::pangkat_get` | 111 |
| GET | `/api/v1/jabatan` | `Master::jabatan_get` | 112 |

### 1.2 Controller-Level CORS (OK)

Both controllers **do** have the OPTIONS interceptor in `__construct()`:

**Master.php** (lines 9–17):
```php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: false");
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
```

**Polda.php** (lines 9–17): identical pattern. ✅

### 1.3 Route-Level OPTIONS — MISSING (Root Cause)

**Question:** When the browser sends `OPTIONS /api/v1/master/kategori-senjata`, does CI3 route it to `Master::kategori_senjata_get`?

**Answer:** No. CI3's router gate (`system/core/CodeIgniter.php` ~line 423) performs a `method_exists()` check. Because `routes.php` only defines:

```php
// line 106 — GET only, no OPTIONS
$route['api/v1/master/kategori-senjata']['GET'] = 'master/kategori_senjata_get';
```

…an OPTIONS request finds **no matching route**, returns **404**, and the `Master` controller is **never instantiated**. The `__construct()` CORS interceptor never fires.

**Same gap** for every other endpoint in the table above (all GET-only routes with no `['OPTIONS']` sibling).

### 1.4 Contrast: Logistik (Working CORS)

Logistik endpoints have **explicit** OPTIONS routes in `routes.php`:

```php
// lines 88–89
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';
$route['api/v1/logistik/senjata/(:any)']['OPTIONS'] = 'logistik/senjata_options';
```

And `Logistik.php` has a dedicated handler (lines 750–753):

```php
public function senjata_options($id = null) {
    http_response_code(200);
    exit;
}
```

This method exists **solely** to satisfy the `method_exists()` gate. The CORS headers themselves are emitted by `__construct()` (which runs before the method). The method body is a formality.

### 1.5 Verdict

**Root cause:** Missing `['OPTIONS']` route entries in `routes.php` for all dropdown GET endpoints. Controller-level CORS headers are present but unreachable during preflight because CI3 404s before loading the controller.

---

## 2. Helper Loading Status (`save_base64_file`)

### 2.1 Helper Definition

File: `/application/helpers/base64_file_helper.php`  
Function: `save_base64_file()` — defined inside `if (!function_exists(...))` guard. ✅

### 2.2 Autoload Status

`/application/config/autoload.php` line 92:

```php
$autoload['helper'] = array('url', 'file');
```

`base64_file` is **NOT autoloaded**. It must be loaded manually via `$this->load->helper('base64_file')`.

### 2.3 Logistik Controller Loading

`Logistik::__construct()` (line 22):

```php
$this->load->helper('base64_file');
```

Loaded once at controller instantiation, before any method runs. This applies to **all** public methods: `senjata_post()`, `senjata_put()`, `senjata_delete()`, `senjata_get()`, `senjata_options()`, `amunisi_post()`, `amunisi_get()`, `satwa_post()`.

### 2.4 POST vs PUT Comparison

| Aspect | `senjata_post()` (line 104) | `senjata_put()` (line 340) |
|---|---|---|
| Helper load source | `__construct()` → line 22 | `__construct()` → line 22 |
| Same controller instance | Yes | Yes |
| Code-visible reason to fail | None | None |

**Finding:** There is **no code-level difference** in how the helper is loaded between POST and PUT. Both methods share the same `__construct()` execution path (the OPTIONS interceptor at line 12 only exits for OPTIONS verb — not for PUT). `save_base64_file()` should be available in both.

### 2.5 Possible Explanations for "Undefined function" at Runtime

If the error is reproducing in production, the cause is **not** in the application code logic. Candidates:

1. **Deployment artifact mismatch** — `base64_file_helper.php` not deployed or corrupted on the server. POST may have been tested against a different (correct) deployment than PUT.

2. **PHP opcache staleness** — If the helper file was added after the initial deployment and opcache was not cleared, old requests may not see the new file. A subsequent deploy or cache clear would fix both POST and PUT simultaneously, but timing-dependent partial states are possible.

3. **Custom CI3 `_remap()` or routing hook** — If a custom `_remap()` method or `pre_controller` hook intercepts the request and re-instantiates the controller differently (e.g., a cache layer that serializes/deserializes the controller), helper loads could be lost on deserialized instances.

4. **.htaccess rewrite anomaly** — If the `mod_rewrite` rules redirect the PUT request through a different entry point that bypasses CI3's normal bootstrap, the helper load chain may be skipped.

### 2.6 Recommendation

Add a defensive inline load at the top of `senjata_put()` before the `save_base64_file()` call, inside the foto block:

```php
// Line ~337, before save_base64_file()
if (array_key_exists('foto_fisik', $input) && $input['foto_fisik'] !== null && $input['foto_fisik'] !== '') {
    $this->load->helper('base64_file');   // ← defensive load
    $upload_dir = FCPATH . 'uploads/senjata/';
    // ...
```

This is belt-and-suspenders: it's technically redundant given `__construct()` already loads the helper, but it makes the dependency explicit at the call site and protects against any edge case where `__construct()` execution is truncated before line 22.

---

## 3. Summary

| Issue | Status | Root Cause |
|---|---|---|
| CORS preflight fails on dropdown GET endpoints | **CONFIRMED BUG** | Missing `['OPTIONS']` route entries in `routes.php` for Master/Polda endpoints |
| `save_base64_file()` undefined in `senjata_put()` | **NOT REPRODUCIBLE in code** | Helper loaded in `__construct()` (line 22), available to all methods. Runtime cause likely deployment/cache issue |

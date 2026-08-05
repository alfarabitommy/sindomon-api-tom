# Backend DELETE Audit — `api/v1/logistik/senjata`

**Date:** 2026-08-05
**Scope:** Route, Controller, Root Cause

---

## 1. Route Status

### Existing Senjata Routes (`application/config/routes.php`, lines 84–88)

| Method   | Route                                      | Handler                    |
|----------|--------------------------------------------|----------------------------|
| `POST`   | `api/v1/logistik/senjata`                  | `logistik/senjata_post`    |
| `GET`    | `api/v1/logistik/senjata`                  | `logistik/senjata_get`     |
| `PUT`    | `api/v1/logistik/senjata/(:any)`           | `logistik/senjata_put/$1`  |
| `OPTIONS`| `api/v1/logistik/senjata`                  | `logistik/senjata_options` |
| `OPTIONS`| `api/v1/logistik/senjata/(:any)`           | `logistik/senjata_options` |

### Missing DELETE Route

```php
// DOES NOT EXIST — never registered
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
```

**Verdict:** No DELETE route defined. A `DELETE /api/v1/logistik/senjata/{id}` request hits CI3's 404 handler.

---

## 2. Controller Status

### Methods in `application/controllers/Logistik.php`

| Method                   | Signature                             | Exists? |
|--------------------------|---------------------------------------|---------|
| `senjata_post()`         | `public function senjata_post()`      | Yes     |
| `senjata_get()`          | `public function senjata_get()`       | Yes     |
| `senjata_put()`          | `public function senjata_put($senjata_id)` | Yes |
| `senjata_delete()`       | —                                     | **NO**  |
| `senjata_options()`      | `public function senjata_options($id = null)` | Yes |
| `amunisi_post()`         | `public function amunisi_post()`      | Yes     |
| `amunisi_get()`          | `public function amunisi_get()`       | Yes     |
| `satwa_post()`           | `public function satwa_post()`        | Yes     |

**Verdict:** No `senjata_delete()` method exists in the controller.

### Reference: How other modules handle DELETE

```php
// User module (routes.php line 58):
$route['api/v1/user/(:num)']['DELETE'] = 'auth/user_delete/$1';

// SDM Personil (routes.php line 97):
$route['api/v1/sdm/personil/(:any)']['DELETE'] = 'sdm/personil_delete/$1';
```

Both pass the ID as a **URL segment** captured by `(:num)` / `(:any)` and injected as a method argument (`$1` → `$senjata_id`).

The senjata module uses UUID-based `senjata_id` values, so the `(:any)` matcher (same as PUT) is the correct choice.

---

## 3. Hypothesis

### Primary Cause (confirmed)

> **The DELETE operation fails because neither the route nor the controller method exists.**

Two missing pieces:

1. **No route entry** — `routes.php` has no `DELETE` key under `api/v1/logistik/senjata/(:any)`.
2. **No controller method** — `Logistik.php` has no `senjata_delete($senjata_id)`.

A `DELETE` request sent by the Flutter client never reaches any handler and returns a CI3 404.

### Secondary Concerns (to verify after adding route + method)

| Concern | Risk |
|---------|------|
| `.htaccess` may block non-GET/POST | **Low** — PUT already works, and `Access-Control-Allow-Methods` in `__construct()` includes DELETE. |
| CORS preflight | **Low** — OPTIONS routes already cover `senjata/(:any)`. |
| JWT auth missing in delete path | **N/A yet** — method doesn't exist. But `amunisi_post` and all other methods validate JWT, so the new method should follow the same pattern. |
| Soft-delete vs hard-delete design | **Open question** — other modules (polda, polres) use soft-delete (set `is_active = 0`). Need to confirm whether senjata should soft-delete or hard-delete. |

### Fix Checklist

1. Add route: `$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';`
2. Add method: `public function senjata_delete($senjata_id)` in `Logistik.php`
3. Inside the method: JWT auth → jurisdiction check → DELETE/UPDATE query → response

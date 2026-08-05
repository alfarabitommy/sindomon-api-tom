# Debug: Senjata Update API — Backend Audit

## 1. Backend Findings

**Root Cause: `PUT /api/v1/logistik/senjata` does NOT exist. No update endpoint for Senjata is implemented.**

### Controller (`application/controllers/Logistik.php`) — Methods Present

| Method | Purpose | Exists? |
|--------|---------|---------|
| `senjata_post()` | Create new Senjata (strict insert, no upsert logic) | Yes (line 32) |
| `senjata_get()` | List all Senjata (with `?search=`) | Yes (line 169) |
| `senjata_put()` | Update Senjata | **NO** |
| Any update/upsert logic inside `senjata_post()` | — | **NO** — the method is insert-only. No `if ($senjata_id)` branch exists. |

### Routes (`application/config/routes.php`) — Registered Senjata Routes

```php
$route['api/v1/logistik/senjata']['POST'] = 'logistik/senjata_post';   // line 84
$route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';    // line 85
// NO PUT route. NO PUT route with (:num) segment.
```

### What Happens When Flutter Sends `PUT /api/v1/logistik/senjata`

CI3 finds no matching route → falls through to default 404 handler → returns HTML "Page Not Found" instead of JSON.

### For Reference — How `kategori_senjata` Does It Correctly (in `Master.php`)

```php
// route (line 104):
$route['api/v1/master/kategori-senjata/(:num)']['PUT'] = 'master/kategori_senjata_put/$1';

// controller (line 640):
public function kategori_senjata_put($kategori_id) { ... }
```

This pattern (PUT with `/(:num)` segment + a `_put` method accepting the ID) is the CI3 convention used elsewhere in the project.

### Required Fix (Backend Only)

Two things must be created:

1. **Route** in `application/config/routes.php`:
   ```php
   $route['api/v1/logistik/senjata/(:num)']['PUT'] = 'logistik/senjata_put/$1';
   ```

2. **Method** in `application/controllers/Logistik.php`:
   ```php
   public function senjata_put($senjata_id) { ... }
   ```

The URL should be `PUT /api/v1/logistik/senjata/{senjata_id}` — the `senjata_id` goes in the URL path, not in the JSON body.

## 2. Payload Requirements

The current `senjata_post()` accepts these JSON fields:

| Field | Type | Required |
|-------|------|----------|
| `nomor_seri` | string | Required (unique) |
| `kategori_id` | int | Required |
| `tahun_pengadaan` | string | Required |
| `status_kelayakan` | string | Required |
| `foto_fisik` | base64 | Required |

An update method (`senjata_put`) would likely:

- Accept the same JSON body fields, but all should be **optional** (only update what's provided).
- Validate `foto_fisik` only if present (don't require re-uploading the photo on every update).
- Validate `nomor_seri` uniqueness only if changed (exclude the current record from the duplicate check).
- Include `updated_at` timestamp on save.

**The Flutter side is not the problem.** The backend simply has no update endpoint. Until `senjata_put()` + its route are implemented, any PUT/PATCH to the Senjata resource will return 404.

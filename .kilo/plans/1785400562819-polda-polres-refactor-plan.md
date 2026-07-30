# Phase 1: Polda & Polres API Refactor Plan

## Scope
- Centralize all Polda CRUD into `Master.php`
- Complete Polres CRUD (add missing `polres_get`)
- Standardize routes under `/api/v1/master/`
- Deprecate legacy `/api/v1/polda` route and `Polda.php` controller
- Fix known bugs in existing Polres methods

---

## 1. Method Signatures to Create in `Master.php`

### 1.1 `polda_get()` — GET /api/v1/master/polda

```
Response shape: flat polda list (no nested polres)
```

| Field | Type | Source |
|-------|------|--------|
| `id` | int | `tbl_polda.id` |
| `nama_polda` | string | `tbl_polda.nama_polda` |
| `latitude` | string | `tbl_polda.latitude` |
| `longitude` | string | `tbl_polda.longitude` |
| `created_at` | string | `tbl_polda.created_at` |

- Auth: any valid JWT (role 1,2,3 — no role restriction)
- Status: 200

### 1.2 `polda_post()` — POST /api/v1/master/polda

| Input | Required | Validation |
|-------|----------|------------|
| `nama_polda` | yes | non-empty string |
| `latitude` | no | string, default null |
| `longitude` | no | string, default null |

- Auth: Super Admin only (role_id=1)
- Status: 201 on success, 422 on validation failure
- Response data: `{"polda_id": <int>}`

### 1.3 `polda_put($id)` — PUT /api/v1/master/polda/{id}

| Input | Required | Validation |
|-------|----------|------------|
| `nama_polda` | yes | non-empty string |
| `latitude` | no | string, default null |
| `longitude` | no | string, default null |

- Auth: Super Admin only (role_id=1)
- `$id` from URL param, NOT JSON body
- Pre-check: polda exists → 404 if not
- Status: 200 on success, 404 if not found, 422 on validation

### 1.4 `polda_delete($id)` — DELETE /api/v1/master/polda/{id}

- Auth: Super Admin only (role_id=1)
- `$id` from URL param
- Pre-check: polda exists → 404 if not
- FK catch: MariaDB error 1451 (`tbl_polres.polda_id` → `tbl_polda.id` ON DELETE RESTRICT)
  - Return 409 with Indonesian message about Polres dependencies
- Status: 200 on success, 404 if not found, 409 if FK blocked

### 1.5 `polres_get()` — GET /api/v1/master/polres

| Field | Type | Source |
|-------|------|--------|
| `polres_id` | int | `tbl_polres.polres_id` |
| `polda_id` | int | `tbl_polres.polda_id` |
| `nama_polres` | string | `tbl_polres.nama_polres` |
| `nama_polda` | string | JOIN `tbl_polda.nama_polda` |

- Auth: any valid JWT
- Optional GET param: `?polda_id=<int>` to filter by parent polda
- Response: flat array, ordered by `polres_id` ASC
- Status: 200

**Design rationale**: Returns flat polres list with JOINed `nama_polda` for display. `?polda_id=` filter supports Flutter dropdown cascading (select Polda → load Polres). The nested tree view remains at `wilayah_get()`.

---

## 2. Existing Methods — Bug Fixes Required

### 2.1 `polres_put($polres_id)` — Missing 404 check

**Bug**: The method checks `polda_exists` but never verifies `polres_exists`. If the polres_id doesn't exist, it runs an UPDATE with 0 affected rows and returns 200 anyway.

**Fix**: Before the polda FK check, add:
```php
$polres_exists = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id])->num_rows();
if ($polres_exists === 0) {
    http_response_code(404);
    echo json_encode([...'Polres tidak ditemukan.'...]);
    return;
}
```

### 2.2 `polres_delete($polres_id)` — Missing 404 check

**Bug**: Same pattern — runs DELETE with `db_debug = FALSE`, but if no rows matched and no FK error, returns 200 "berhasil dihapus" even though nothing was deleted.

**Fix**: After `$this->db->delete()`, check `$this->db->affected_rows()`:
```php
if ($this->db->affected_rows() === 0 && $error['code'] == 0) {
    // 404 — polres not found (not FK error)
}
```

---

## 3. Route Definitions

### New routes to add:

```php
// Polda CRUD (under master/)
$route['api/v1/master/polda']['GET']              = 'master/polda_get';
$route['api/v1/master/polda']['POST']             = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']       = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE']    = 'master/polda_delete/$1';

// Polres — add GET
$route['api/v1/master/polres']['GET']             = 'master/polres_get';
```

### Existing routes to keep (unchanged):

```php
$route['api/v1/master/polres']['POST']            = 'master/polres_post';
$route['api/v1/master/polres/(:num)']['PUT']      = 'master/polres_put/$1';
$route['api/v1/master/polres/(:num)']['DELETE']   = 'master/polres_delete/$1';
$route['api/v1/master/wilayah']['GET']            = 'master/wilayah_get';
```

### Route to REMOVE:

```php
$route['api/v1/polda']['get'] = 'polda/get';   // DELETE THIS LINE
```

`Polda.php` controller file: mark as deprecated. Do not delete yet (may have other consumers). Can be removed entirely after frontend cutover verified.

---

## 4. Response Shape Standard

All new methods follow the modern Master.php pattern:

```json
{
  "status": 200,
  "message": "Indonesian success message here.",
  "data": []
}
```

- `(object)[]` via `(object)[]` or `new stdClass()` for empty data
- HTTP status set via `http_response_code()` (consistent with existing Master methods)

---

## 5. DB Schema Reference

### `tbl_polda`
```
id            INT(11) AUTO_INCREMENT PK
nama_polda    VARCHAR(100) NULL
latitude      VARCHAR(100) NULL
longitude     VARCHAR(100) NULL
created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
```

### `tbl_polres`
```
polres_id     INT(11) AUTO_INCREMENT PK
polda_id      INT(11) NOT NULL DEFAULT 0  — FK → tbl_polda.id ON DELETE RESTRICT
nama_polres   VARCHAR(100) NOT NULL
```

---

## 6. Implementation Checklist

- [ ] **1.** Add `polda_get()` to `Master.php` — flat polda list, any auth
- [ ] **2.** Add `polda_post()` to `Master.php` — create polda, Super Admin only
- [ ] **3.** Add `polda_put($id)` to `Master.php` — update polda, Super Admin only, 404 check
- [ ] **4.** Add `polda_delete($id)` to `Master.php` — delete polda, Super Admin only, FK 1451→409, 404 check
- [ ] **5.** Add `polres_get()` to `Master.php` — flat polres list, JOIN nama_polda, optional ?polda_id= filter
- [ ] **6.** Fix `polres_put($polres_id)` — add 404 pre-check for polres existence
- [ ] **7.** Fix `polres_delete($polres_id)` — add 404 pre-check via affected_rows
- [ ] **8.** Add all new `$route` entries to `routes.php`
- [ ] **9.** Remove legacy `$route['api/v1/polda']['get'] = 'polda/get';` from `routes.php`
- [ ] **10.** Verify no other code references `Polda.php` (search for `polda/get`, `Polda::class`, `new Polda`)

---

## 7. Validation Plan

After implementation, test with curl/Postman:

1. `GET /api/v1/master/polda` → 200 with flat polda array
2. `GET /api/v1/master/polda` (no token) → 401
3. `POST /api/v1/master/polda` (Super Admin) → 201 with polda_id
4. `POST /api/v1/master/polda` (Operator/role_id=2) → 403
5. `PUT /api/v1/master/polda/999` (nonexistent) → 404
6. `PUT /api/v1/master/polda/{id}` (change nama_polda) → 200
7. `DELETE /api/v1/master/polda/{id_with_polres}` → 409 (FK blocked)
8. `DELETE /api/v1/master/polda/{id_no_polres}` → 200
9. `GET /api/v1/master/polres` → 200 with flat polres array + nama_polda
10. `GET /api/v1/master/polres?polda_id=1` → 200 filtered
11. `GET /api/v1/polda` → 404 (legacy route removed)

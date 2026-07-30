# Backend API Refactor Plan — Gap Analysis & Roadmap

## Audit Date
2026-07-30

## Project
CodeIgniter 3 — `sindomon-api-tom` — MySQL `sindomondb`

## Rules (non-negotiable)
1. **Scoped paths**: every endpoint under `/api/v1/{module}/{entity}` (e.g. `/api/v1/master/polda`, `/api/v1/sdm/personil`)
2. **Create + Update**: POST for create, PUT for update on every core entity (frontend reuses forms)
3. **DELETE by path param**: `DELETE /api/v1/{module}/{entity}/{id}`, no JSON body
4. **Naming**: "Inventaris" → "Sarpras", table = `tbl_sarpras`

---

## 1. Current Route Inventory vs Required

| Entity | Required | Current | Missing |
|--------|----------|---------|---------|
| **Polda** | GET/POST/PUT/DELETE `/master/polda` | GET `/polda` (flat, legacy) | POST, PUT, DELETE + migrate GET |
| **Polres** | GET/POST/PUT/DELETE `/master/polres` | POST, PUT, DELETE | **GET** |
| **Personil** | GET/POST/PUT/DELETE `/sdm/personil` | GET, POST, PUT | **DELETE** |
| **Senjata** | GET/POST/PUT/DELETE `/logistik/senjata` | POST only | **GET, PUT, DELETE** |
| **Satwa** | GET/POST/PUT/DELETE `/logistik/satwa` | POST only | **GET, PUT, DELETE** |
| **Sarpras** | GET/POST/PUT/DELETE `/logistik/sarpras` | — | **Everything** (no controller, no table, no route) |
| **User** | GET/POST/PUT/DELETE `/user` | GET via `/user` (Auth::all, no auth) | **POST, PUT, DELETE** (needs full rebuild) |

---

## 2. Controller Mapping

| Controller | File | Pattern | Auth | Phase |
|-----------|------|---------|------|-------|
| `Master.php` | exists, 202 lines | Modern (`get_jwt_payload`) | Super Admin (role_id=1) | Extend |
| `Sdm.php` | exists, 630 lines | Modern | Operator Polda (role_id=2) | Extend |
| `Logistik.php` | exists, 443 lines | Modern | JWT (auto-inject polda_id) | Extend |
| `Polda.php` | exists, 56 lines | **Legacy** (raw `jwt_decode`) | Any auth | **Migrate → Master** |
| `Auth.php` | exists, 62 lines | **Legacy** (raw `jwt_decode`, no CORS) | None on `all()` | **Replace → User.php** |
| **`User.php`** | **does not exist** | — | — | **Create** |
| **`Sarpras.php`** | **does not exist** | — | — | **Create** |

---

## 3. Database Tables

| Table | Exists in schema? | Defined in seeder `_ensure_tables()`? | Action |
|-------|--------------------|---------------------------------------|--------|
| `tbl_polda` | Yes (v5) | Yes | No change |
| `tbl_polres` | Yes (v5, migrated v6) | Yes | No change |
| `tbl_personil` | Yes (v5 FK) | Yes | No change |
| `tbl_senjata` | Yes | Yes | No change |
| `tbl_satwa` | **NOT defined in seeder or SQL dump** | No | **Create migration** |
| `tbl_sarpras` | **NOT defined anywhere** | No | **Create migration** |
| `tbl_users` | Yes (v5) | No (assumed pre-existing) | No change |
| `tbl_role` | Yes (v5) | No (assumed pre-existing) | No change |

### `tbl_satwa` columns (inferred from Logistik::satwa_post):
- `polda_id` int FK
- `nomor_registrasi` varchar unique
- `jenis_satwa` varchar (enum: K9 / Turangga)
- `nama_satwa` varchar
- `nama_handler` varchar
- `kualifikasi` varchar
- `jadwal_vaksin` date nullable
- `foto_url` varchar(500)
- Need PK: `satwa_id` varchar(36) UUID or int auto_increment
- `created_at` datetime
- `updated_at` datetime

### `tbl_sarpras` columns (needs definition — propose based on common inventaris pattern):
- `sarpras_id` varchar(36) UUID PK
- `polda_id` int FK
- `kode_barang` varchar unique
- `nama_barang` varchar
- `kategori` varchar (Kendaraan / Alat Komunikasi / Perlengkapan Kantor / ...)
- `kondisi` enum(Baik, Rusak Ringan, Rusak Berat)
- `tahun_pengadaan` varchar(10)
- `foto_url` varchar(500)
- `created_at` datetime
- `updated_at` datetime

---

## 4. Gap Detail & Priority

### TIER 1 — Quick Wins (no new tables, no new files)

| # | Gap | File | Work |
|---|-----|------|------|
| 1.1 | `GET /api/v1/master/polres` missing | `Master.php` + `routes.php` | Add `polres_get()` method + route |
| 1.2 | `DELETE /api/v1/sdm/personil/{personil_id}` missing | `Sdm.php` + `routes.php` | Add `personil_delete($personil_id)` method + route |
| 1.3 | `GET /api/v1/logistik/senjata` missing | `Logistik.php` + `routes.php` | Add `senjata_get()` method + route |
| 1.4 | `PUT /api/v1/logistik/senjata/{id}` missing | `Logistik.php` + `routes.php` | Add `senjata_put($senjata_id)` method + route (optional photo update) |
| 1.5 | `DELETE /api/v1/logistik/senjata/{id}` missing | `Logistik.php` + `routes.php` | Add `senjata_delete($senjata_id)` method + route |
| 1.6 | `GET /api/v1/logistik/satwa` missing | `Logistik.php` + `routes.php` | Add `satwa_get()` method + route |
| 1.7 | `PUT /api/v1/logistik/satwa/{id}` missing | `Logistik.php` + `routes.php` | Add `satwa_put($satwa_id)` method + route (optional photo update) |
| 1.8 | `DELETE /api/v1/logistik/satwa/{id}` missing | `Logistik.php` + `routes.php` | Add `satwa_delete($satwa_id)` method + route |

### TIER 2 — Polda Migration (migrate legacy controller to scoped Master)

| # | Gap | File | Work |
|---|-----|------|------|
| 2.1 | `GET /api/v1/master/polda` — replace flat `/polda` | `Master.php` + `routes.php` | Add `polda_get()` (copy+modernize Polda::get) |
| 2.2 | `POST /api/v1/master/polda` | `Master.php` + `routes.php` | Add `polda_post()` — Super Admin only |
| 2.3 | `PUT /api/v1/master/polda/{id}` | `Master.php` + `routes.php` | Add `polda_put($id)` — Super Admin only |
| 2.4 | `DELETE /api/v1/master/polda/{id}` | `Master.php` + `routes.php` | Add `polda_delete($id)` — Super Admin, FK check |
| 2.5 | Remove legacy route + controller | `Polda.php`, `routes.php` | Drop deprecated `/api/v1/polda` route. Keep Polda.php file (or delete if no other use) |

### TIER 3 — User CRUD (new controller)

| # | Gap | File | Work |
|---|-----|------|------|
| 3.1 | Create `User.php` controller | New file | Modern pattern, CORS headers, JWT auth via `get_jwt_payload()` |
| 3.2 | `GET /api/v1/user` | `User.php` + `routes.php` | `user_get()` — Super Admin: list all. Operator: list own polda |
| 3.3 | `POST /api/v1/user` | `User.php` + `routes.php` | `user_post()` — Super Admin create user |
| 3.4 | `PUT /api/v1/user/{id}` | `User.php` + `routes.php` | `user_put($id)` — Super Admin update user |
| 3.5 | `DELETE /api/v1/user/{id}` | `User.php` + `routes.php` | `user_delete($id)` — Super Admin delete user |
| 3.6 | Keep `/api/v1/auth/login` and `/api/v1/auth/insert` in Auth.php | No change | Legacy auth endpoints preserved for now |

### TIER 4 — Sarpras (new table + new controller)

| # | Gap | File | Work |
|---|-----|------|------|
| 4.1 | CREATE TABLE `tbl_sarpras` | Migration SQL or Seeder | Add `_ensure_tables()` entry in Seeder.php |
| 4.2 | Create `Sarpras.php` controller | New file | Modern pattern, file upload via `save_base64_file()` |
| 4.3 | `GET /api/v1/logistik/sarpras` | `Sarpras.php` + `routes.php` | List with search, jurisdiction filter |
| 4.4 | `POST /api/v1/logistik/sarpras` | `Sarpras.php` + `routes.php` | Create with mandatory photo upload |
| 4.5 | `PUT /api/v1/logistik/sarpras/{id}` | `Sarpras.php` + `routes.php` | Update with optional photo upload |
| 4.6 | `DELETE /api/v1/logistik/sarpras/{id}` | `Sarpras.php` + `routes.php` | Delete with file cleanup |

---

## 5. Target `routes.php` Structure

```php
// ── Auth ──
$route['api/v1/auth/login']                       = 'auth/login';         // keep
$route['api/v1/auth/insert']                       = 'auth/insert_user';   // keep (backward compat)

// ── User ──
$route['api/v1/user']['GET']                       = 'user/get';
$route['api/v1/user']['POST']                      = 'user/post';
$route['api/v1/user/(:num)']['PUT']                = 'user/put/$1';
$route['api/v1/user/(:num)']['DELETE']             = 'user/delete/$1';

// ── Master / Wilayah ──
$route['api/v1/master/wilayah']['GET']             = 'master/wilayah_get';
$route['api/v1/master/polda']['GET']               = 'master/polda_get';
$route['api/v1/master/polda']['POST']              = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']        = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE']     = 'master/polda_delete/$1';
$route['api/v1/master/polres']['GET']              = 'master/polres_get';
$route['api/v1/master/polres']['POST']             = 'master/polres_post';
$route['api/v1/master/polres/(:num)']['PUT']       = 'master/polres_put/$1';
$route['api/v1/master/polres/(:num)']['DELETE']    = 'master/polres_delete/$1';

// ── SDM ──
$route['api/v1/sdm/org-tree']['GET']               = 'sdm/org_tree_get';
$route['api/v1/sdm/personil']['GET']               = 'sdm/personil_get';
$route['api/v1/sdm/personil']['POST']              = 'sdm/personil_post';
$route['api/v1/sdm/personil/(:any)']['PUT']        = 'sdm/personil_put/$1';
$route['api/v1/sdm/personil/(:any)']['DELETE']     = 'sdm/personil_delete/$1';
$route['api/v1/sdm/hukum']['POST']                 = 'sdm/hukum_post';

// ── Logistik ──
$route['api/v1/logistik/senjata']['GET']           = 'logistik/senjata_get';
$route['api/v1/logistik/senjata']['POST']          = 'logistik/senjata_post';
$route['api/v1/logistik/senjata/(:any)']['PUT']    = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/amunisi']['GET']           = 'logistik/amunisi_get';
$route['api/v1/logistik/amunisi']['POST']          = 'logistik/amunisi_post';
$route['api/v1/logistik/amunisi/(:any)']['PUT']    = 'logistik/amunisi_put/$1';
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1';
$route['api/v1/logistik/satwa']['GET']             = 'logistik/satwa_get';
$route['api/v1/logistik/satwa']['POST']            = 'logistik/satwa_post';
$route['api/v1/logistik/satwa/(:any)']['PUT']      = 'logistik/satwa_put/$1';
$route['api/v1/logistik/satwa/(:any)']['DELETE']   = 'logistik/satwa_delete/$1';
$route['api/v1/logistik/sarpras']['GET']           = 'sarpras/get';
$route['api/v1/logistik/sarpras']['POST']          = 'sarpras/post';
$route['api/v1/logistik/sarpras/(:any)']['PUT']    = 'sarpras/put/$1';
$route['api/v1/logistik/sarpras/(:any)']['DELETE'] = 'sarpras/delete/$1';

// ── Existing routes (unchanged) ──
// [pengaduan, knowledge, kamtibmas, dms, role, profile — keep as-is]
```

---

## 6. Auth Matrix

| Module | Role | Scope |
|--------|------|-------|
| Master/Polda CRUD | role_id=1 (Super Admin) | All Polda |
| Master/Polres CRUD | role_id=1 | All Polres |
| Master/Wilayah GET | Any authenticated | All Wilayah |
| SDM/Personil GET | 1,2,3 | 2=locked to JWT polda_id, 1+3=optional filter |
| SDM/Personil POST/PUT/DELETE | role_id=2 | Locked to JWT polda_id |
| Logistik/Senjata/Satwa/Sarpras GET | any authenticated | role_id=2 locked to JWT polda_id |
| Logistik/Senjata/Satwa/Sarpras POST/PUT/DELETE | any authenticated | polda_id auto-injected from JWT |
| User CRUD | role_id=1 | All users |
| User GET | role_id=1 → all; role_id=2 → own polda |

---

## 7. Response Format Standard

All new/modified endpoints must follow:
```json
{
  "status": <http_status_code>,
  "message": "<Indonesian language message>",
  "data": <object|array|null>
}
```

POST → 201, PUT → 200, DELETE → 200, GET → 200
422 for validation, 403 for forbidden, 401 for no token, 404 for not found

---

## 8. Implementation Order

1. **TIER 1** — 8 methods in existing controllers + route entries (no risk)
2. **TIER 2** — Polda migration to Master controller + route cleanup
3. **TIER 3** — New `User.php` controller + User CRUD routes
4. **TIER 4** — `tbl_sarpras` migration + new `Sarpras.php` controller

---

## 9. Open Questions for User

- **Q1**: Is `tbl_satwa` already in the live database? The seeder doesn't create it, but Logistik controller references it. Confirm or provide migration path.
- **Q2**: Should the `tbl_sarpras` schema above be approved, or is there a documented ERD with different columns?
- **Q3**: Should existing flat routes (`/api/v1/polda`, `/api/v1/role`, `/api/v1/profile`) be removed immediately, or kept as deprecated aliases for one release?
- **Q4**: Does User CRUD need polda-level operators to create/update only their own polda's users, or is it Super Admin only (as currently planned)?
- **Q5**: For PUT operations on Senjata/Satwa/Sarpras — should `foto_fisik`/`foto_url` be mandatory on update, or optional (keep existing if not sent)?
- **Q6**: The `tbl_polda` PK is auto-increment `id` (INT), not UUID. All other entities (Personil, Senjata) use UUID. Should Polda CRUD use `id` (numeric) or do we keep as-is? (Current Polres PUT/DELETE uses `(:num)` placeholder — Polda will follow same pattern.)

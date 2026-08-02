# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Quick Commands

```bash
# Start dev server (port 8080, with test router)
php -S localhost:8080 tests/router.php

# Run API E2E tests (Playwright — starts its own server)
npm test

# Run database seeder (resets and seeds all tables, 250+ rows)
php index.php seeder run

# Run a single Playwright test file
npx playwright test tests/api/seeder_master.spec.ts
```

## Architecture Overview

CodeIgniter 3 REST API (`>=PHP 5.3.7`, MySQL `sindomondb`) for Indonesian National Police (Polri) management. The backend serves a Flutter frontend.

**No ORM or Models.** Controllers query the database directly via `$this->db->query()` or CodeIgniter query builder. The project uses no CI3 models directory at all.

### Auth & Roles

JWT authentication (HS256) is implemented in `application/libraries/Jwt.php` (decode) and `application/helpers/jwt_helper.php` (encode + smart extraction). The `get_jwt_payload($ci)` helper handles both `Bearer <token>` and raw token formats. Secret is configured in `application/config/jwt.php`. JWT payload includes `{uid, username, role_id, polda_id, iat, exp}`.

Three roles control authorization across the API:

| role_id | Name | Access Pattern |
|---------|------|---------------|
| 1 | Super Admin | Full access, optional `?polda_id=` query filtering |
| 2 | Operator Polda | Locked to JWT `polda_id` — cannot cross jurisdictions |
| 3 | Eksekutif | Read-only access with optional `?polda_id=` filtering |

Jurisdiction enforcement: Operator Polda (role_id=2) always has `polda_id` auto-injected from JWT. Admin/Eksekutif can optionally filter by `?polda_id=` query param.

### API Surface (`application/config/routes.php`)

All routes are prefixed `api/v1/`. Key endpoints:

| Route | Controller | Auth |
|-------|-----------|------|
| `POST /auth/login` | `Auth` | Public |
| `GET/POST/PUT/DELETE /role` | `Role` | JWT |
| `GET /profile` | `Profile` | Raw token |
| `GET/POST /master/polda`, `GET/POST/PUT/DELETE /master/polres` | `Master` | JWT (POST/PUT/DELETE: Admin only) |
| `GET /master/wilayah` | `Master` | JWT |
| `GET /sdm/org-tree` | `Sdm` | JWT (role-based jurisdiction) |
| `GET/POST /sdm/personil`, `PUT /sdm/personil/(:any)` | `Sdm` | JWT (POST/PUT: Operator only) |
| `POST /sdm/hukum` | `Sdm` | Operator only |
| `POST /logistik/senjata`, `POST /logistik/amunisi`, `GET /logistik/amunisi`, `POST /logistik/satwa` | `Logistik` | JWT |
| `POST /dms/surat`, `GET /dms/surat`, `GET /dms/surat/(:any)/download`, `PATCH /dms/surat/(:any)/read` | `Dms` | JWT |
| `POST /kamtibmas/laporan` | `Kamtibmas` | Operator only |
| `GET /pengaduan/tiket`, `PATCH /pengaduan/tiket/(:num)/status` | `Pengaduan` | JWT |
| `GET /knowledge/dokumen` | `Knowledge` | JWT (all roles) |

### Response Envelope

All endpoints should return `{"status": <http_code>, "message": "...", "data": ...}`. `data` is `{}` (or `(object)[]` / `new stdClass()` in PHP) when empty — never `null` or `[]`, for Flutter compatibility.

### Controllers Pattern

Each controller follows this structure:
1. CORS headers in constructor (`Access-Control-Allow-Origin: *`, handle OPTIONS preflight)
2. Load helpers: `jwt`, `uuid`, `string`, `base64_file` as needed
3. Auth via `get_jwt_payload($this)` or manual `$this->jwt->decode()`
4. Role/jurisdiction gating before business logic
5. Type-cast INT columns for Flutter JSON parsing (especially IDs)
6. `http_response_code()` set before `echo json_encode()`

### Database & Seeder

**Schema migration** is handled via `database/v{n}/` SQL files. The seeder (`application/controllers/Seeder.php`) uses `CREATE TABLE IF NOT EXISTS` with `ALTER TABLE` column checks, making it a migration tool as well. It seeds:
- 38 Polda (national coverage) with lat/lng coordinates + 76 Polres
- 13 ranks (Pangkat), 8 positions (Jabatan), 2 weapon categories
- 50 personnel, 35 weapons, 31 ammo batches, 25 animals (K9 + Turangga), 30 Sarpras
- 30 SITKAMTIBMAS reports, 25 DMS letters, 25 public complaints

### File Uploads

All file uploads use base64-encoded payloads in JSON body (not multipart). The `save_base64_file()` helper in `application/helpers/base64_file_helper.php` handles decoding, MIME validation via `finfo`, size checking, and writes to `uploads/{senjata|dms|sitkamtibmas|satwa}/`.

### Testing

Playwright TypeScript E2E tests in `tests/api/`. The test server is PHP's built-in server with `tests/router.php` which strips CI3's error wrapper HTML around JSON output. The seeder runs in `beforeAll` to reset state.

### Custom Libraries & Helpers

- `application/libraries/Jwt.php` — JWT decode only (HS256), class-based
- `application/helpers/jwt_helper.php` — JWT encode + `get_jwt_payload()` smart extraction + base64url functions
- `application/helpers/uuid_helper.php` — `generate_uuid4()` via `random_bytes`
- `application/helpers/base64_file_helper.php` — `save_base64_file()` with MIME/size validation
- `application/helpers/string_helper.php` — `randomString()` helper

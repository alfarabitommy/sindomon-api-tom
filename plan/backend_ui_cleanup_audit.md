# Backend UI Cleanup Audit

**Date:** 2025-07-14  
**Scope:** Profile endpoint, Logout mechanism, Legacy Laporan controllers  
**Status:** DEBUG / PLAN MODE — no code changes

---

## 1. Legacy "Laporan" Controller

### Finding: No `Laporan.php` controller exists

The list of controllers in `application/controllers/` is:

```
Auth.php, Dashboard.php, Dms.php, Kamtibmas.php, Knowledge.php,
Logistik.php, Master.php, Pengaduan.php, Polda.php, Profile.php,
Role.php, Sdm.php, Seeder.php, Welcome.php
```

The word "laporan" appears only as:
- A **route** in `routes.php` line 80: `$route['api/v1/kamtibmas/laporan']['POST'] = 'kamtibmas/laporan';` — this is the SITKAMTIBMAS report endpoint served by `Kamtibmas.php`, not a standalone controller.
- **Seed data strings** in `Seeder.php` (e.g., `'Laporan Intelijen Mingguan...'`, `'Laporan pencurian...'`).

**Verdict:** ✅ Nothing to delete. There is no legacy/dummy Laporan controller.

---

## 2. Profile Endpoint (`GET /api/v1/profile`)

### Route

```php
// routes.php line 65
$route['api/v1/profile']['get'] = 'profile/get';
```

### Controller: `Profile.php` → `Profile::get()`

```php
// application/controllers/Profile.php (lines 25-36)
public function get()
{
    $headers = $this->input->request_headers();
    if($headers != null){
        $authorization = $headers['Authorization'];
        $data = $this->db->query("select * from tbl_users where token = '".$authorization."'")->result_array();
        echo json_encode(array("message"=> "success", "status" => 200 , "data" => $data));
    }else{
        http_response_code(401);
        echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
    }
}
```

### Critical Issues

| # | Issue | Severity | Detail |
|---|-------|----------|--------|
| 1 | **Uses legacy token auth, not JWT** | 🔴 HIGH | Matches the raw `Authorization` header directly against `tbl_users.token`. Every other protected endpoint uses `get_jwt_payload($this)` with HS256 JWT. This is a completely different (and obsolete) auth mechanism. |
| 2 | **Leaks password hash** | 🔴 CRITICAL | `SELECT *` returns ALL columns including `password` (bcrypt hash). The login endpoint in `Auth.php` correctly calls `unset($check[0]['password'])`, but Profile does not. |
| 3 | **No JOINs — missing rich profile data** | 🟡 MEDIUM | Returns raw `tbl_users` columns only. It does **NOT** return: `role_name` (from `tbl_role`), `nama_polda` (from `tbl_polda`), or `is_2fa_enabled` (column does not exist anywhere in the schema). |
| 4 | **No role/jurisdiction gating** | 🟡 MEDIUM | Unlike every other endpoint, there is no `get_jwt_payload()` call, no role check, no jurisdiction enforcement. Any valid `token` string grants access. |
| 5 | **SQL injection** | 🟠 MEDIUM-HIGH | `$authorization` is interpolated directly into the SQL string with no escaping. While the token comes from an HTTP header (not directly user-typed), this is still a bad practice. |

### What the Profile Endpoint Currently Returns

Based on the `tbl_users` schema (v5: `id`, `username`, `password`, `roles_id`, `polda_id`, `uuid`, `token`, `expired`, `created_at`) plus runtime columns possibly added (`is_active`, `updated_at`), the response looks like:

```json
{
  "status": 200,
  "message": "success",
  "data": [{
    "id": 4,
    "username": "admin",
    "password": "$2y$10$...",       // ⚠️ PASSWORD HASH LEAKED
    "roles_id": 1,
    "polda_id": null,
    "uuid": "7997324a-...",
    "token": "3b9ecb26465fada...",
    "expired": "30",
    "created_at": "2026-07-11 16:07:10"
  }]
}
```

### What the Profile Endpoint SHOULD Return (per PRD 1.3)

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "username": "admin",
    "role_name": "Super Admin",       // JOIN tbl_role
    "nama_polda": "Polda Metro Jaya", // JOIN tbl_polda
    "is_2fa_enabled": false            // column does NOT exist yet
  }
}
```

### Gap Analysis

| PRD Field | Currently Returned? | Source |
|-----------|-------------------|--------|
| `username` | ✅ Yes | `tbl_users.username` |
| `role_name` | ❌ No (returns `roles_id` integer) | Needs JOIN `tbl_role.roles` |
| `nama_polda` | ❌ No (returns `polda_id` integer/null) | Needs JOIN `tbl_polda.nama_polda` |
| `is_2fa_enabled` | ❌ No (column does not exist) | Needs schema migration + logic |

**Action required:** The entire `Profile::get()` method needs to be rewritten to use JWT auth, JOINs, and the standard response envelope. Alternatively, the profile endpoint could be moved into `Auth.php` (as `auth/profile`) to consolidate auth-related endpoints. `Profile.php` could then be deleted as a controller.

---

## 3. Logout Mechanism

### Finding: No server-side logout exists

- **No `logout` route** in `routes.php`. All 50+ routes were reviewed — none relate to logout.
- **No `logout()` method** in any controller (`Auth.php`, `Profile.php`, etc.).
- **No token blacklisting** mechanism. Grep for `logout|blacklist|token_invalidate|invalidate` across the entire `application/` directory returned **zero matches**.
- JWT tokens are generated in `Auth::login()` with `'exp' => time() + 3600` (1-hour TTL). They are stateless — the server never stores them in a database or cache.

### How Logout Works Currently

Logout is **entirely client-side**:
1. Flutter app deletes the JWT from local storage (`SharedPreferences` / `flutter_secure_storage`).
2. The token remains valid until it naturally expires (max 1 hour after issue).
3. There is no way to revoke a token before expiry.

### Implications

| Concern | Risk |
|---------|------|
| Stolen token reuse | Token works until expiry (up to 1 hour) — no way to revoke |
| "Logout everywhere" | Not possible without server-side state |
| Token leaked in logs | No invalidation mechanism |

**Action required (if desired):** Implement a token blacklist table (e.g., `tbl_token_blacklist` with `token_jti`, `expires_at`) and a `POST /api/v1/auth/logout` endpoint. This is **optional** for the UI cleanup — the PRD does not mandate server-side logout. Flutter's client-side logout is the current working approach.

---

## 4. Summary of Recommended Actions

| # | Action | Priority | Effort |
|---|--------|----------|--------|
| 1 | Rewrite `Profile::get()` to use JWT auth + JOINs + safe column selection | 🔴 P0 | Small |
| 2 | Remove `password`, `token`, `uuid`, `expired` from profile response | 🔴 P0 | Small |
| 3 | Add `is_2fa_enabled` column to `tbl_users` (schema migration) | 🟡 P1 | Small |
| 4 | Consider merging profile into `Auth.php` as `auth/profile` and deleting `Profile.php` | 🟢 P2 | Small |
| 5 | Consider adding `POST /api/v1/auth/logout` with token blacklisting | 🟢 P3 | Medium |
| 6 | No Laporan controller to delete — nothing to do | ✅ N/A | Zero |

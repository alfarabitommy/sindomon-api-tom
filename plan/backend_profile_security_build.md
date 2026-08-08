# Backend Profile Security Build

**Date:** 2025-07-14  
**File changed:** `application/controllers/Profile.php`  
**Status:** ✅ EXECUTED & VERIFIED — live smoke test passed

---

## 1. Vulnerability Fixed

**Before (legacy):** `Profile::get()` authenticated by matching the raw `Authorization` header against `tbl_users.token`, then ran `SELECT *` — leaking the bcrypt **password hash**, `token`, and `uuid` to any caller with a valid session token string. It also bypassed the entire JWT/role system and returned no enriched profile data (`role_name`, `nama_polda`).

**After (secure):** JWT authentication via `get_jwt_payload($this)` (HS256, shared with every other protected endpoint), explicit column selection, and LEFT JOINs to `tbl_role` + `tbl_polda`.

---

## 2. Security Properties

| Property | Implementation |
|----------|----------------|
| Auth | `get_jwt_payload($this)` — accepts `Bearer <token>` and raw token (Flutter) |
| No `SELECT *` | Explicit columns only: `u.id, u.username, r.roles, p.nama_polda, is_2fa_enabled` |
| Password hash | **Never** selected or returned |
| `is_2fa_enabled` | INFORMATION_SCHEMA existence check → falls back to `0` (false) if the column is missing |
| Envelope | `{"status", "message", "data"}` with `data` as JSON **object** (Flutter-compatible) |
| Error handling | 401 invalid/missing token · 404 user not found |
| SQL injection | User input never reaches SQL — `uid` comes from the verified JWT payload and is `(int)` cast |

---

## 3. Full Secure Code — `application/controllers/Profile.php`

```php
<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Profile extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: false");

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $this->config->load('jwt');
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->library('jwt');
        $this->load->helper('uuid');
        $this->load->helper('string');
        $this->load->helper('jwt');
    }

    public function get()
    {
        // ── 1. JWT authentication (replaces legacy raw-token DB lookup) ──
        $payload = get_jwt_payload($this);
        if ($payload === null || !isset($payload['uid'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        $user_id = (int) $payload['uid'];

        // ── 2. Safe is_2fa_enabled handling ──
        // Column does not exist yet in the schema; fall back to 0 (false)
        // so the query never fails on environments without the migration.
        $has_2fa = $this->db->query(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tbl_users'
               AND COLUMN_NAME = 'is_2fa_enabled'"
        )->num_rows() > 0;
        $select_2fa = $has_2fa ? 'u.is_2fa_enabled' : '0 AS is_2fa_enabled';

        // ── 3. Explicit column SELECT with JOINs — NEVER SELECT * ──
        // Explicit columns prevent leaking the password hash, token, uuid, etc.
        $this->db->select('u.id, u.username, r.roles AS role_name, p.nama_polda, ' . $select_2fa);
        $this->db->from('tbl_users u');
        $this->db->join('tbl_role r', 'u.roles_id = r.id', 'left');
        $this->db->join('tbl_polda p', 'u.polda_id = p.id', 'left');
        $this->db->where('u.id', $user_id);
        $user = $this->db->get()->row_array();

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'status' => 404,
                'message' => 'User tidak ditemukan.',
                'data' => (object)[]
            ]);
            return;
        }

        // ── 4. Type-cast for Flutter JSON compatibility ──
        $profile = [
            'id'              => (int) $user['id'],
            'username'        => $user['username'],
            'role_name'       => $user['role_name'] !== null ? (string) $user['role_name'] : '',
            'nama_polda'      => $user['nama_polda'] !== null ? (string) $user['nama_polda'] : '',
            'is_2fa_enabled'  => (bool) $user['is_2fa_enabled']
        ];

        http_response_code(200);
        echo json_encode([
            'status' => 200,
            'message' => 'success',
            'data' => $profile
        ]);
    }
}
```

---

## 4. Verification — Live Smoke Test

Environment: `php -S 127.0.0.1:8099 tests/router.php` against local `sindomondb` (MySQL, root/root).

### 4.1 Valid JWT — raw format (Flutter sends token directly)

```bash
curl http://127.0.0.1:8099/api/v1/profile -H "Authorization: <jwt>"
```

**Response (Super Admin, `polda_id` NULL):**
```json
{"status":200,"message":"success","data":{"id":4,"username":"admin","role_name":"Super Admin","nama_polda":"","is_2fa_enabled":false}}
```

**Response (Operator, `polda_id` = 12):**
```json
{"status":200,"message":"success","data":{"id":23,"username":"operator_test","role_name":"Operator Polda","nama_polda":"Polda Banten","is_2fa_enabled":false}}
```

### 4.2 Valid JWT — `Bearer` format

```json
{"status":200,"message":"success","data":{"id":4,"username":"admin","role_name":"Super Admin","nama_polda":"","is_2fa_enabled":false}}
```

### 4.3 No token → 401

```json
{"status":401,"message":"Token tidak ditemukan atau tidak valid.","data":{}}
```
`HTTP 401` ✅

### 4.4 Garbage token → 401

```json
{"status":401,"message":"Token tidak ditemukan atau tidak valid.","data":{}}
```
`HTTP 401` ✅

### 4.5 Checks

- ✅ `php -l application/controllers/Profile.php` → *No syntax errors detected*
- ✅ No `password`, `token`, or `uuid` fields in any response (no `SELECT *`)
- ✅ `data` is a JSON **object**, never an array
- ✅ `is_2fa_enabled` falls back to `false` — confirmed column **not present** in live `tbl_users` schema
- ✅ Both auth header formats work through `get_jwt_payload()` (Bearer + raw)

---

## 5. Follow-ups (not in scope of this fix)

| Item | Notes |
|------|-------|
| `is_2fa_enabled` migration | Add the column to `tbl_users` (e.g., `database/v{n}/`) when 2FA lands; the code will pick it up automatically via the INFORMATION_SCHEMA check |
| Legacy `tbl_users.token` column | Now only used by `Auth::insert_user()`; can be dropped once no legacy client depends on it |
| `nama_polda` empty string | Correct behavior for users without `polda_id` (Super Admin / Eksekutif); frontend should handle empty string |

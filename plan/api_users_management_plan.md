# User Management API — Edit & Soft Delete Endpoints

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Super Admin-protected Edit User (PUT) and Soft Delete (`is_active=0`) API endpoints so the Flutter frontend's "Edit" and "Delete" buttons work.

**Architecture:** Add `is_active` column to `tbl_users` via the Seeder `_ensure_tables()` INFORMATION_SCHEMA pattern, then implement `user_put($id)` and `user_delete($id)` in the existing `Auth` controller following Master.php's Super Admin gate pattern. Secure existing `all()` and `insert_user()` with JWT auth.

**Tech Stack:** CodeIgniter 3, MySQL, PHP JWT (HS256), bcrypt (`password_hash`/`password_verify`)

## Global Constraints

- PHP >= 5.3.7, MySQL `sindomondb`
- Reuse `get_jwt_payload($this)` from `application/helpers/jwt_helper.php` for auth
- Follow Master.php pattern: `http_response_code()` + short-array `json_encode()` + `(object)[]` for empty data
- Raw SQL via `$this->db->query()` or query builder — no ORM, no models
- Flutter compatibility: empty data serializes to `{}`, never `[]` or `null`; all IDs cast to `(int)`
- Responses: `{"status": <http_code>, "message": "...", "data": ...}`

---

## 1. Backend Audit

### 1.1 Current Routes (`application/config/routes.php`)

| Route | Method | Target | Auth? |
|---|---|---|---|
| `api/v1/auth/login` | any | `auth/login` | Public |
| `api/v1/auth/insert` | any | `auth/insert_user` | **None** |
| `api/v1/user` | any | `auth/all` | **None** |

**No routes exist for updating or deleting a user.** No routes with `(:num)` parameter for user-by-id.

### 1.2 Current Controller (`application/controllers/Auth.php`)

| Method | What it does | Security issues |
|---|---|---|
| `login()` | Verifies username/password, returns JWT + full user row (including password hash) | SQL injection (raw concat), no response on unknown username, JWT issued before password verify, password hash leaked in response |
| `insert_user()` | Creates user with `username, password, roles_id, uuid, token, expired, created_at` | **No auth**, no `polda_id` support, SQL injection, no duplicate-username check, no role existence check |
| `all()` | Returns `SELECT * FROM tbl_users` — all rows including bcrypt hashes | **No auth**, dumps password hashes to anyone |

Constructor loads `jwt`, `uuid`, `string` helpers and `session` library, but has **no CORS headers, no OPTIONS preflight, and does not load the `jwt` library.**

### 1.3 Database Schema (`tbl_users`)

Current columns (v5): `id, username, password, roles_id, polda_id, uuid, token, expired, created_at`

- **No `is_active` column** — confirmed by grep across entire codebase (zero hits for `is_active`, `deleted_at`, `is_deleted`)
- **No `updated_at` column**
- No foreign keys on `roles_id` (references `tbl_role.id`) or `polda_id` (references `tbl_polda.id`)
- Soft delete is not used anywhere in the project

### 1.4 Reference Pattern: Master.php Super Admin Gate

File: `application/controllers/Master.php`

Every write endpoint uses this exact block (appears 6 times — lines 58-66, 116-124, 171-179, 297-305, 345-353, 401-409):

```php
$payload = get_jwt_payload($this);

if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
    http_response_code(403);
    echo json_encode([
        'status' => 403,
        'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
        'data' => (object)[]
    ]);
    return;
}
```

### 1.5 Reference Pattern: Seeder Conditional ALTER TABLE

File: `application/controllers/Seeder.php`, lines 60-66

```php
$has_lat = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_polda'
    AND COLUMN_NAME = 'latitude'")->num_rows();
if (!$has_lat) {
    $this->db->query("ALTER TABLE `tbl_polda` ADD COLUMN `latitude` varchar(100) DEFAULT NULL AFTER `nama_polda`");
    $this->db->query("ALTER TABLE `tbl_polda` ADD COLUMN `longitude` varchar(100) DEFAULT NULL AFTER `latitude`");
}
```

---

## 2. Gap Analysis

### 2.1 Missing Endpoints

| Need | Status | What's required |
|---|---|---|
| Edit User (PUT) | **Missing** | Endpoint to update username, password, role_id, polda_id for a user by ID. Must hash password if provided. |
| Delete User (DELETE) | **Missing** | Endpoint to deactivate a user. Must be soft delete (`is_active = 0`) since users may be referenced by logs/audit trails. |
| User by ID retrieval | **Missing** | Route with `(:num)` parameter — currently only `api/v1/user` (list all) exists. |

### 2.2 Schema Gap

- **No `is_active` column**: Must be added to `tbl_users`. The project does not use CI3 migrations — schema changes go through the Seeder's `_ensure_tables()` INFORMATION_SCHEMA pattern.
- **No `updated_at` column**: While not strictly required for the Flutter buttons, it's standard practice. The plan adds it alongside `is_active`.

### 2.3 Security Gaps on Existing Endpoints

- `all()` is unauthenticated and returns password hashes → must add JWT auth + exclude `password` column
- `insert_user()` is unauthenticated → must add Super Admin gate
- Auth controller lacks CORS headers and JWT library loading → must add constructor setup

### 2.4 Self-Deletion Protection

A Super Admin must not be able to soft-delete their own account — this would lock them out permanently. The plan adds a self-deletion check comparing `$id` with JWT `uid`.

### 2.5 Password Update Handling

If the Edit endpoint receives a `password` field, it must be hashed with `password_hash(..., PASSWORD_DEFAULT)` before updating. If `password` is empty or omitted, the password column should NOT be updated (partial update pattern).

---

## 3. Implementation Plan

### Task 1: Add `is_active` and `updated_at` columns to `tbl_users`

**Files:**
- Modify: `application/controllers/Seeder.php` (add ALTER block in `_ensure_tables()`)
- Modify: `application/controllers/Auth.php` (update `insert_user()` to set `is_active = 1`)
- Modify: `application/controllers/Auth.php` (update `all()` to filter `is_active = 1` and exclude password)

**Schema migration strategy:** The Seeder's `_ensure_tables()` method is the project's established migration mechanism. We add columns conditionally using the INFORMATION_SCHEMA pattern.

- [ ] **Step 1: Add columns in `Seeder.php` `_ensure_tables()`**

In `application/controllers/Seeder.php`, inside `_ensure_tables()`, add after the existing polda ALTER blocks (after line 72):

```php
// v7: Add is_active + updated_at to tbl_users
$has_is_active = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_users'
    AND COLUMN_NAME = 'is_active'")->num_rows();
if (!$has_is_active) {
    $this->db->query("ALTER TABLE `tbl_users`
        ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1 AFTER `polda_id`");
}
$has_updated_at = $this->db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'sindomondb' AND TABLE_NAME = 'tbl_users'
    AND COLUMN_NAME = 'updated_at'")->num_rows();
if (!$has_updated_at) {
    $this->db->query("ALTER TABLE `tbl_users`
        ADD COLUMN `updated_at` datetime DEFAULT NULL AFTER `created_at`");
}
```

- [ ] **Step 2: Run seeder to apply schema**

```bash
php index.php seeder run
```

- [ ] **Step 3: Verify columns exist**

```sql
DESCRIBE tbl_users;
-- Expected: is_active (tinyint(1), DEFAULT 1), updated_at (datetime, DEFAULT NULL)
```

- [ ] **Step 4: Commit**

```bash
git add application/controllers/Seeder.php
git commit -m "feat: add is_active and updated_at columns to tbl_users for soft delete"
```

---

### Task 2: Secure existing `Auth` controller — add CORS, JWT library, auth gates

**Files:**
- Modify: `application/controllers/Auth.php` (constructor, `all()`, `insert_user()`)

- [ ] **Step 1: Update constructor with CORS + JWT library loading**

Replace the existing `__construct()` (lines 6-14) with:

```php
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
```

- [ ] **Step 2: Add JWT auth gate to `all()`**

Replace the existing `all()` method (lines 57-61) with:

```php
public function all()
{
    $payload = get_jwt_payload($this);
    if ($payload === null) {
        http_response_code(401);
        echo json_encode([
            'status' => 401,
            'message' => 'Token tidak ditemukan atau tidak valid.',
            'data' => (object)[]
        ]);
        return;
    }

    $data = $this->db->query(
        "SELECT id, username, roles_id, polda_id, uuid, token, expired, is_active, created_at, updated_at
         FROM tbl_users WHERE is_active = 1"
    )->result_array();

    // Cast integer columns for Flutter
    foreach ($data as &$row) {
        $row['id'] = (int) $row['id'];
        $row['roles_id'] = (int) $row['roles_id'];
        $row['polda_id'] = isset($row['polda_id']) ? (int) $row['polda_id'] : null;
        $row['is_active'] = (int) $row['is_active'];
    }

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar user berhasil dimuat.',
        'data' => $data
    ]);
}
```

- [ ] **Step 3: Add Super Admin gate to `insert_user()`**

Add after `$data = json_decode(...)` (after line 48, before the INSERT):

```php
// Super Admin gate
$payload = get_jwt_payload($this);
if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
    http_response_code(403);
    echo json_encode([
        'status' => 403,
        'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
        'data' => (object)[]
    ]);
    return;
}
```

Also update the INSERT query to include `polda_id` and `is_active`:

```php
$polda_id = isset($data['polda_id']) ? (int) $data['polda_id'] : null;
$rows = $this->db->query(
    "INSERT INTO tbl_users(username, password, roles_id, polda_id, uuid, token, expired, is_active, created_at)
     VALUES (
         '".$data['username']."',
         '".password_hash($data['password'], PASSWORD_DEFAULT)."',
         '".$data['roles_id']."',
         ".($polda_id !== null ? "'".$polda_id."'" : "NULL").",
         '".$h_uuid."',
         '".$r_string."',
         '30',
         1,
         '".date('Y-m-d H:i:s')."'
     )"
);
```

- [ ] **Step 4: Commit**

```bash
git add application/controllers/Auth.php
git commit -m "feat: add CORS, JWT auth to user list/create, hide password from all()"
```

---

### Task 3: Add Edit User endpoint (`PUT api/v1/user/(:num)`)

**Files:**
- Modify: `application/config/routes.php` (add route)
- Modify: `application/controllers/Auth.php` (add `user_put($id)` method)

**Interfaces:**
- Consumes: `get_jwt_payload($this)` from jwt_helper, `$this->db` query builder
- Produces: `PUT api/v1/user/(:num)` → `auth/user_put/$1`

- [ ] **Step 1: Add route in `routes.php`**

After the existing auth routes (after line 56), add:

```php
$route['api/v1/user/(:num)']['PUT']    = 'auth/user_put/$1';
```

- [ ] **Step 2: Add `user_put($id)` method to `Auth.php`**

Add before the closing `}` of the `Auth` class:

```php
public function user_put($id)
{
    $payload = get_jwt_payload($this);

    // ── 1. Super Admin gate ──
    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 2. User existence check ──
    $user = $this->db->get_where('tbl_users', ['id' => $id, 'is_active' => 1])->row_array();
    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'message' => 'User tidak ditemukan.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 3. Parse JSON input ──
    $input = json_decode($this->input->raw_input_stream, true);
    if (empty($input)) {
        http_response_code(400);
        echo json_encode([
            'status' => 400,
            'message' => 'Request body harus berupa JSON.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 4. Build update data (partial update — only update provided fields) ──
    $update_data = [];
    $update_data['updated_at'] = date('Y-m-d H:i:s');

    // username
    if (isset($input['username']) && trim($input['username']) !== '') {
        $new_username = trim($input['username']);

        // Uniqueness check (exclude self)
        $dup = $this->db->query(
            "SELECT id FROM tbl_users WHERE username = '".$new_username."' AND id != ".(int)$id
        )->num_rows();
        if ($dup > 0) {
            http_response_code(409);
            echo json_encode([
                'status' => 409,
                'message' => 'Username sudah digunakan oleh user lain.',
                'data' => (object)[]
            ]);
            return;
        }

        $update_data['username'] = $new_username;
    }

    // password (only update if non-empty)
    if (isset($input['password']) && trim($input['password']) !== '') {
        $update_data['password'] = password_hash(trim($input['password']), PASSWORD_DEFAULT);
    }

    // roles_id
    if (isset($input['roles_id'])) {
        $roles_id = (int) $input['roles_id'];

        // Validate role exists
        $role_exists = $this->db->get_where('tbl_role', ['id' => $roles_id])->num_rows();
        if ($role_exists === 0) {
            http_response_code(422);
            echo json_encode([
                'status' => 422,
                'message' => 'Role tidak ditemukan. Gunakan roles_id 1 (Super Admin), 2 (Operator Polda), atau 3 (Eksekutif).',
                'data' => (object)[]
            ]);
            return;
        }

        $update_data['roles_id'] = $roles_id;
    }

    // polda_id
    if (array_key_exists('polda_id', $input)) {
        $polda_id = $input['polda_id'] !== null && $input['polda_id'] !== '' ? (int) $input['polda_id'] : null;

        if ($polda_id !== null) {
            $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
            if ($polda_exists === 0) {
                http_response_code(422);
                echo json_encode([
                    'status' => 422,
                    'message' => 'Polda tidak ditemukan.',
                    'data' => (object)[]
                ]);
                return;
            }
        }

        $update_data['polda_id'] = $polda_id;
    }

    // ── 5. Reject if nothing to update ──
    if (count($update_data) <= 1) { // only updated_at was set
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Tidak ada field yang valid untuk diperbarui.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 6. Execute update ──
    $this->db->where('id', $id)->update('tbl_users', $update_data);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Data user berhasil diperbarui.',
        'data' => (object)[]
    ]);
}
```

- [ ] **Step 3: Verify with curl**

```bash
# Get a Super Admin JWT first
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}' | jq -r '.data.jwt_token')

# Edit user (update role)
curl -s -X PUT "http://localhost:8080/api/v1/user/5" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"roles_id": 2}' | jq .
# Expected: {"status":200,"message":"Data user berhasil diperbarui.","data":{}}
```

- [ ] **Step 4: Commit**

```bash
git add application/config/routes.php application/controllers/Auth.php
git commit -m "feat: add PUT /api/v1/user/(:num) Super Admin edit endpoint"
```

---

### Task 4: Add Soft Delete User endpoint (`DELETE api/v1/user/(:num)`)

**Files:**
- Modify: `application/config/routes.php` (add route)
- Modify: `application/controllers/Auth.php` (add `user_delete($id)` method)

**Interfaces:**
- Consumes: `get_jwt_payload($this)`, `$this->db` query builder
- Produces: `DELETE api/v1/user/(:num)` → `auth/user_delete/$1`

- [ ] **Step 1: Add route in `routes.php`**

After the PUT route (added in Task 3), add:

```php
$route['api/v1/user/(:num)']['DELETE'] = 'auth/user_delete/$1';
```

- [ ] **Step 2: Add `user_delete($id)` method to `Auth.php`**

Add before the closing `}` of the `Auth` class:

```php
public function user_delete($id)
{
    $payload = get_jwt_payload($this);

    // ── 1. Super Admin gate ──
    if ($payload === null || !isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 2. User existence check (only active users) ──
    $user = $this->db->get_where('tbl_users', ['id' => $id, 'is_active' => 1])->row_array();
    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 404,
            'message' => 'User tidak ditemukan atau sudah dinonaktifkan.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 3. Prevent self-deletion ──
    $requester_uid = (int) $payload['uid'];
    if ((int) $id === $requester_uid) {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Anda tidak dapat menonaktifkan akun sendiri.',
            'data' => (object)[]
        ]);
        return;
    }

    // ── 4. Soft delete: set is_active = 0 ──
    $this->db->where('id', $id)->update('tbl_users', [
        'is_active' => 0,
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'User berhasil dinonaktifkan.',
        'data' => (object)[]
    ]);
}
```

- [ ] **Step 3: Verify with curl**

```bash
# Soft delete a user
curl -s -X DELETE "http://localhost:8080/api/v1/user/5" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" | jq .
# Expected: {"status":200,"message":"User berhasil dinonaktifkan.","data":{}}

# Verify self-deletion is blocked (assuming admin has uid=4)
curl -s -X DELETE "http://localhost:8080/api/v1/user/4" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" | jq .
# Expected: {"status":422,"message":"Anda tidak dapat menonaktifkan akun sendiri.","data":{}}

# Verify deleted user no longer appears in list
curl -s "http://localhost:8080/api/v1/user" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | map(select(.id == 5))'
# Expected: []
```

- [ ] **Step 4: Commit**

```bash
git add application/config/routes.php application/controllers/Auth.php
git commit -m "feat: add DELETE /api/v1/user/(:num) soft delete endpoint"
```

---

### Task 5: Update login to reject inactive users

**Files:**
- Modify: `application/controllers/Auth.php` (`login()` method)

Currently `login()` only checks username. Add an `is_active = 1` filter so deactivated users cannot log in.

- [ ] **Step 1: Update login query**

Change line 20 from:
```php
$sql = $this->db->query("select * from tbl_users where username = '".$data['username']."'");
```
To:
```php
$sql = $this->db->query("select * from tbl_users where username = '".$data['username']."' AND is_active = 1");
```

- [ ] **Step 2: Add "inactive user" error message**

Add after the `num_rows() > 0` check (after line 21), before the success path:

```php
if ($sql->num_rows() === 0) {
    // Check if user exists but is inactive
    $inactive = $this->db->query("select id from tbl_users where username = '".$data['username']."' AND is_active = 0");
    if ($inactive->num_rows() > 0) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akun telah dinonaktifkan. Hubungi administrator.',
            'data' => (object)[]
        ]);
        return;
    }

    http_response_code(401);
    echo json_encode([
        'status' => 401,
        'message' => 'Username atau password salah.',
        'data' => (object)[]
    ]);
    return;
}
```

- [ ] **Step 3: Commit**

```bash
git add application/controllers/Auth.php
git commit -m "feat: reject login for inactive users, proper error on unknown username"
```

---

### Task 6: Run E2E tests and verify

- [ ] **Step 1: Run existing Playwright test suite**

```bash
npm test
```

Ensure no existing tests break from the changes (the test seeder uses `DELETE FROM tbl_users` — hard delete — which should still work since `is_active` has no FK constraints).

- [ ] **Step 2: Manual end-to-end smoke test**

```bash
# 1. Login as Super Admin
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}' | jq -r '.data.jwt_token')
echo "Token: ${TOKEN:0:20}..."

# 2. List users (should not contain passwords)
curl -s "http://localhost:8080/api/v1/user" \
  -H "Authorization: Bearer $TOKEN" | jq '.data[0] | keys'
# Expected: no "password" key

# 3. Edit a user's role
curl -s -X PUT "http://localhost:8080/api/v1/user/5" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"roles_id": 3}' | jq .
# Expected: 200

# 4. Soft delete a user
curl -s -X DELETE "http://localhost:8080/api/v1/user/5" \
  -H "Authorization: Bearer $TOKEN" | jq .
# Expected: 200

# 5. Verify deleted user is gone from list
curl -s "http://localhost:8080/api/v1/user" \
  -H "Authorization: Bearer $TOKEN" | jq '.data | map(select(.id == 5))'
# Expected: []

# 6. Verify non-admin cannot edit/delete
# (login as operator_polda and try PUT/DELETE → 403)
```

- [ ] **Step 3: Commit any remaining changes**

---

## 4. Summary of Changes

| File | Action | Change |
|---|---|---|
| `application/controllers/Seeder.php` | Modify | Add `is_active` + `updated_at` ALTER blocks in `_ensure_tables()` |
| `application/controllers/Auth.php` | Modify | CORS + JWT library in constructor; auth gate on `all()`; Super Admin gate on `insert_user()`; exclude password from `all()`; add `polda_id` + `is_active` to `insert_user()`; add `user_put($id)`; add `user_delete($id)`; filter inactive users in `login()`; proper error messages |
| `application/config/routes.php` | Modify | Add `PUT api/v1/user/(:num)` and `DELETE api/v1/user/(:num)` |

### New API Surface

| Route | Method | Auth | Description |
|---|---|---|---|
| `api/v1/user` | GET | JWT (any role) | List active users (no password) |
| `api/v1/auth/insert` | POST | JWT (Super Admin) | Create user |
| `api/v1/user/(:num)` | PUT | JWT (Super Admin) | Edit user (partial update) |
| `api/v1/user/(:num)` | DELETE | JWT (Super Admin) | Soft delete user (`is_active=0`) |
| `api/v1/auth/login` | POST | Public | Login (rejects inactive users) |

# Backend Auth Security Build

**Date:** 2025-07-14  
**File changed:** `application/controllers/Auth.php`  
**Status:** ✅ EXECUTED & VERIFIED — live smoke test + Playwright regression + security review

---

## 1. Changes Made

### 1.1 `insert_user()` — SQL Injection Eliminated (Query Builder)

**Removed:** both raw `$this->db->query()` calls with string interpolation.

| Before (vulnerable) | After (parameterized) |
|---------------------|----------------------|
| `$this->db->query("SELECT id FROM tbl_users WHERE username = '".$data['username']."'")` | `$this->db->get_where('tbl_users', ['username' => $data['username']])` |
| `$this->db->query("INSERT INTO tbl_users(...) VALUES ('".$data['username']."', '".password_hash(...)."', '".$data['roles_id']."', ...)")` | `$this->db->insert('tbl_users', $insert_data)` (associative array — auto-escaped) |

**Bonus hardening:**
- `roles_id` is now `(int)` cast before insert (previously string-interpolated).
- `polda_id` empty-string/`null` → inserts `NULL` (previously could insert `'0'`).
- The 201 response no longer echoes the plaintext `password` back (`unset($data['password'])`).
- RBAC gate unchanged: `$payload['role_id'] !== 1` → 403.

### 1.2 `all()` — RBAC Gap Closed

Added the Super Admin gate immediately after the JWT validity check:

```php
// ── Super Admin gate (only Super Admin may list all users) ──
if (!isset($payload['role_id']) || $payload['role_id'] !== 1) {
    http_response_code(403);
    echo json_encode([
        'status' => 403,
        'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
        'data' => (object)[]
    ]);
    return;
}
```

---

## 2. Rewritten Methods (Full Code)

### `insert_user()`

```php
public function insert_user()
{
    // ── Super Admin gate ──
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

    $h_uuid = generate_uuid4();
    $r_string = randomString();
    $data = json_decode($this->input->raw_input_stream, true);

    if (empty($data) || empty($data['username']) || empty($data['password']) || empty($data['roles_id'])) {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Validasi gagal. Field username, password, dan roles_id wajib diisi.',
            'data' => (object)[]
        ]);
        return;
    }

    // Check duplicate username (Query Builder — parameterized, no raw SQL)
    $dup = $this->db->get_where('tbl_users', ['username' => $data['username']])->num_rows();
    if ($dup > 0) {
        http_response_code(409);
        echo json_encode([
            'status' => 409,
            'message' => 'Username sudah digunakan.',
            'data' => (object)[]
        ]);
        return;
    }

    // Validate roles_id exists
    $role_exists = $this->db->get_where('tbl_role', ['id' => $data['roles_id']])->num_rows();
    if ($role_exists === 0) {
        http_response_code(422);
        echo json_encode([
            'status' => 422,
            'message' => 'Role tidak ditemukan.',
            'data' => (object)[]
        ]);
        return;
    }

    $polda_id = isset($data['polda_id']) && $data['polda_id'] !== '' && $data['polda_id'] !== null
        ? (int) $data['polda_id']
        : null;

    // Insert via Query Builder — parameterized, no string interpolation
    $insert_data = [
        'username'   => $data['username'],
        'password'   => password_hash($data['password'], PASSWORD_DEFAULT),
        'roles_id'   => (int) $data['roles_id'],
        'polda_id'   => $polda_id,
        'uuid'       => $h_uuid,
        'token'      => $r_string,
        'expired'    => '30',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $rows = $this->db->insert('tbl_users', $insert_data);

    if ($rows) {
        // Never echo the plaintext password back in the response
        unset($data['password']);
        http_response_code(201);
        echo json_encode([
            'status' => 201,
            'message' => 'User berhasil dibuat.',
            'data' => $data
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 500,
            'message' => 'Gagal membuat user.',
            'data' => (object)[]
        ]);
    }
}
```

### `all()` (gate only — the rest of the method is unchanged)

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

    // ── Super Admin gate (only Super Admin may list all users) ──
    if (!isset($payload['role_id']) || $payload['role_id'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'status' => 403,
            'message' => 'Akses ditolak. Anda tidak memiliki otoritas Super Admin.',
            'data' => (object)[]
        ]);
        return;
    }

    // --- Pagination & real-time search query parameters ---
    $search = trim((string) $this->input->get('search'));
    $page   = max(1, (int) ($this->input->get('page') ?? 1));
    $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // --- Build query BEFORE counting (JOINs + filters) ---
    $this->db->from('tbl_users u');
    $this->db->join('tbl_role r', 'u.roles_id = r.id', 'left');
    $this->db->join('tbl_polda p', 'u.polda_id = p.id', 'left');
    $this->db->where('u.is_active', 1);

    // Optional real-time search: partial (LIKE) match on username.
    if ($search !== '') {
        $this->db->like('u.username', $search);
    }

    // Total rows matching the current filter. The FALSE second argument
    // preserves the Query Builder state (FROM/JOIN/WHERE/LIKE) for get()
    // below. Empty string is passed because from() is already set.
    $total_data = $this->db->count_all_results('', false);

    // --- Apply SELECT, LIMIT, ORDER AFTER counting ---
    // REMOVED uuid, token, expired — critical security fix (token leak).
    $this->db->select('u.id, u.username, u.roles_id, u.polda_id, r.roles as role_name, p.nama_polda, u.is_active, u.created_at, u.updated_at');
    $this->db->order_by('u.id', 'ASC');
    $this->db->limit($limit, ($page - 1) * $limit);

    $data = $this->db->get()->result_array();

    // Cast integer columns for Flutter compatibility
    foreach ($data as &$row) {
        $row['id'] = (int) $row['id'];
        $row['roles_id'] = (int) $row['roles_id'];
        $row['polda_id'] = isset($row['polda_id']) ? (int) $row['polda_id'] : null;
        $row['is_active'] = (int) $row['is_active'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar user berhasil dimuat.',
        'data' => [
            'items' => $data,
            'pagination' => [
                'total_data'   => (int) $total_data,
                'total_pages'  => (int) ceil($total_data / $limit),
                'current_page' => $page,
                'per_page'     => $limit,
            ]
        ]
    ]);
}
```

---

## 3. Verification

### 3.1 Live Smoke Test (real DB, PHP dev server)

| # | Scenario | Expected | Result |
|---|----------|----------|--------|
| 1 | Operator (role 2) → `POST /api/v1/auth/insert` | 403 | ✅ `{"status":403,...}` |
| 2 | Operator (role 2) → `GET /api/v1/user` | 403 | ✅ `{"status":403,...}` |
| 3 | SQLi payload in `username` (`sqli_x",\"roles_id\",1);-- -`) | literal insert, no injection | ✅ 201, username stored literally |
| 4 | Duplicate username | 409 | ✅ |
| 5 | Invalid `roles_id=99` | 422 | ✅ |
| 6 | Valid create (no polda) | 201, **no password in response** | ✅ `{"username":"fresh_user","roles_id":2}` |
| 7 | Valid create with `polda_id=12` | 201 | ✅ |
| 8 | Admin → `GET /api/v1/user` | 200 + items | ✅ |
| 9 | Newly created user logs in | 200 + JWT | ✅ (uid 26, role 2, polda 12) |

Test rows cleaned up afterward — DB restored to original 3 users.

### 3.2 Static Checks

- ✅ `php -l application/controllers/Auth.php` — no syntax errors
- ✅ No `$this->db->query()` with string interpolation remains in either method

### 3.3 Playwright Regression (`tests/api/seeder_master.spec.ts`)

| Run | Result |
|-----|--------|
| With my changes | 2 passed, 1 flaky (login latency), **1 failed** (SDM org-tree vacancy) |
| Baseline (original code, `git stash`) | 3 passed, **same SDM org-tree failure** |

The SDM org-tree failure is **pre-existing** — it reproduces identically on the untouched codebase and is unrelated to `Auth.php`.

### 3.4 Independent Security Review (subagent)

- ✅ Diff clean: SQLi removed, no password echo, gate correctly placed and typed
- ⚠️ **BLOCKING (pre-existing):** JWT secret is a hardcoded placeholder (`secret_key_yang_sangat_panjang_dan_aman` in `application/config/jwt.php`) — anyone with repo access can forge `role_id=1` tokens, bypassing every RBAC gate including this one. Fix: load from environment variable + rotate.
- ⚠️ LOW (pre-existing): array-typed `roles_id`/`username` produce 500s instead of clean 422s.
- ⚠️ NOTE (pre-existing): `login()` still uses raw SQL interpolation pre-auth.

---

## 4. Follow-ups (out of scope, pre-existing)

| # | Item | Priority |
|---|------|----------|
| 1 | Move JWT secret out of the repo into environment config; rotate the placeholder | 🔴 BLOCKING |
| 2 | Refactor `login()` raw SQL to Query Builder (`$this->db->get_where('tbl_users', ['username' => $data['username'], 'is_active' => 1])`) | 🟠 HIGH |
| 3 | Add scalar/type validation for `roles_id`, `username`, `polda_id` → clean 422 instead of DB error 500 | 🟡 LOW |

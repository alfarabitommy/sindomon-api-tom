# Backend RBAC Security Audit — User Creation

**Date:** 2025-07-14  
**Scope:** User creation endpoint RBAC, privilege escalation vectors, related Auth controller gates  
**Status:** 🔍 DEBUG / PLAN MODE — no code changes

---

## 1. User Creation Endpoint — Located & Verified

| Property | Value |
|----------|-------|
| Route | `POST /api/v1/auth/insert` |
| Controller method | `Auth::insert_user()` |
| File | `application/controllers/Auth.php` — line 106 |
| Last modified | As-is from original implementation |

**Route definition** (routes.php line 54):
```php
// ⚠️  No HTTP-method restriction — POST implied
$route['api/v1/auth/insert'] = 'auth/insert_user';
```

---

## 2. RBAC Gate Analysis — `insert_user()`

### Current Gate (lines 108–118)

```php
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
```

### Gate Evaluation

| Condition | Checked? | Detail |
|-----------|----------|--------|
| Missing/expired/invalid JWT | ✅ `$payload === null` | Rejects null from `get_jwt_payload()` |
| `role_id` absent from payload | ✅ `!isset($payload['role_id'])` | Defensive safety net (JWT always encodes `role_id`) |
| `role_id` ≠ 1 (Super Admin) | ✅ `$payload['role_id'] !== 1` | Strict integer comparison |
| HTTPS enforcement | ❌ | Not applicable to local dev |
| Rate limiting | ❌ | No rate limiting on this or any endpoint |

**Verdict: ✅ The `insert_user` RBAC gate is correct and complete.** Non-admin JWT tokens (role_id 2 or 3) receive HTTP 403 with the standard envelope. The "Tambah Akun" frontend form is a **frontend-only UI bug** — any non-admin submission will be rejected by the backend.

---

## 3. Full Auth Controller RBAC Matrix

To rule out privilege escalation through adjacent endpoints, here is every method in `Auth.php`:

| Method | Route | RBAC Gate | Risk |
|--------|-------|-----------|------|
| `login()` | `POST /api/v1/auth/login` | None (public) | ✅ By design |
| `insert_user()` | `POST /api/v1/auth/insert` | `role_id !== 1` → 403 | ✅ Locked |
| `all()` | `GET /api/v1/user` | **NONE** | 🟡 **GAP** (see §4) |
| `user_put($id)` | `PUT /api/v1/user/:id` | `role_id !== 1` → 403 | ✅ Locked |
| `user_delete($id)` | `DELETE /api/v1/user/:id` | `role_id !== 1` → 403 | ✅ Locked |

**Conclusion on user creation/management:** `insert_user` (create), `user_put` (update), and `user_delete` (soft-delete) all have strict Super Admin gates. No non-admin can create, edit, or deactivate users through these endpoints.

However, `all()` (list users) has **no role gate**, which is a separate RBAC gap.

---

## 4. Related RBAC Gap — `GET /api/v1/user` (User List)

### Issue

`Auth::all()` (lines 195–260) checks **only that the JWT is valid** — it does not restrict by `role_id`:

```php
public function all()
{
    $payload = get_jwt_payload($this);
    if ($payload === null) {
        http_response_code(401);
        // ...
        return;
    }
    // ⚠️ No `role_id` check — any role can proceed
    // ...fetches and returns all active users with roles/polda...
}
```

### What is Exposed

```php
$this->db->select('u.id, u.username, u.roles_id, u.polda_id, r.roles as role_name, p.nama_polda, u.is_active, u.created_at, u.updated_at');
```

An Operator Polda (role 2) or Eksekutif (role 3) can list **all** users across **all** Polda regions, including:
- Every username on the system
- Every role assignment
- Every Polda assignment
- Account activity timestamps

This violates the jurisdiction model used by the SDM and dashboard endpoints, where Operator Polda is restricted to their own `polda_id`.

### Severity

| Factor | Assessment |
|--------|------------|
| Exploitability | Trivial — any valid JWT works via a simple GET |
| Impact | Full user roster enumeration across all jurisdictions |
| Does it enable creation? | No — cannot create/modify users |
| Classification | **MEDIUM** — intelligence leak, not a write primitive |

---

## 5. SQL Injection — Second-Order Vulnerabilities (Bonus Finding)

While auditing `insert_user()` for RBAC, two SQL injection vectors were discovered in the **same method** — they are inside the Super Admin-only code path, so only a legitimate admin could exploit them, but they are still dangerous:

### 5.1 Duplicate Username Check (line 135–137)

```php
$dup = $this->db->query(
    "SELECT id FROM tbl_users WHERE username = '".$data['username']."'"
)->num_rows();
```

`$data['username']` comes directly from JSON input, is not escaped, and is interpolated into raw SQL. If an attacker gains Super Admin credentials, they could inject SQL through the username field.

### 5.2 INSERT Statement (lines 163–176)

```php
$rows = $this->db->query(
    "INSERT INTO tbl_users(username, password, roles_id, polda_id, uuid, token, expired, is_active, created_at)
     VALUES (
         '".$data['username']."',
         '".password_hash($data['password'], PASSWORD_DEFAULT)."',
         '".$data['roles_id']."',     // ⚠️  string-interpolated, not int-cast
         ".$polda_val.",               // ✅  int-cast before interpolation
         '".$h_uuid."',
         '".$r_string."',
         '30',
         1,
         '".date('Y-m-d H:i:s')."'
     )"
);
```

Same issue — `$data['username']` and `$data['roles_id']` are string-interpolated. The roles_id is validated via `get_where()` (line 149), but the check and the INSERT use different SQL patterns, creating a potential bypass.

| Severity | Notes |
|----------|-------|
| **MEDIUM-HIGH** | Requires Super Admin authentication to exploit (or a stolen admin JWT). The username field in particular is a classic SQLi entry point. |

---

## 6. Recommendation Blueprint

### P0 — Frontend Fix (Not This Audit)

The "Tambah Akun" form should be conditionally rendered by Flutter based on the JWT payload's `role_id`. Only `role_id === 1` should see the form. The backend gate already protects the endpoint — this is a UI leak, not a data breach.

### P1 — Lock Down `GET /api/v1/user`

Add role-based gating to `Auth::all()` — either:
- **Option A (simple):** Super Admin only, matching `insert_user` / `user_put` / `user_delete`.
- **Option B (jurisdiction-aware):** Super Admin sees all; Operator Polda sees only their `polda_id`; Eksekutif sees all (read-only by design per CLAUDE.md).

### P2 — Fix SQL Injection in `insert_user()`

- Use CodeIgniter Query Builder (`$this->db->insert()`) instead of raw SQL with string interpolation.
- Or at minimum, type-cast `$data['roles_id']` with `(int)` and escape `$data['username']` with `$this->db->escape_str()`.

### P3 — Add HTTP Method Restriction to Route

```php
// Current (ambiguous — POST is implied but not enforced)
$route['api/v1/auth/insert'] = 'auth/insert_user';

// Recommended
$route['api/v1/auth/insert']['POST'] = 'auth/insert_user';
```

---

## 7. Verdict

| Question | Answer |
|----------|--------|
| Can non-admins create users via the backend? | **No.** `role_id !== 1` gate correctly rejects them. |
| Is the frontend "Tambah Akun" a security breach? | **No.** It's a UI bug—the backend blocks the request. |
| Are there privilege escalation vectors? | **No** in write operations. `all()` leaks user list but cannot modify. |
| Are there other issues worth fixing? | **Yes** — SQLi in `insert_user()` + missing RBAC on `all()`. |

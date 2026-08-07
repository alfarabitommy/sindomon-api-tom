# Backend Users Pagination Audit

> **Endpoint:** `GET /api/v1/user`  
> **Controller::method:** `Auth::all()`  
> **File:** `application/controllers/Auth.php` (lines 195–227)  
> **Audit Date:** 2026-08-07

---

## 1. Current Logic

### 1.1 Route
```php
// application/config/routes.php:56
$route['api/v1/user'] = 'auth/all';
```

### 1.2 Auth Gate
- Calls `get_jwt_payload($this)` — any authenticated user (all 3 roles) can access.
- Returns 401 if token is missing/invalid.
- **No role-based restriction** — Super Admin, Operator Polda, and Eksekutif all see the same list.

### 1.3 Query (current)
```php
$data = $this->db->query(
    "SELECT id, username, roles_id, polda_id, uuid, token, expired, is_active, created_at, updated_at
     FROM tbl_users WHERE is_active = 1"
)->result_array();
```

| Feature | Status | Detail |
|---------|--------|--------|
| JOIN to `tbl_role` | ❌ Missing | `roles_id` returned as raw integer (1, 2, 3). Frontend sees IDs, not "Super Admin" / "Operator Polda" / "Eksekutif". |
| JOIN to `tbl_polda` | ❌ Missing | `polda_id` returned as raw integer or NULL. Frontend sees IDs or dashes, not "Polda Metro Jaya" etc. |
| Pagination (`LIMIT`/`OFFSET`) | ❌ Missing | Returns ALL active users in one flat array. No `page` or `limit` params. |
| Search (`LIKE` on username) | ❌ Missing | No `search` query param. No filtering beyond `is_active = 1`. |
| Count-first pattern | ❌ Missing | No `COUNT(*)` query. No `total`/`total_pages` in response. |
| Response envelope | ⚠️ Partial | Returns `{status, message, data}` but `data` is a flat `[]` — no pagination metadata. |
| Type casting | ✅ Present | `id`, `roles_id`, `polda_id`, `is_active` cast to `(int)` for Flutter compatibility. |
| Sensitive fields | ⚠️ Exposed | `password` is NOT selected (good), but `uuid` and `token` ARE exposed. |

### 1.4 Response Shape (current)
```json
{
  "status": 200,
  "message": "Daftar user berhasil dimuat.",
  "data": [
    {
      "id": 4,
      "username": "admin",
      "roles_id": 1,
      "polda_id": null,
      "uuid": "7997324a-bce7-48b1-bfad-3008068d7ebe",
      "token": "3b9ecb26465fada071efba2eab80da00",
      "expired": "30",
      "is_active": 1,
      "created_at": "2026-07-11 16:07:10",
      "updated_at": null
    }
  ]
}
```

### 1.5 Schema Relationships

```
tbl_users               tbl_role                tbl_polda
───────────             ────────                ─────────
id (PK)          ┌───── id (PK)        ┌────── id (PK)
username         │      roles           │       nama_polda
password              │      created_at        │       latitude
roles_id (FK) ────────┘                        │       longitude
polda_id (FK) ────────────────────────────────┘       created_at
uuid
token
expired
is_active
created_at
updated_at
```

| FK Column | References | Name Column |
|-----------|-----------|-------------|
| `tbl_users.roles_id` | `tbl_role.id` | `tbl_role.roles` |
| `tbl_users.polda_id` | `tbl_polda.id` | `tbl_polda.nama_polda` |

---

## 2. Refactor Blueprint

### 2.1 New Query Parameters

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `search` | string | `""` | Case-insensitive LIKE match on `username` |
| `page` | int | `1` | 1-based page number |
| `limit` | int | `10` | Rows per page (clamped 1–100) |

### 2.2 Query Design (Count-First Pattern)

**Step 1 — Count query:**
```sql
SELECT COUNT(*) AS total
FROM tbl_users u
LEFT JOIN tbl_role r ON u.roles_id = r.id
LEFT JOIN tbl_polda p ON u.polda_id = p.id
WHERE u.is_active = 1
  AND u.username LIKE '%search_term%'   -- only when search is non-empty
```

**Step 2 — Data query:**
```sql
SELECT u.id, u.username, u.roles_id, u.polda_id,
       r.roles AS role_name,
       p.nama_polda,
       u.is_active, u.created_at, u.updated_at
FROM tbl_users u
LEFT JOIN tbl_role r ON u.roles_id = r.id
LEFT JOIN tbl_polda p ON u.polda_id = p.id
WHERE u.is_active = 1
  AND u.username LIKE '%search_term%'   -- only when search is non-empty
ORDER BY u.id ASC
LIMIT :limit OFFSET :offset
```

**Note:** Use `LEFT JOIN` for `tbl_polda` because `polda_id` is nullable (Super Admin may not be assigned to a Polda). Use `LEFT JOIN` for `tbl_role` defensively — though `roles_id` should always be set.

### 2.3 Response Envelope (new)

```json
{
  "status": 200,
  "message": "Daftar user berhasil dimuat.",
  "data": {
    "items": [
      {
        "id": 4,
        "username": "admin",
        "roles_id": 1,
        "role_name": "Super Admin",
        "polda_id": null,
        "nama_polda": null,
        "is_active": 1,
        "created_at": "2026-07-11 16:07:10",
        "updated_at": null
      }
    ],
    "total": 42,
    "page": 1,
    "limit": 10,
    "total_pages": 5
  }
}
```

### 2.4 Fields to REMOVE from Response

| Field | Reason |
|-------|--------|
| `uuid` | Internal token identifier — not needed by frontend |
| `token` | Raw auth token — security risk to expose |
| `expired` | Token expiry string — irrelevant to user list |

### 2.5 Type Casting (preserve existing pattern)

```php
foreach ($items as &$row) {
    $row['id']         = (int) $row['id'];
    $row['roles_id']   = (int) $row['roles_id'];
    $row['polda_id']   = isset($row['polda_id']) ? (int) $row['polda_id'] : null;
    $row['is_active']  = (int) $row['is_active'];
}
```

### 2.6 Edge Cases

1. **Empty search** → omit the LIKE clause entirely (don't inject `%%`).
2. **page out of range** → return empty `items` array, but correct `total`/`total_pages`.
3. **limit validation** → clamp to 1–100. If `limit` ≤ 0 or > 100, fall back to default (10).
4. **SQL injection** → use CodeIgniter query builder or `$this->db->escape_like_str()` for the search term. Do NOT concatenate raw user input into SQL.
5. **polda_id is NULL** → `LEFT JOIN tbl_polda` handles this gracefully; `nama_polda` will be `null` in JSON.

### 2.7 Implementation Checklist

- [ ] Modify `Auth::all()` to read `search`, `page`, `limit` from `$_GET` (CI3 `$this->input->get()`)
- [ ] Validate/clamp `page` (≥1) and `limit` (1–100)
- [ ] Compute `$offset = ($page - 1) * $limit`
- [ ] Build dynamic WHERE clause: base `is_active = 1` + optional `username LIKE`
- [ ] Run COUNT query first
- [ ] Run data query with JOINs, ORDER BY, LIMIT, OFFSET
- [ ] Remove `uuid`, `token`, `expired` from SELECT
- [ ] Add `role_name` and `nama_polda` to SELECT via JOINs
- [ ] Type-cast integer columns
- [ ] Return paginated envelope `{items, total, page, limit, total_pages}`
- [ ] Update or add Playwright E2E test for pagination + search

---

## 3. Appendix: Full Current `all()` Method

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

    // Cast integer columns for Flutter compatibility
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

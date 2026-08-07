# Backend Users Pagination Build

> **Endpoint:** `GET /api/v1/user`
> **Controller::method:** `Auth::all()` — `application/controllers/Auth.php`
> **Status:** ✅ Implemented

---

## 1. What Changed

| Feature | Before | After |
|---------|--------|-------|
| JOIN `tbl_role` | ❌ Raw `roles_id` (1, 2, 3) | ✅ `r.roles as role_name` ("Super Admin" / "Operator Polda" / "Eksekutif") |
| JOIN `tbl_polda` | ❌ Raw `polda_id` / NULL | ✅ `p.nama_polda` |
| Pagination | ❌ Flat array of ALL users | ✅ `page` + `limit` params, `LIMIT/OFFSET` |
| Search | ❌ None | ✅ `search` param, `LIKE` on `u.username` |
| Count-first | ❌ None | ✅ `count_all_results('', false)` before LIMIT |
| Response shape | `data: [...]` | ✅ `data.items` + `data.pagination` (established pattern) |
| 🔒 Security | ❌ Exposed `uuid`, `token`, `expired` | ✅ Removed from SELECT — **token leak patched** |

**Scope:** Only `Auth::all()` modified. JWT guard, `login()`, `insert_user()`, `user_put()`, `user_delete()` untouched.

---

## 2. Rewritten Method (`Auth::all()`)

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

## 3. Generated SQL (illustrative)

```sql
-- Count (before SELECT/LIMIT/ORDER)
SELECT COUNT(*) AS `numrows`
FROM `tbl_users` `u`
LEFT JOIN `tbl_role` `r` ON `u`.`roles_id` = `r`.`id`
LEFT JOIN `tbl_polda` `p` ON `u`.`polda_id` = `p`.`id`
WHERE `u`.`is_active` = 1
  AND `u`.`username` LIKE '%admin%' ESCAPE '!';   -- only when ?search=admin

-- Data (after counting)
SELECT `u`.`id`, `u`.`username`, `u`.`roles_id`, `u`.`polda_id`,
       `r`.`roles` AS `role_name`, `p`.`nama_polda`,
       `u`.`is_active`, `u`.`created_at`, `u`.`updated_at`
FROM `tbl_users` `u`
LEFT JOIN `tbl_role` `r` ON `u`.`roles_id` = `r`.`id`
LEFT JOIN `tbl_polda` `p` ON `u`.`polda_id` = `p`.`id`
WHERE `u`.`is_active` = 1
  AND `u`.`username` LIKE '%admin%' ESCAPE '!'
ORDER BY `u`.`id` ASC
LIMIT 10 OFFSET 0;
```

---

## 4. Response Shape (new)

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
        "polda_id": null,
        "role_name": "Super Admin",
        "nama_polda": null,
        "is_active": 1,
        "created_at": "2026-07-11 16:07:10",
        "updated_at": null
      }
    ],
    "pagination": {
      "total_data": 3,
      "total_pages": 1,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

> 🔒 **Gone from every row:** `uuid`, `token`, `expired` — JWT/token material no longer leaves the server.

---

## 5. Query Params

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `search` | string | `''` | Partial `LIKE` match on `username` (real-time search) |
| `page` | int | `1` | Clamped to `>= 1` |
| `limit` | int | `10` | Clamped to `1..100` |

Example: `GET /api/v1/user?search=admin&page=1&limit=10`

---

## 6. Verification

- ✅ `php -l application/controllers/Auth.php` → **No syntax errors detected**
- ✅ Diff confirmed: only `all()` body changed (43 insertions / 8 deletions in `Auth.php`)
- ✅ JWT auth guard preserved verbatim (401 on missing/invalid token)
- ✅ `uuid` / `token` / `expired` no longer present in the SELECT list
- ✅ Pagination envelope matches `Master::polda_get()` established pattern
- ✅ Type-casting loop retained (`id`, `roles_id`, `polda_id`, `is_active`) + `unset($row)`

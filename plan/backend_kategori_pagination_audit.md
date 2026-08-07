# Backend Pagination Audit: `kategori_senjata_get`

> **Status**: AUDIT | **Date**: 2025-01-21 | **Target**: `application/controllers/Master.php:596-624`

---

## 1. Current Logic

### 1.1 Code (lines 596–624)

```php
public function kategori_senjata_get()
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

    // Only active (not soft-deleted) Kategori Senjata are shown to the frontend.
    $this->db->order_by('kategori_id', 'ASC');
    $rows = $this->db->get_where('tbl_kategori_senjata', ['is_active' => 1])->result_array();

    foreach ($rows as &$row) {
        $row['kategori_id'] = (int) $row['kategori_id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Kategori Senjata berhasil dimuat.',
        'data' => $rows
    ]);
}
```

### 1.2 What it does

| Aspect | Current behavior |
|---|---|
| **Auth** | JWT required (any role) |
| **Filter** | `is_active = 1` only (soft-delete aware) |
| **Search** | ❌ None |
| **Pagination** | ❌ None — returns ALL rows |
| **Ordering** | `kategori_id ASC` |
| **Type-casting** | `kategori_id` → `(int)` |
| **Response envelope** | `data` = flat array `$rows` (no `items`/`pagination` wrapper) |

### 1.3 Why this needs updating

- Currently only **2 seeded rows** (`Pendek/9mm` and `Panjang/5.56mm`), but the table is designed to grow.
- The Flutter frontend expects a paginated envelope: `data.items[]` + `data.pagination{}`.
- Returning a flat array breaks the Flutter client's parsing contract — every other `*_get` endpoint in this controller (`polda_get`, `polres_get`) already uses the paginated shape.
- No search means the frontend must fetch everything and filter client-side, which doesn't scale.

### 1.4 Table schema (`tbl_kategori_senjata`)

```sql
CREATE TABLE `tbl_kategori_senjata` (
    `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
    `tipe_laras`  enum('Panjang','Pendek') NOT NULL,
    `kaliber`     varchar(20) NOT NULL,
    `is_active`   tinyint(1) NOT NULL DEFAULT 1,
    `updated_at`  datetime DEFAULT NULL,
    PRIMARY KEY (`kategori_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

| Column | Type | Searchable? | Notes |
|---|---|---|---|
| `kategori_id` | `int(11)` PK | ❌ | Internal ID — not user-facing for search |
| `tipe_laras` | `enum('Panjang','Pendek')` | ✅ LIKE | Users will search "Panjang" or "Pendek" |
| `kaliber` | `varchar(20)` | ✅ LIKE | Users will search e.g. "9mm", "5.56" |
| `is_active` | `tinyint(1)` | ❌ | Always `1` in the query |
| `updated_at` | `datetime` | ❌ | Metadata only |

---

## 2. Refactor Blueprint

### 2.1 Reference pattern: `polda_get` (lines 26–85)

The established "count-first" pagination pattern used in `polda_get` and `polres_get`:

```
1. Extract query params:  search, page, limit
2. Apply is_active = 1 filter
3. Conditionally apply LIKE (if search !== '')
4. count_all_results(table, false)  ← count BEFORE pagination; FALSE preserves state
5. Apply order_by + limit + offset
6. get() WITHOUT table name (qb_from already set by count_all_results)
7. Type-cast integer columns
8. Return { items, pagination: { total_data, total_pages, current_page, per_page } }
```

### 2.2 Target implementation for `kategori_senjata_get`

```php
public function kategori_senjata_get()
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

    // Only active (not soft-deleted) Kategori Senjata are shown.
    $this->db->where('is_active', 1);

    // Optional real-time search: partial (LIKE) match on kaliber OR tipe_laras.
    if ($search !== '') {
        $this->db->group_start();           // open parentheses
        $this->db->like('kaliber',    $search);
        $this->db->or_like('tipe_laras', $search);
        $this->db->group_end();             // close parentheses
    }

    // Total rows matching the current filter.
    $total_data = $this->db->count_all_results('tbl_kategori_senjata', false);

    // Stable ordering.
    $this->db->order_by('kategori_id', 'ASC');

    // Pagination.
    $this->db->limit($limit, ($page - 1) * $limit);
    $rows = $this->db->get()->result_array();

    foreach ($rows as &$row) {
        $row['kategori_id'] = (int) $row['kategori_id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Kategori Senjata berhasil dimuat.',
        'data' => [
            'items' => $rows,
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

### 2.3 Key design decisions

| Decision | Rationale |
|---|---|
| **Search targets `kaliber` AND `tipe_laras`** | Users type "9mm" to match kaliber, or "Panjang"/"Pendek" to match tipe_laras. Using `OR LIKE` with `group_start/group_end` means `WHERE is_active=1 AND (kaliber LIKE '%x%' OR tipe_laras LIKE '%x%')` — the search does not leak past the `is_active` filter. |
| **`limit` capped at 100** | Matches `polda_get`/`polres_get` — prevents accidental unbounded queries. |
| **Default `limit=10`, `page=1`** | Consistent with sibling endpoints. |
| **`count_all_results(..., false)`** | Preserves Query Builder state so `get()` inherits WHERE/LIKE without re-specifying the table name (avoids `FROM tbl_kategori_senjata, tbl_kategori_senjata` cartesian-product bug). |
| **Sort by `kategori_id ASC`** | Stable, deterministic ordering — pages never overlap across requests even as rows are inserted. |
| **Response shape change: `data` from `[...]` to `{items, pagination}`** | Breaking change for the Flutter client, but necessary for consistency. The Flutter side should be updated simultaneously. The `message` string stays the same. |
| **No `select()` call needed** | The table has only 3 meaningful columns (`kategori_id`, `tipe_laras`, `kaliber`) — `SELECT *` is fine; `is_active` and `updated_at` are internal and harmless to expose. |

### 2.4 SQL that will be generated

**No search:**
```sql
SELECT * FROM tbl_kategori_senjata WHERE is_active = 1
ORDER BY kategori_id ASC LIMIT 10 OFFSET 0;
```

**With search `"9mm"`:**
```sql
SELECT * FROM tbl_kategori_senjata
WHERE is_active = 1 AND (kaliber LIKE '%9mm%' OR tipe_laras LIKE '%9mm%')
ORDER BY kategori_id ASC LIMIT 10 OFFSET 0;
```

**Count (no search):**
```sql
SELECT COUNT(*) FROM tbl_kategori_senjata WHERE is_active = 1;
```

### 2.5 Risk assessment

| Risk | Severity | Mitigation |
|---|---|---|
| Flutter client expects flat array | **Medium** | Coordinate with Flutter dev to update parsing to `data.items` + `data.pagination`. The message string is unchanged so toast/snackbar is unaffected. |
| `get()` without table name after `count_all_results` | **Low** | This is the established pattern in `polda_get` and `polres_get` — it's battle-tested. |
| `OR LIKE` on `tipe_laras` enum | **None** | MySQL treats `LIKE` on ENUM as a string comparison — works correctly. Searching "panjang" (case-insensitive by default collation) matches `'Panjang'`. |

### 2.6 Test verification

After implementation, confirm via curl:

```bash
# No search, page 1
curl -s "http://localhost:8080/api/v1/master/kategori-senjata" \
  -H "Authorization: Bearer <token>" | jq '.data.pagination'

# Expected: { "total_data": 2, "total_pages": 1, "current_page": 1, "per_page": 10 }

# Search "9mm"
curl -s "http://localhost:8080/api/v1/master/kategori-senjata?search=9mm" \
  -H "Authorization: Bearer <token>" | jq '.data.items'

# Expected: [{ "kategori_id": 1, "tipe_laras": "Pendek", "kaliber": "9mm", ... }]

# Search "Panjang"
curl -s "http://localhost:8080/api/v1/master/kategori-senjata?search=Panjang" \
  -H "Authorization: Bearer <token>" | jq '.data.items'

# Expected: [{ "kategori_id": 2, "tipe_laras": "Panjang", "kaliber": "5.56mm", ... }]
```

---

## 3. Summary

The `kategori_senjata_get` method is a straightforward refactor target. It currently follows none of the pagination patterns that its sibling endpoints (`polda_get`, `polres_get`) already use. The change is low-risk and mechanical:

1. Add `search`/`page`/`limit` query param extraction
2. Replace `get_where()` with the `count_all_results` → `get()` two-step pattern
3. Add `group_start/group_end` with `like('kaliber')` + `or_like('tipe_laras')` for search
4. Restructure response from flat array to `{ items, pagination }` envelope
5. The Flutter client must update its parsing accordingly

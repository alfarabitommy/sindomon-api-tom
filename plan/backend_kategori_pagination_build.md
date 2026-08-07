# Build Report: Pagination + Real-time Search for `kategori_senjata_get`

> **Status**: DONE — implemented, linted, live-tested | **Target**: `application/controllers/Master.php` (method `kategori_senjata_get`, lines 596–663)
> **Pattern source**: `polda_get` / `polres_get` (count-first pattern)
> **Scope guard**: Only `kategori_senjata_get()` was modified. No other method in `Master.php` was touched.

---

## 1. The Rewritten Method (exact final code)

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

    // Only active (not soft-deleted) Kategori Senjata are shown to the frontend.
    $this->db->where('is_active', 1);

    // Optional real-time search: partial (LIKE) match on kaliber OR tipe_laras.
    // group_start/group_end keep the OR inside parentheses so the search
    // never bypasses the is_active = 1 filter above.
    if ($search !== '') {
        $this->db->group_start();
        $this->db->like('kaliber', $search);
        $this->db->or_like('tipe_laras', $search);
        $this->db->group_end();
    }

    // Total rows matching the current filter. The FALSE second argument
    // preserves the Query Builder state (WHERE/LIKE) for the get() below.
    $total_data = $this->db->count_all_results('tbl_kategori_senjata', false);

    // Stable ordering so pagination pages never overlap between requests.
    $this->db->order_by('kategori_id', 'ASC');

    // Pagination: LIMIT {limit} OFFSET {(page - 1) * limit}
    // NOTE: get() is intentionally called WITHOUT a table name —
    // count_all_results() already set qb_from; passing the table again
    // would compile "FROM tbl_kategori_senjata, tbl_kategori_senjata"
    // (cartesian product).
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

---

## 2. Change Summary (vs. previous implementation)

| # | Aspect | Before | After |
|---|---|---|---|
| 1 | JWT auth guard | ✅ kept | ✅ unchanged |
| 2 | `is_active = 1` filter | ✅ `get_where([...])` | ✅ explicit `where('is_active', 1)` before count |
| 3 | `search` param | ❌ none | ✅ `trim((string) $this->input->get('search'))` |
| 4 | `page` param | ❌ none | ✅ `max(1, ...)` — defaults to 1 |
| 5 | `limit` param | ❌ none | ✅ default 10, hard cap 100 (`max(1, min(100, ...))`) |
| 6 | Search scope | ❌ n/a | ✅ `group_start` + `like('kaliber')` + `or_like('tipe_laras')` + `group_end` |
| 7 | Count | ❌ n/a | ✅ `count_all_results('tbl_kategori_senjata', false)` — count-first |
| 8 | Ordering | ✅ `kategori_id ASC` | ✅ unchanged |
| 9 | Pagination | ❌ none | ✅ `limit($limit, ($page-1)*$limit)` |
| 10 | Query exec | `get_where()` | `get()` **without table name** (qb_from already set by count) |
| 11 | Type-cast loop | ✅ `kategori_id` → int | ✅ unchanged |
| 12 | Response shape | `data` = flat `[...]` | `data` = `{items: [...], pagination: {total_data, total_pages, current_page, per_page}}` |

---

## 3. SQL Generated (verification)

**No search (default):**
```sql
SELECT * FROM tbl_kategori_senjata WHERE is_active = 1 ORDER BY kategori_id ASC LIMIT 10 OFFSET 0;
```

**With `search=9mm`:**
```sql
SELECT * FROM tbl_kategori_senjata
WHERE is_active = 1 AND (kaliber LIKE '%9mm%' OR tipe_laras LIKE '%9mm%')
ORDER BY kategori_id ASC LIMIT 10 OFFSET 0;
```

**Count query (search active):**
```sql
SELECT COUNT(*) AS numrows FROM tbl_kategori_senjata
WHERE is_active = 1 AND (kaliber LIKE '%9mm%' OR tipe_laras LIKE '%9mm%');
```

---

## 4. Live Test Results (HTTP 200, against running dev server + MySQL)

| # | Request | Result |
|---|---|---|
| 1 | `GET /master/kategori-senjata` | ✅ 2 items, `{total_data:2, total_pages:1, current_page:1, per_page:10}` |
| 2 | `?search=9mm` | ✅ 1 item (`Pendek/9mm`), `total_data:1` |
| 3 | `?search=Panjang` | ✅ 1 item (`Panjang/5.56mm`) — `tipe_laras` LIKE works |
| 4 | `?limit=1&page=2` | ✅ 1 item (`Panjang/5.56mm`), `{total_data:2, total_pages:2, current_page:2, per_page:1}` — OFFSET math correct |
| 5 | `?search=zzz` | ✅ `items:[]`, `{total_data:0, total_pages:0}` — empty state clean |
| 6 | no token | ✅ HTTP 401 guard intact |
| 7 | `?search=&page=0&limit=999` | ✅ sanitized → `page:1`, `per_page:100` (cap applied), empty search = no LIKE |

**PHP lint**: `php -l application/controllers/Master.php` → `No syntax errors detected`.

**Scope check**: `git diff application/controllers/Master.php` shows changes confined to `kategori_senjata_get()` only.

---

## 5. Notes & Follow-ups

- **Breaking shape change for the Flutter client**: `data` changed from a flat array to `{items, pagination}`. The Flutter frontend must parse `data.items` + `data.pagination` — this matches what it already expects from `polda_get` / `polres_get`.
- `is_active` and `updated_at` columns are still returned per row (no `select()` narrowing). Harmless — the response already carried them before; a `select('kategori_id, tipe_laras, kaliber')` narrowing is an optional future cleanup, not required.
- No Playwright spec covers this endpoint yet (`tests/api/` has only `master_polres`, `seeder_master`, `sindomon_e2e`). Adding `kategori_senjata` coverage to `seeder_master.spec.ts` is a recommended follow-up.

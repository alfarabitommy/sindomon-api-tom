# Polres GET — Pagination & Real-Time Search Build Report

> **Controller:** `application/controllers/Master.php`  
> **Method:** `polres_get()` (lines 296–368)  
> **Status:** ✅ Implemented, syntax-checked, live-tested, and E2E-suite-verified  
> **Related audit:** [`plan/backend_polres_pagination_audit.md`](./backend_polres_pagination_audit.md)

---

## 1. Exact Rewritten `polres_get()`

```php
public function polres_get()
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

    // Existing filter: drill-down by parent Polda (preserved).
    $polda_id_filter = $this->input->get('polda_id');

    // Only active (not soft-deleted) Polres are shown to the frontend.
    $this->db->where('r.is_active', 1);

    if ($polda_id_filter !== null && $polda_id_filter !== '') {
        $this->db->where('r.polda_id', (int) $polda_id_filter);
    }

    // Optional real-time search: partial (LIKE) match on nama_polres.
    if ($search !== '') {
        $this->db->like('r.nama_polres', $search);
    }

    // Total rows matching the current filter. The FALSE second argument
    // preserves the Query Builder state (WHERE/LIKE) for the get() below.
    $total_data = $this->db->count_all_results('tbl_polres r', false);

    // LEFT JOIN so Polres still appear even if parent Polda was soft-deleted,
    // but the (deleted) Polda name must not leak into the response.
    $this->db->select('r.polres_id, r.polda_id, r.nama_polres, p.nama_polda');
    $this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');

    // Stable ordering so pagination pages never overlap between requests.
    $this->db->order_by('r.polres_id', 'ASC');

    // Pagination: LIMIT {limit} OFFSET {(page - 1) * limit}
    // NOTE: get() is intentionally called WITHOUT a table name —
    // count_all_results() already set qb_from; passing the table again
    // would compile "FROM tbl_polres, tbl_polres" (cartesian product).
    $this->db->limit($limit, ($page - 1) * $limit);
    $rows = $this->db->get()->result_array();

    foreach ($rows as &$row) {
        $row['polres_id'] = (int) $row['polres_id'];
        $row['polda_id'] = (int) $row['polda_id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Polres berhasil dimuat.',
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

## 2. What Changed vs. What Was Preserved

### Preserved (untouched semantics)

| Element | Evidence in new code |
|---------|---------------------|
| JWT auth guard (401 on missing token) | First block, unchanged |
| `polda_id` GET filter | `$polda_id_filter = $this->input->get('polda_id');` + `where('r.polda_id', (int) $polda_id_filter)` |
| `r.is_active = 1` (soft-delete filter) | `where('r.is_active', 1)` |
| LEFT JOIN with `p.is_active = 1` guard | `join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left')` |
| Selected columns | `r.polres_id, r.polda_id, r.nama_polres, p.nama_polda` |
| Ordering | `order_by('r.polres_id', 'ASC')` |
| Int casting | `polres_id` and `polda_id` → `(int)` |

### Added (new capability)

| Element | Detail |
|---------|--------|
| `search` param | `trim((string) $this->input->get('search'))` → `LIKE '%term%'` on `r.nama_polres` when non-empty |
| `page` param | `max(1, (int) (... ?? 1))` — default 1, clamped ≥ 1 |
| `limit` param | `max(1, min(100, (int) (... ?? 10)))` — default 10, clamped 1–100 |
| Total count | `count_all_results('tbl_polres r', false)` — counts with ALL active filters (is_active + polda_id + search) |
| Pagination | `limit($limit, ($page - 1) * $limit)` |
| Response envelope | `data.items` + `data.pagination` (replaces flat `data: [...]`) |

---

## 3. Why the Call Order Is Safe (CI3 internals, verified in `system/database/DB_query_builder.php`)

1. **WHERE/LIKE before count** — `count_all_results($table, false)` (line 1401) with `$reset = FALSE` preserves `qb_where`/`qb_like`/`qb_join`/`qb_select`; only `qb_orderby` is saved/cleared/restored. The count query therefore reflects the same filter set as the data query.
2. **FROM is set by `count_all_results`** — it calls `from($table)` internally (line 1406). `from()` *appends* to `qb_from` (line 501), so we must NOT call `from('tbl_polres r')` ourselves before it — otherwise `FROM tbl_polres r, tbl_polres r` (cartesian product). That is why there is no explicit `from()` in the final code.
3. **`select()`/`join()` after count** — `count_all_results` runs `_compile_select($override)` (line 1418) which *bypasses* `qb_select` (line 2324) instead of consuming it. So `qb_select` is still empty after the count; calling `select()` then populates it exactly once. Same for `qb_join` (untouched by the count path).
4. **`get()` without a table name** — `qb_from` already holds `tbl_polres r` from the count; passing a table again would double the FROM. Comment in code documents this.
5. **`like()` is alias-safe** — `_like()` (line 991) interpolates the field into the WHERE condition without `protect_identifiers`, so `r.nama_polres` is emitted verbatim.
6. **`where('r.x', ...)` is alias-safe** — `protect_identifiers` (DB_driver.php:1822) with `dbprefix = ''` (config line 83) skips prefix insertion; `` `r`.`is_active` `` is valid SQL even before alias registration.

Resulting SQL shape:

```sql
-- count
SELECT COUNT(*) AS numrows
FROM tbl_polres r
WHERE r.is_active = 1
  AND r.polda_id = ?            -- only when polda_id filter present
  AND r.nama_polres LIKE '%?%'  -- only when search present

-- data
SELECT r.polres_id, r.polda_id, r.nama_polres, p.nama_polda
FROM tbl_polres r
LEFT JOIN tbl_polda p ON r.polda_id = p.id AND p.is_active = 1
WHERE r.is_active = 1
  AND r.polda_id = ?            -- only when polda_id filter present
  AND r.nama_polres LIKE '%?%'  -- only when search present
ORDER BY r.polres_id ASC
LIMIT ? OFFSET ?
```

---

## 4. Response Contract (breaking change for Flutter)

```json
{
  "status": 200,
  "message": "Daftar Polres berhasil dimuat.",
  "data": {
    "items": [
      { "polres_id": 1, "polda_id": 1, "nama_polres": "Polrestabes 1.1", "nama_polda": "Polda Aceh" }
    ],
    "pagination": {
      "total_data": 78,
      "total_pages": 8,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

> ⚠️ **Frontend impact:** `data` is now an object with `items`/`pagination`, not a flat array. Flutter must read `data['items']` and `data['pagination']`.

---

## 5. Verification Evidence (all run live against `php -S localhost:8080 tests/router.php`)

| Scenario | Result |
|----------|--------|
| `GET /master/polres` (default) | ✅ 200, 10 items, `total_data: 78`, `total_pages: 8`, `current_page: 1`, `per_page: 10`; first row `{polres_id:1, polda_id:1, nama_polres:"Polrestabes 1.1", nama_polda:"Polda Aceh"}` |
| `?polda_id=1` | ✅ 4 rows (Polda Aceh jurisdiction), pagination metadata consistent |
| `?search=restabes` | ✅ 38 matches, 4 pages, all `nama_polda` populated (JOIN intact) |
| `?polda_id=1&search=restabes` | ✅ exactly 1 row: `Polrestabes 1.1` / `Polda Aceh` |
| `?polda_id=2&search=2.1` | ✅ exactly 1 row: `Polrestabes 2.1` / `Polda Sumatera Utara` |
| `?page=2&limit=5` | ✅ rows 6–10 (correct OFFSET 5), `total_pages: 16` |
| `?page=3&limit=30` | ✅ rows 61–78 (18 rows), `total_pages: 3` |
| `?limit=200` | ✅ clamped → `per_page: 100`, all 78 rows |
| `?page=-5&limit=abc` | ✅ clamped → `page: 1`, `per_page: 1` (same semantics as `polda_get`) |
| `?search=%20` (whitespace) | ✅ treated as no search (78 total) |
| `php -l application/controllers/Master.php` | ✅ No syntax errors |
| `npx playwright test tests/api/master_polres.spec.ts` | ✅ 6 passed; 1 pre-existing env failure (DELETE trap test shells out to `mysql` CLI, not installed here — unrelated to `polres_get`) |
| `npx playwright test tests/api/seeder_master.spec.ts` | ✅ 3 passed; 1 pre-existing failure (`sdm/org-tree` vacancy alert — served by `Sdm.php`, untouched by this diff) |

### Files touched (final working-tree state)

- `M application/controllers/Master.php` — the only source change (46 lines added, `polres_get` only)
- `?? plan/backend_polres_pagination_audit.md` — audit artifact from planning phase
- `?? plan/backend_polres_pagination_build.md` — this report

---

## 6. Safety Confirmation

- **No other method in `Master.php` was modified** — `git diff` shows changes only inside `polres_get()`.
- The `polda_id` filter and LEFT JOIN survive verbatim (section 2).
- No schema changes, no migration needed, no new dependencies.

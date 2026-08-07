# Polres GET — Pagination & Real-Time Search Audit

> **Controller:** `application/controllers/Master.php`  
> **Methods compared:** `polda_get()` (lines 26–85) vs `polres_get()` (lines 296–338)  
> **Goal:** Replicate the pagination + search logic from `polda_get` into `polres_get` while preserving the existing `polda_id` filter and LEFT JOIN.

---

## 1. Current Logic — `polres_get()` (as-is)

### 1.1 Auth (preserved)

```php
// lines 298–307
$payload = get_jwt_payload($this);
if ($payload === null) { /* 401 */ return; }
```

No role gating — any authenticated role can call this. **Keep as-is.**

### 1.2 Query parameters (currently parsed)

Only `polda_id` is read:

```php
// line 309
$polda_id_filter = $this->input->get('polda_id');
```

**Missing:** `search`, `page`, `limit`.

### 1.3 Query builder setup (current)

```php
// lines 311–316
$this->db->select('r.polres_id, r.polda_id, r.nama_polres, p.nama_polda');
$this->db->from('tbl_polres r');
$this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');
$this->db->where('r.is_active', 1);
```

Key points:
- **Alias `r`** for `tbl_polres`, **alias `p`** for `tbl_polda`.
- **LEFT JOIN** with a composite ON clause (`p.is_active = 1`) so a soft-deleted Polda parent yields `nama_polda = NULL` instead of leaking a deleted name or dropping the Polres row entirely.
- **`r.is_active = 1`** ensures only active (not soft-deleted) Polres rows appear.

### 1.4 Optional `polda_id` filter (current)

```php
// lines 318–320
if ($polda_id_filter !== null && $polda_id_filter !== '') {
    $this->db->where('r.polda_id', (int) $polda_id_filter);
}
```

**Must be preserved.** This is the primary jurisdictional/drill-down filter.

### 1.5 No search, no pagination

```php
// line 322–324
$this->db->order_by('r.polres_id', 'ASC');
$query = $this->db->get();
$rows = $query->result_array();
```

- No `LIKE` on `nama_polres`.
- No `LIMIT` / `OFFSET`.
- No `count_all_results` — no total row count.
- The entire result set is returned in one go.

### 1.6 Response shape (current)

```php
// lines 332–337
echo json_encode([
    'status' => 200,
    'message' => 'Daftar Polres berhasil dimuat.',
    'data' => $rows   // <--- flat array
]);
```

**Problem:** Returns a flat array (`data: [...]`), not the paginated envelope `data: { items: [...], pagination: {...} }` that Flutter expects.

### 1.7 Type casting (current)

```php
// lines 326–329
foreach ($rows as &$row) {
    $row['polres_id'] = (int) $row['polres_id'];
    $row['polda_id']  = (int) $row['polda_id'];
}
```

**Must be preserved.** Flutter needs ints for IDs.

---

## 2. Reference Implementation — `polda_get()` (lines 26–85)

This is the gold standard we're replicating. Key structural elements:

| Element | `polda_get` |
|---------|-------------|
| `search` param | `$this->input->get('search')` — trims, optional |
| `page` param | `$this->input->get('page')` — defaults to 1, clamped ≥ 1 |
| `limit` param | `$this->input->get('limit')` — defaults to 10, clamped 1–100 |
| Filter column | `is_active = 1` |
| Search column | `nama_polda` via `$this->db->like('nama_polda', $search)` |
| Count strategy | `count_all_results('tbl_polda', false)` — preserves WHERE/LIKE, does NOT re-add FROM |
| Data query | `get()` with NO table name — FROM already set by `count_all_results` |
| Ordering | `order_by('id', 'ASC')` |
| Pagination | `limit($limit, ($page - 1) * $limit)` |
| Response shape | `data.items` + `data.pagination` (total_data, total_pages, current_page, per_page) |

---

## 3. CI3 Query Builder — Critical Internals (verified from source)

These are the CI3 QB behaviours that dictate the safe refactor order:

### 3.1 `count_all_results($table, $reset)`

**Source:** `system/database/DB_query_builder.php:1401–1437`

```
1. If $table !== '', calls $this->from($table)   ← APPENDS to qb_from[]
2. Saves + clears qb_orderby (to keep COUNT fast)
3. Calls _compile_select($select_override)       ← bypasses qb_select entirely
4. If $reset === FALSE: restores qb_orderby, leaves everything else intact
```

**Key takeaway:** With `$reset = FALSE`, `qb_from`, `qb_join`, `qb_where`, `qb_like`, and `qb_select` are all preserved exactly as they were before the call. Only `qb_orderby` is restored from a saved copy.

### 3.2 `select()` appends, does NOT replace

**Source:** `system/database/DB_query_builder.php:285–314`

```
$this->qb_select[] = $val;   // APPEND — line 301
```

There is no built-in "replace select" mechanism. The workaround:
- Call `select()` only AFTER `count_all_results` has run (since `_compile_count` bypasses `qb_select`), so `qb_select` is still empty when we populate it.
- **OR** set up `select()` before `count_all_results` and rely on `_compile_select($override)` to bypass it during the count. After count, `qb_select` is still our desired columns.

### 3.3 `from()` appends

**Source:** `system/database/DB_query_builder.php:501`

```
$this->qb_from[] = $val;   // APPEND
```

**This is the central foot-gun.** `polda_get` avoids it by never calling `from()` before `count_all_results`. The count call's internal `from($table)` becomes the sole FROM source.

### 3.4 `like()` does NOT use `protect_identifiers`

**Source:** `system/database/DB_query_builder.php:947–1001`

The field name `$k` is interpolated directly into the condition string (line 991). No identifier escaping is applied to the field. So `like('r.nama_polres', $search)` is always safe — the `r.` prefix is just a raw string.

### 3.5 `where()` DOES use `protect_identifiers`

**Source:** `DB_driver.php:1822–1942` and `DB_query_builder.php _where()`

- `where('r.is_active', 1)` calls `protect_identifiers('r.is_active')`.
- With `dbprefix = ''` (confirmed in `application/config/database.php:83`), the prefix-adding branch (line 1908) is skipped.
- The identifier is just escaped to `` `r`.`is_active` `` — safe regardless of alias registration.

### 3.6 `get()` resets the builder

**Source:** `DB_query_builder.php` (around `get()`)

After `get()` runs, it calls `$this->_reset_select()`, clearing everything. This is fine — we're done after `get()`.

---

## 4. Refactor Blueprint

### 4.1 Strategy: "count-first" pattern (matching `polda_get`)

The safe order, validated against CI3 internals:

```
┌─────────────────────────────────────────────────────────┐
│ 1. Parse params: search, page, limit, polda_id          │
├─────────────────────────────────────────────────────────┤
│ 2. WHERE clauses only (no FROM/SELECT/JOIN yet)         │
│    - where('r.is_active', 1)                            │
│    - where('r.polda_id', ...)   [if polda_id present]   │
│    - like('r.nama_polres', ...) [if search present]     │
├─────────────────────────────────────────────────────────┤
│ 3. count_all_results('tbl_polres r', false)             │
│    → sets FROM, runs COUNT(*), preserves WHERE/LIKE     │
│    → total_data is now correct (includes LIKE filter)   │
├─────────────────────────────────────────────────────────┤
│ 4. Add display elements (after count)                   │
│    - select('r.polres_id, r.polda_id, r.nama_polres,    │
│             p.nama_polda')                               │
│    - join('tbl_polda p',                                │
│           'r.polda_id = p.id AND p.is_active = 1',      │
│           'left')                                       │
├─────────────────────────────────────────────────────────┤
│ 5. Order + paginate                                     │
│    - order_by('r.polres_id', 'ASC')                     │
│    - limit($limit, ($page - 1) * $limit)                │
├─────────────────────────────────────────────────────────┤
│ 6. get()->result_array()  (NO table name — FROM exists) │
├─────────────────────────────────────────────────────────┤
│ 7. Type-cast polres_id, polda_id to (int)               │
├─────────────────────────────────────────────────────────┤
│ 8. Return paginated envelope                            │
│    { items: [...], pagination: { total_data,            │
│      total_pages, current_page, per_page } }            │
└─────────────────────────────────────────────────────────┘
```

### 4.2 Why `like()` before `count_all_results` is safe

- `like()` stores the condition directly in `qb_where[]` (line 991 of QB).
- It does NOT go through `protect_identifiers`.
- `count_all_results` with `$reset=FALSE` preserves `qb_where` (and therefore the LIKE condition).
- The count query compiles to: `SELECT COUNT(*) AS numrows FROM tbl_polres r WHERE r.is_active = 1 AND r.polda_id = ? AND r.nama_polres LIKE '%search%'` — correct.
- Since `dbprefix` is empty, `where('r.polda_id', ...)` with the `r.` prefix before alias registration produces valid SQL.

### 4.3 Why `select()` and `join()` AFTER `count_all_results`

- `count_all_results` calls `_compile_select($override)` which **bypasses** `qb_select`. The `qb_select` array is untouched.
- After the count, `qb_select` is still empty (we never called `select()` before). So `select(...)` populates it fresh — no append/duplication issues.
- `qb_join` is also untouched by the count. Calling `join()` after populates it once.
- The final `get()` compiles to: `SELECT r.polres_id, ... FROM tbl_polres r LEFT JOIN tbl_polda p ON ... WHERE ... ORDER BY ... LIMIT ... OFFSET ...` — exactly right.

### 4.4 Pseudocode (the actual target)

```php
public function polres_get()
{
    // --- Auth (unchanged) ---
    $payload = get_jwt_payload($this);
    if ($payload === null) {
        http_response_code(401);
        echo json_encode([
            'status'  => 401,
            'message' => 'Token tidak ditemukan atau tidak valid.',
            'data'    => (object)[]
        ]);
        return;
    }

    // --- NEW: Pagination & search parameters ---
    $search = trim((string) $this->input->get('search'));
    $page   = max(1, (int) ($this->input->get('page') ?? 1));
    $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // --- Existing: Polda filter (PRESERVED) ---
    $polda_id_filter = $this->input->get('polda_id');

    // --- WHERE clauses (before count, matching polda_get pattern) ---
    // COUNT will run against tbl_polres r only — JOIN isn't needed for the count
    // because all filter columns (is_active, polda_id, nama_polres) belong to r.
    $this->db->where('r.is_active', 1);

    if ($polda_id_filter !== null && $polda_id_filter !== '') {
        $this->db->where('r.polda_id', (int) $polda_id_filter);
    }

    // --- NEW: Optional real-time search ---
    if ($search !== '') {
        $this->db->like('r.nama_polres', $search);
    }

    // --- Count (sets FROM, preserves WHERE/LIKE) ---
    $total_data = $this->db->count_all_results('tbl_polres r', false);

    // --- Display elements (added AFTER count) ---
    $this->db->select('r.polres_id, r.polda_id, r.nama_polres, p.nama_polda');
    $this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');

    // --- Order + Paginate ---
    $this->db->order_by('r.polres_id', 'ASC');
    $this->db->limit($limit, ($page - 1) * $limit);

    // --- Data query (no table name — FROM already set by count_all_results) ---
    $rows = $this->db->get()->result_array();

    // --- Type-cast (PRESERVED) ---
    foreach ($rows as &$row) {
        $row['polres_id'] = (int) $row['polres_id'];
        $row['polda_id']  = (int) $row['polda_id'];
    }
    unset($row);

    // --- NEW: Paginated response envelope ---
    http_response_code(200);
    echo json_encode([
        'status'  => 200,
        'message' => 'Daftar Polres berhasil dimuat.',
        'data'    => [
            'items'      => $rows,
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

### 4.5 What we are NOT changing

| Element | Status |
|---------|--------|
| Auth (JWT extraction) | **Unchanged** |
| `polda_id` GET filter | **Preserved** — still works, now lives alongside search |
| LEFT JOIN with `p.is_active = 1` guard | **Preserved** — soft-deleted parent Polda still yields `nama_polda = NULL` |
| `r.is_active = 1` filter | **Preserved** — soft-deleted Polres still hidden |
| `polres_id` / `polda_id` int casting | **Preserved** |
| `order_by('r.polres_id', 'ASC')` | **Preserved** — stable, deterministic ordering |

### 4.6 What we ARE adding

| Element | Detail |
|---------|--------|
| `search` param | GET query string, trimmed string. When non-empty: `LIKE '%value%'` on `r.nama_polres` |
| `page` param | GET query string, integer ≥ 1, default 1 |
| `limit` param | GET query string, integer 1–100, default 10 |
| Total row count | Via `count_all_results('tbl_polres r', false)` — includes all active WHERE + LIKE filters |
| Paginated envelope | `data.items` + `data.pagination` replacing flat `data: [...]` |
| `nama_polda` may be `null` | When parent Polda is soft-deleted, the LEFT JOIN guard makes it NULL — Flutter must handle this. (Previously also true, just undocumented.) |

### 4.7 Breaking change notice for Flutter

The response shape changes from:
```json
{ "status": 200, "message": "...", "data": [ {...}, {...} ] }
```
to:
```json
{
  "status": 200,
  "message": "...",
  "data": {
    "items": [ {...}, {...} ],
    "pagination": {
      "total_data": 76,
      "total_pages": 8,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

**The Flutter client must be updated** to read `data.items` instead of `data` directly, and to use `data.pagination` for pagination controls.

---

## 5. Verification Checklist

After implementing, verify:

- [ ] `GET /api/v1/master/polres` returns paginated envelope (default: page 1, limit 10)
- [ ] `GET /api/v1/master/polres?polda_id=1` filters by Polda AND paginates
- [ ] `GET /api/v1/master/polres?search=metro` filters by name AND paginates
- [ ] `GET /api/v1/master/polres?polda_id=1&search=metro` both filters work together
- [ ] `GET /api/v1/master/polres?page=2&limit=5` pagination metadata is correct
- [ ] `GET /api/v1/master/polres?limit=200` clamped to 100
- [ ] `GET /api/v1/master/polres?page=-1` clamped to 1
- [ ] Soft-deleted Polres (`is_active=0`) never appear
- [ ] Polres under soft-deleted Polda: still appear, `nama_polda` is `null`
- [ ] `polres_id` and `polda_id` are integers (not strings)
- [ ] Empty search (`?search=` or `?search=%20`) behaves as no-search
- [ ] E2E tests pass (`npm test` or targeted Playwright test)

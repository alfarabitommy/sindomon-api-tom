# Backend Polda Pagination & Search Audit

> **Status:** Investigation Complete — Awaiting user review before code changes
> **Date:** 2026-08-03
> **Scope:** `GET /api/v1/master/polda` — functional pagination + real-time search

---

## 1. Current API State

### 1.1 Route

**File:** `application/config/routes.php`, line 70

```php
$route['api/v1/master/polda']['GET'] = 'master/polda_get';
```

No query-parameter requirements are documented in the route. The route is plain — it takes no URI segments, so all filtering/pagination must arrive as query params.

### 1.2 Controller Method: `polda_get()` (Master.php lines 26–53)

```php
public function polda_get()
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

    // Only active (not soft-deleted) Polda are shown to the frontend.
    $rows = $this->db->get_where('tbl_polda', ['is_active' => 1])->result_array();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Polda berhasil dimuat.',
        'data' => $rows
    ]);
}
```

### 1.3 What It Does (and Does NOT Do)

| Feature | Currently Implemented? | Detail |
|---------|----------------------|--------|
| **JWT Auth** | ✅ Yes | Standard `get_jwt_payload($this)` guard |
| **Soft-delete filter** | ✅ Yes | `WHERE is_active = 1` |
| **Type-cast IDs** | ✅ Yes | `$row['id'] = (int) $row['id']` |
| **Search / filter** | ❌ No | No `$this->input->get('search')` extraction |
| **Pagination** | ❌ No | No `LIMIT`, no `OFFSET`, no `page`/`limit` params |
| **Pagination metadata** | ❌ No | Response is `{status, message, data: [...]}` — no `total_data`, `total_pages`, `current_page` |
| **Sorting** | ❌ No | Relies on default DB order (by `id` ASC from auto-increment) |

**Key observation:** The method returns **every active row** in a single response. With the seeder currently at 38 Polda records this is not a performance problem *yet*, but:
- No `ORDER BY` clause means result order is not guaranteed.
- The Flutter frontend has no way to request a specific page or search.
- As Polda records grow (soft-deleted rows are hidden, but new provinces/restructuring could add rows), this becomes a pagination problem.

### 1.4 Database Schema (`tbl_polda`)

**File:** `application/controllers/Seeder.php` lines 51–60 (canonical schema via seeder `CREATE TABLE IF NOT EXISTS`)

| Column | Type | Notes |
|--------|------|-------|
| `id` | `int(11) AUTO_INCREMENT` | Primary key |
| `nama_polda` | `varchar(100)` | **Search target** — the only text column worth searching |
| `latitude` | `varchar(100)` | Not a search candidate |
| `longitude` | `varchar(100)` | Not a search candidate |
| `is_active` | `tinyint(1) DEFAULT 1` | Soft-delete flag |
| `updated_at` | `datetime DEFAULT NULL` | |
| `created_at` | `datetime NOT NULL` | |

**Search focus:** Only `nama_polda` is usable for a `LIKE` search. Latitude/longitude are coordinates, not user-facing search fields.

### 1.5 Dataset Size

The seeder (`_seed_wilayah()`) seeds **38 Polda** (all Indonesian provinces). Each Polda gets 2 Polres children. The test `seeder_master.spec.ts` asserts exactly 38 Polda in `/api/v1/master/wilayah`.

### 1.6 No Pre-Existing Pagination Pattern in Codebase

A grep for `LIMIT`, `limit`, `offset`, `page`, `total_data`, `total_pages`, `current_page` across all `application/controllers/*.php` returned **zero matches**. This would be the **first endpoint** in the entire project to implement pagination, so there is no internal pattern to follow — we are defining the convention.

### 1.7 Contrast with `polres_get()` (Master.php lines 264–306)

`polres_get()` is slightly more advanced — it already extracts `$this->input->get('polda_id')` for optional filtering, but still has no pagination:

```php
$polda_id_filter = $this->input->get('polda_id');
// ...
if ($polda_id_filter !== null && $polda_id_filter !== '') {
    $this->db->where('r.polda_id', (int) $polda_id_filter);
}
```

This proves the codebase already uses `$this->input->get()` for query-parameter filtering — the pattern is familiar and can be extended.

---

## 2. Refactor Plan

### 2.1 Query Parameters to Accept

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `search` | string | `null` | Partial match on `nama_polda` via `LIKE '%{search}%'` |
| `page` | int | `1` | 1-based page number |
| `limit` | int | `10` | Items per page (clamped to e.g. 1–100) |
| `sort_by` | string | `id` | Column to sort by (e.g. `id`, `nama_polda`) |
| `sort_order` | string | `asc` | `asc` or `desc` |

### 2.2 Refactored `polda_get()` Pseudocode

```
1. JWT auth guard (unchanged)
2. Extract query params:
   - search  = $this->input->get('search')   → trim, default null
   - page    = $this->input->get('page')     → (int), clamp min 1
   - limit   = $this->input->get('limit')    → (int), clamp 1..100, default 10
   - sort_by = $this->input->get('sort_by')  → whitelist ['id','nama_polda','created_at'], default 'id'
   - sort_order = $this->input->get('sort_order') → whitelist ['asc','desc'], default 'asc'
3. Build query:
   - SELECT * FROM tbl_polda WHERE is_active = 1
   - IF search is not empty: AND nama_polda LIKE '%search%'
   - ORDER BY {sort_by} {sort_order}
4. Count total (before LIMIT):
   - SELECT COUNT(*) as total FROM (same WHERE clause)
5. Apply pagination:
   - LIMIT {limit} OFFSET {(page-1) * limit}
6. Type-cast IDs (unchanged)
7. Return response envelope with pagination metadata
```

### 2.3 Response Envelope (New Shape)

```json
{
  "status": 200,
  "message": "Daftar Polda berhasil dimuat.",
  "data": {
    "items": [
      { "id": 1, "nama_polda": "Polda Aceh", "latitude": "5.550000", "longitude": "95.316666", "created_at": "..." }
    ],
    "pagination": {
      "total_data": 38,
      "total_pages": 4,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

**Important:** The current response shape puts rows directly in `data` as an array. Changing this to an object with `items` + `pagination` is a **breaking change** for the Flutter frontend. We must confirm this shape with the frontend team or document it clearly.

**Alternative (non-breaking):** Keep `data` as the array of items but add pagination metadata as sibling keys:
```json
{
  "status": 200,
  "message": "Daftar Polda berhasil dimuat.",
  "data": [...],
  "pagination": { "total_data": 38, "total_pages": 4, "current_page": 1, "per_page": 10 }
}
```
This is less conventional but avoids breaking the Flutter client. **Recommendation:** use the `data.items + data.pagination` pattern (first option) — it's cleaner long-term — but coordinate with the frontend team.

### 2.4 Code Changes (Step by Step)

**File:** `application/controllers/Master.php`
**Method:** `polda_get()` (lines 26–53)

#### Step A: Extract query parameters (insert after JWT guard, before DB query)

```php
$search     = trim($this->input->get('search') ?? '');
$page       = max(1, (int) ($this->input->get('page') ?? 1));
$limit      = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));
$sort_by    = $this->input->get('sort_by') ?? 'id';
$sort_order = strtolower($this->input->get('sort_order') ?? 'asc');

// Whitelist sort columns to prevent SQL injection
$allowed_sort = ['id', 'nama_polda', 'created_at'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'id';
}
if (!in_array($sort_order, ['asc', 'desc'])) {
    $sort_order = 'asc';
}
```

#### Step B: Build WHERE clause with optional search

Replace the simple `get_where()` call with query builder chaining:

```php
$this->db->where('is_active', 1);

if ($search !== '') {
    $this->db->like('nama_polda', $search);
}
```

#### Step C: Count total before pagination

```php
$total_data = $this->db->count_all_results('tbl_polda', false);
// false = don't reset query builder, so WHERE clause is preserved for the next get()
```

Note: CI3's `count_all_results()` with `false` second param preserves the query builder state. Verified: CI3 supports this via `$this->db->count_all_results('', false)` with an empty table string.

**Correction for CI3 compatibility:** Use a cloned approach instead:
```php
// Clone the WHERE conditions for the count
$count_db = clone $this->db;
$total_data = $count_db->where('is_active', 1);
if ($search !== '') {
    $total_data = $count_db->like('nama_polda', $search);
}
$total_data = $count_db->count_all_results('tbl_polda');
```

Actually, the simplest CI3-compatible approach:
```php
$this->db->where('is_active', 1);
if ($search !== '') {
    $this->db->like('nama_polda', $search);
}

// Count total (run a separate query with same conditions)
$total_data = $this->db->count_all_results('tbl_polda', false);
// false = don't reset query builder → WHERE/LIKE are preserved
```

Wait — CI3's `count_all_results()` with second param `FALSE` does NOT reset the query builder. From CI3 docs: "Permits you to determine the number of rows in a particular Active Record query. Queries will accept Active Record restrictors. The second parameter FALSE prevents resetting QB." So `count_all_results('tbl_polda', false)` works — it runs `SELECT COUNT(*) FROM tbl_polda WHERE is_active = 1 AND nama_polda LIKE ...` and leaves the WHERE clause intact for the next `get()`.

#### Step D: Apply sorting, limit, and offset

```php
$this->db->order_by($sort_by, $sort_order);
$this->db->limit($limit, ($page - 1) * $limit);
$rows = $this->db->get('tbl_polda')->result_array();
```

#### Step E: Calculate pagination metadata

```php
$total_pages = (int) ceil($total_data / $limit);
```

#### Step F: Return new response envelope

```php
http_response_code(200);
echo json_encode([
    'status' => 200,
    'message' => 'Daftar Polda berhasil dimuat.',
    'data' => [
        'items' => $rows,
        'pagination' => [
            'total_data'   => (int) $total_data,
            'total_pages'  => $total_pages,
            'current_page' => $page,
            'per_page'     => $limit,
        ]
    ]
]);
```

### 2.5 Security Notes

1. **`$sort_by` and `$sort_order` are whitelisted** — never interpolated directly into SQL. The whitelist `in_array()` check prevents SQL injection via the ORDER BY clause.
2. **`$this->db->like()` automatically escapes** the search value — CodeIgniter's query builder handles escaping.
3. **`$search` trimmed and nullable** — empty string means no LIKE clause, avoiding `LIKE '%%'` which is a full-table scan with no benefit.
4. **`$limit` clamped to 1–100** — prevents a client from requesting `limit=999999` and defeating pagination.
5. **`$page` clamped to min 1** — prevents negative OFFSET.

### 2.6 Edge Cases to Handle

| Edge Case | Behavior |
|-----------|----------|
| `page` exceeds `total_pages` | Return empty `items` array, `total_data` still correct |
| `search` matches nothing | `items` empty, `total_data` = 0, `total_pages` = 0 |
| `search` with special SQL chars (`%`, `_`) | CI3's `like()` escapes them via `$this->db->escape_like_str()` |
| No query params at all | Backward-compatible: returns page 1, limit 10, no search |
| `limit=0` | Clamped to 1 |
| `page=0` or `page=-5` | Clamped to 1 |

### 2.7 What Stays Unchanged

- JWT auth guard (lines 28–37) — no changes
- Type-casting of `id` to int (line 43) — preserved
- Soft-delete filter `is_active = 1` — preserved
- HTTP status code 200 and Indonesian message — preserved
- CORS headers in `__construct` — unchanged

### 2.8 Testing Plan

After implementation, add test cases in `tests/api/`:

1. **Default (no params):** `GET /api/v1/master/polda` → 200, `data.items` has ≤10 items, `pagination.total_data` = 38
2. **Page 2:** `GET /api/v1/master/polda?page=2&limit=10` → `pagination.current_page` = 2
3. **Search:** `GET /api/v1/master/polda?search=Jawa` → all items contain "Jawa" in `nama_polda`
4. **Search no results:** `GET /api/v1/master/polda?search=ZZZNOTFOUND` → empty items, `pagination.total_data` = 0
5. **Custom limit:** `GET /api/v1/master/polda?limit=5` → `pagination.per_page` = 5, max 5 items
6. **Sort descending:** `GET /api/v1/master/polda?sort_by=nama_polda&sort_order=desc` → items sorted Z→A
7. **Invalid sort column:** `GET /api/v1/master/polda?sort_by=evil;DROP` → falls back to `id` ASC (no error)
8. **Invalid limit:** `GET /api/v1/master/polda?limit=9999` → clamped to 100
9. **Auth required:** No token → 401

### 2.9 Backward Compatibility Risk

**Breaking change:** The `data` field changes from `[...]` (array) to `{items: [...], pagination: {...}}` (object). The Flutter client must be updated to read `data.items` instead of `data`.

**Mitigation option:** If the Flutter team cannot deploy simultaneously, implement the pagination behind an opt-in query parameter (e.g., `?paginate=1`). When absent, return the old array format. This allows gradual migration.

---

## 3. Summary

| Aspect | Current | Target |
|--------|---------|--------|
| **Search** | None | `?search=keyword` → `LIKE '%keyword%'` on `nama_polda` |
| **Pagination** | Returns all rows | `?page=1&limit=10` with `LIMIT`/`OFFSET` |
| **Sorting** | Unordered | `?sort_by=id&sort_order=asc` with whitelist |
| **Response** | `{data: [...]}` | `{data: {items: [...], pagination: {...}}}` |
| **Data size** | 38 rows (always) | Configurable per-page (default 10) |

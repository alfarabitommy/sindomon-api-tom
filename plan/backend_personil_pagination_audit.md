# Backend Personnel Directory Pagination & Search Audit

> **Status:** Audit Complete — Awaiting user approval before code changes
> **Date:** 2026-08-08
> **Scope:** `GET /api/v1/sdm/personil` — functional pagination + real-time search
> **Controller:** `Sdm::personil_get()` in `application/controllers/Sdm.php` (lines 125–238)

---

## 1. Current Logic

### 1.1 Route

```php
// application/config/routes.php (implicit CI3 REST pattern)
// GET /api/v1/sdm/personil  →  Sdm::personil_get()
```

### 1.2 Auth Gate

- Calls `$this->_extract_jwt_payload()` (internal fallback JWT extraction — lines 699–717).
- Returns 401 if token is missing/invalid.
- **Role-based jurisdiction** (lines 140–160):
  - `role_id=2` (Operator Polda): auto-locked to JWT `polda_id`. Cannot see personnel outside their Polda.
  - `role_id=1` (Super Admin) / `role_id=3` (Eksekutif): optional `?polda_id=` query param for filtering.
  - Any other role → 403.

### 1.3 Query (current — lines 162–219)

The query is built entirely via CodeIgniter query builder chaining (NOT raw SQL):

```php
// ── 3. QUERY: SELECT + 4 LEFT JOINs ──
$this->db->select("
    p.personil_id,
    p.nrp,
    p.nama_lengkap,
    p.status_aktif,
    p.polda_id,
    p.polres_id,
    p.pangkat_id,
    p.jabatan_id,
    pkt.nama_pangkat,
    jbt.nama_jabatan,
    prs.nama_polres,
    pda.nama_polda
")
->from('tbl_personil p')
->join('tbl_pangkat pkt', 'p.pangkat_id = pkt.pangkat_id', 'left')
->join('tbl_jabatan jbt', 'p.jabatan_id = jbt.jabatan_id', 'left')
->join('tbl_polres prs',  'p.polres_id = prs.polres_id', 'left')
->join('tbl_polda pda',   'p.polda_id = pda.id AND pda.is_active = 1', 'left');

// ── 4. DYNAMIC FILTERS (GET params) ──

// ?search= (nama_lengkap OR nrp)  ← ALREADY EXISTS
$search = $this->input->get('search');
if ($search !== null && $search !== '') {
    $this->db->group_start()
        ->like('p.nama_lengkap', $search)
        ->or_like('p.nrp', $search)
        ->group_end();
}

// ?polres_id= (int)  ← ALREADY EXISTS
$polres_id = $this->input->get('polres_id');
if ($polres_id !== null && $polres_id !== '') {
    $this->db->where('p.polres_id', (int) $polres_id);
}

// ?status= (enum: Aktif, Mutasi, Pensiun)  ← ALREADY EXISTS
$status = $this->input->get('status');
if ($status !== null && $status !== '') {
    $valid = array('Aktif', 'Mutasi', 'Pensiun');
    if (in_array($status, $valid)) {
        $this->db->where('p.status_aktif', $status);
    } else {
        // 400 error returned
    }
}

// ── 5. ORDER & EXECUTE ──
$this->db->order_by('p.nrp', 'ASC');
$query = $this->db->get();       // ← NO table name (qb_from already set by ->from())
$rows = $query->result_array();

// ── 6. TYPE CAST ──
foreach ($rows as &$row) {
    $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;
    $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;
    $row['polres_id']  = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
    $row['polda_id']   = (int) $row['polda_id'];
}
unset($row);

// ── 7. SUCCESS ──
echo json_encode([
    "message" => "Daftar personel berhasil dimuat.",
    "status" => 200,
    "data" => $rows       // ← FLAT ARRAY — no pagination envelope
]);
```

### 1.4 Feature Matrix

| Feature | Currently Implemented? | Detail |
|---------|----------------------|--------|
| **JWT Auth** | ✅ Yes | `_extract_jwt_payload()` guard |
| **Role-based jurisdiction** | ✅ Yes | Operator locked to JWT `polda_id`; Admin/Eksekutif optional `?polda_id=` |
| **Search** | ✅ Yes | `?search=` → `LIKE` on `p.nama_lengkap` AND `p.nrp` (OR-group) |
| **polres_id filter** | ✅ Yes | `?polres_id=` → exact match on `p.polres_id` |
| **status filter** | ✅ Yes | `?status=Aktif\|Mutasi\|Pensiun` with whitelist validation |
| **JOINs for text names** | ✅ Yes | 4 LEFT JOINs (pangkat, jabatan, polres, polda) — frontend displays these successfully |
| **ORDER BY** | ✅ Yes | `p.nrp ASC` |
| **Type casting** | ✅ Yes | All FK IDs cast to `(int)` for Flutter |
| **Pagination** | ❌ No | No `LIMIT`, no `OFFSET`, no `page`/`limit` params |
| **Count-first pattern** | ❌ No | No `COUNT(*)` query before data fetch |
| **Pagination metadata** | ❌ No | Response is `{status, message, data: [...]}` — flat array |

### 1.5 The 4 JOINs (MUST preserve)

These are critical — the Flutter frontend displays text names, not raw IDs. Dropping any of these would break the UI.

| Alias | Table | ON Condition | Type | Purpose |
|-------|-------|-------------|------|---------|
| `pkt` | `tbl_pangkat` | `p.pangkat_id = pkt.pangkat_id` | LEFT | Display `nama_pangkat` (e.g., "Brigadir Jenderal Polisi") |
| `jbt` | `tbl_jabatan` | `p.jabatan_id = jbt.jabatan_id` | LEFT | Display `nama_jabatan` (e.g., "Kapolda") |
| `prs` | `tbl_polres` | `p.polres_id = prs.polres_id` | LEFT | Display `nama_polres` (nullable — personnel may not be assigned to a Polres) |
| `pda` | `tbl_polda` | `p.polda_id = pda.id AND pda.is_active = 1` | LEFT | Display `nama_polda` (with soft-delete guard on the Polda itself) |

All four are **LEFT JOINs** — they don't filter out rows, only enrich them. Every `personil` row always has `pangkat_id`, `jabatan_id`, and `polda_id` (NOT NULL in schema), so the only nullable join result is `nama_polres`.

### 1.6 Existing Search Columns

Search already targets two columns via `OR LIKE`:

| Column | Table | Type | Search suitability |
|--------|-------|------|--------------------|
| `nama_lengkap` | `tbl_personil` | `varchar(100)` | ✅ Primary — user-facing name |
| `nrp` | `tbl_personil` | `varchar(20)` | ✅ Secondary — police registration number |

Both are good search targets. No changes needed to the search logic — the `group_start()/or_like()/group_end()` pattern is correct and secure (CI3's `like()` auto-escapes).

### 1.7 Existing Filters That Affect WHERE

These must continue to work after pagination is added. They all filter on `tbl_personil` columns, not joined-table columns:

| Filter | Column | Operator |
|--------|--------|----------|
| Jurisdiction (role_id=2) | `p.polda_id = ?` | `=` (from JWT) |
| Jurisdiction (role_id=1,3) | `p.polda_id = ?` | `=` (from `?polda_id=`) |
| Search | `p.nama_lengkap LIKE` OR `p.nrp LIKE` | `LIKE '%...%'` |
| Polres | `p.polres_id = ?` | `=` (from `?polres_id=`) |
| Status | `p.status_aktif = ?` | `=` (from `?status=`) |

---

## 2. Refactor Blueprint

### 2.1 New Query Parameters to Add

| Parameter | Type | Default | Clamp | Description |
|-----------|------|---------|-------|-------------|
| `page` | int | `1` | min 1 | 1-based page number |
| `limit` | int | `10` | 1–100 | Items per page |

**Note:** `search`, `polres_id`, `status`, and `polda_id` already exist — no changes needed.

### 2.2 Count-First Pattern (with Critical CI3 Gotcha)

This project has already solved the CI3 count-first pattern (see `plan/backend_polda_pagination_build.md` §3). The key insight:

```
count_all_results('table', false) → sets qb_from + executes COUNT → preserves qb_state
get()  (NO table argument!)       → reuses qb_from + qb_where + qb_join from above
```

**The gotcha:** Calling `get('tbl_personil p')` AFTER `count_all_results('tbl_personil p', false)` would result in `qb_from = ['tbl_personil p', 'tbl_personil p']` — a cartesian-product bug. The fix: call `count_all_results('', false)` with an **empty string** so it doesn't re-add the table to `qb_from`, and call `get()` without arguments.

### 2.3 Insertion Point in the Current Flow

The `count_all_results()` call must be inserted **after** all WHERE/LIKE conditions have been added, but **before** `order_by()` and `limit()`:

```
[Step 1] AUTH guard (unchanged)
[Step 2] Extract page, limit params (NEW — after auth, before query building)
[Step 3] Role & jurisdiction WHERE clauses (unchanged)
[Step 4] SELECT + FROM + 4 JOINs (unchanged)
[Step 5] Search WHERE (unchanged)
[Step 6] polres_id WHERE (unchanged)
[Step 7] status WHERE (unchanged, including 400 early-return)
[Step 8] ═══ COUNT QUERY ═══ $total = $this->db->count_all_results('', false);  ← NEW
[Step 9] ORDER BY p.nrp ASC (unchanged, but now AFTER count)
[Step 10] LIMIT/OFFSET (NEW)
[Step 11] $this->db->get() — NO table argument! (unchanged call style)
[Step 12] Type cast (unchanged)
[Step 13] Return paginated envelope (MODIFIED)
```

### 2.4 Why `count_all_results('', false)` Works Here

1. `->from('tbl_personil p')` was already called in Step 4 → `qb_from = ['tbl_personil p']`
2. `count_all_results('', false)` checks `if ($table !== '')` → skips `from()` → `qb_from` stays as `['tbl_personil p']` (no duplicate)
3. The JOINs are still in `qb_join` — CI3 compiles `SELECT COUNT(*) AS numrows FROM tbl_personil p LEFT JOIN ... WHERE ...` — correct count
4. `$reset = FALSE` → query builder state is preserved for the subsequent `get()`
5. `get()` without table argument → reuses `qb_from` → no cartesian product

### 2.5 Why JOINs in the COUNT Are Harmless

All four JOINs are:
- **LEFT JOIN** (don't filter rows)
- **N:1 from `tbl_personil`'s perspective** (each personil has exactly one pangkat, one jabatan, one polda, and at most one polres)

Therefore `COUNT(*)` on the joined result = `COUNT(*)` on `tbl_personil` alone. The JOINs add no CPU concern at the seeder scale (250 personnel × 4 trivial lookups).

### 2.6 Response Envelope (MODIFIED)

**Current (flat array):**
```json
{
  "status": 200,
  "message": "Daftar personel berhasil dimuat.",
  "data": [ ... ]
}
```

**New (paginated envelope):**
```json
{
  "status": 200,
  "message": "Daftar personel berhasil dimuat.",
  "data": {
    "items": [ ... ],
    "pagination": {
      "total_data": 250,
      "total_pages": 25,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

**⚠️ Breaking change:** `data` changes from `[...]` (array) to `{items: [...], pagination: {...}}` (object). The Flutter frontend must be updated to read `data.items` instead of `data`. This matches the pattern already established for `polda_get()`, `users/all()`, `kategori_senjata_get()`, and `polres_get()`.

### 2.7 Pseudocode of Refactored Method

```php
public function personil_get()
{
    // ── 1. AUTH ── (unchanged)
    $payload = $this->_extract_jwt_payload();
    if (!$payload) { /* 401 */ return; }

    // ── 2. ROLE & JURISDICTION ── (unchanged)
    $role_id = (int) $payload['role_id'];
    $jwt_polda_id = (int) $payload['polda_id'];

    if ($role_id == 2) {
        $this->db->where('p.polda_id', $jwt_polda_id);
    } else if ($role_id == 1 || $role_id == 3) {
        $query_polda = $this->input->get('polda_id');
        if ($query_polda !== null && $query_polda !== '') {
            $this->db->where('p.polda_id', (int) $query_polda);
        }
    } else { /* 403 */ return; }

    // ── NEW: Extract pagination params ──
    $page  = max(1, (int) ($this->input->get('page') ?? 1));
    $limit = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // ── 3. SELECT + 4 JOINs ── (unchanged)
    $this->db->select("p.personil_id, p.nrp, p.nama_lengkap, p.status_aktif,
                       p.polda_id, p.polres_id, p.pangkat_id, p.jabatan_id,
                       pkt.nama_pangkat, jbt.nama_jabatan,
                       prs.nama_polres, pda.nama_polda")
        ->from('tbl_personil p')
        ->join('tbl_pangkat pkt', 'p.pangkat_id = pkt.pangkat_id', 'left')
        ->join('tbl_jabatan jbt', 'p.jabatan_id = jbt.jabatan_id', 'left')
        ->join('tbl_polres prs',  'p.polres_id = prs.polres_id', 'left')
        ->join('tbl_polda pda',   'p.polda_id = pda.id AND pda.is_active = 1', 'left');

    // ── 4. DYNAMIC FILTERS ── (unchanged)
    $search = $this->input->get('search');
    if ($search !== null && $search !== '') {
        $this->db->group_start()
            ->like('p.nama_lengkap', $search)
            ->or_like('p.nrp', $search)
            ->group_end();
    }

    $polres_id = $this->input->get('polres_id');
    if ($polres_id !== null && $polres_id !== '') {
        $this->db->where('p.polres_id', (int) $polres_id);
    }

    $status = $this->input->get('status');
    if ($status !== null && $status !== '') {
        $valid = ['Aktif', 'Mutasi', 'Pensiun'];
        if (in_array($status, $valid)) {
            $this->db->where('p.status_aktif', $status);
        } else {
            /* 400 — return early BEFORE count, just like current code */ return;
        }
    }

    // ── NEW: Count total matching rows ──
    // CRITICAL: empty string → does NOT re-add table to qb_from
    $total_data = $this->db->count_all_results('', false);

    // ── 5. ORDER & PAGINATION ──
    $this->db->order_by('p.nrp', 'ASC');
    $this->db->limit($limit, ($page - 1) * $limit);

    // CRITICAL: get() without table name — qb_from is already set
    $query = $this->db->get();
    $rows = $query->result_array();

    // ── 6. TYPE CAST ── (unchanged)
    foreach ($rows as &$row) {
        $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;
        $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;
        $row['polres_id']  = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
        $row['polda_id']   = (int) $row['polda_id'];
    }
    unset($row);

    // ── 7. SUCCESS ── (MODIFIED — paginated envelope)
    $this->output->set_status_header(200);
    echo json_encode([
        "message" => "Daftar personel berhasil dimuat.",
        "status" => 200,
        "data" => [
            "items" => $rows,
            "pagination" => [
                "total_data"   => (int) $total_data,
                "total_pages"  => (int) ceil($total_data / $limit),
                "current_page" => $page,
                "per_page"     => $limit
            ]
        ]
    ]);
}
```

### 2.8 Edge Cases

| Edge Case | Behavior |
|-----------|----------|
| `page` exceeds `total_pages` | Return empty `items` array; `total_data` and `total_pages` still correct |
| `search` matches nothing | `items` empty, `total_data` = 0, `total_pages` = 0 |
| `search` + `polres_id` + `status` all empty | Returns page 1 of all personnel (within jurisdiction) |
| `search` with special chars (`%`, `_`) | CI3's `like()` auto-escapes via `escape_like_str()` |
| `limit=0` or negative | Clamped to 1 |
| `limit=9999` | Clamped to 100 |
| `page=0` or `page=-5` | Clamped to 1 |
| `page` is non-numeric string | `(int) "abc"` → 0 → clamped to 1 |
| Invalid `status` value | Returns 400 BEFORE the count query (no wasted DB round-trip) |
| `polda_id` query param for Admin/Eksekutif | Still works as before — applied as WHERE before count |
| JWT missing/invalid | Returns 401 before any query (unchanged) |

### 2.9 What Stays Unchanged

- JWT auth guard — no changes
- Role-based jurisdiction (`role_id=2` locked, `1`/`3` optional `polda_id`) — no changes
- All 4 LEFT JOINs — no changes (table aliases, ON conditions, join types all preserved)
- Search logic (`group_start()`/`or_like()` on `nama_lengkap` + `nrp`) — no changes
- `polres_id` filter — no changes
- `status` filter with whitelist validation — no changes
- Type casting of FK IDs — no changes
- `ORDER BY p.nrp ASC` — no changes
- CORS headers in `__construct` — unchanged
- HTTP status codes (200, 401, 403, 400) — unchanged

### 2.10 Security Review

1. **`$page` and `$limit` are integer-cast** → injection impossible
2. **`$search` goes through CI3's `like()`** → auto-escaped via `$this->db->escape_like_str()`
3. **`$polres_id` and `$polda_id` are `(int)` cast** → injection impossible
4. **`$status` is whitelist-validated** against `['Aktif', 'Mutasi', 'Pensiun']` before use
5. **No raw string interpolation** into SQL — all values go through query builder or escape methods
6. **`count_all_results('', false)` is safe** — empty string prevents duplicate FROM entry (no SQL injection risk from table name)

### 2.11 Testing Plan

After implementation, verify with these E2E scenarios:

1. **Default (no params):** `GET /api/v1/sdm/personil` → 200, `data.items` has ≤10 items, `data.pagination.total_data` > 0
2. **Page 2:** `GET /api/v1/sdm/personil?page=2&limit=5` → `pagination.current_page` = 2, 5 items, `pagination.per_page` = 5
3. **Search by name:** `GET /api/v1/sdm/personil?search=andi` → all items have "andi" in `nama_lengkap` or `nrp`
4. **Search by NRP:** `GET /api/v1/sdm/personil?search=9102` → items match NRP pattern
5. **Search no results:** `GET /api/v1/sdm/personil?search=ZZZNOTFOUND` → empty items, `total_data` = 0
6. **Filter by polres:** `GET /api/v1/sdm/personil?polres_id=1` → all items have `polres_id` = 1
7. **Filter by status:** `GET /api/v1/sdm/personil?status=Aktif` → all items have `status_aktif` = "Aktif"
8. **Combined:** `GET /api/v1/sdm/personil?search=andi&status=Aktif&page=1&limit=5` → paginated, filtered, searched
9. **Admin polda filter:** `GET /api/v1/sdm/personil?polda_id=1` → all items have `polda_id` = 1
10. **Operator auto-lock:** Token with `role_id=2, polda_id=5` → no `?polda_id=` param → all items have `polda_id` = 5
11. **Limit clamping:** `GET /api/v1/sdm/personil?limit=9999` → `per_page` = 100 (not 9999)
12. **Page clamping:** `GET /api/v1/sdm/personil?page=-5` → `current_page` = 1
13. **Auth required:** No token → 401
14. **Wrong role:** Token with `role_id=99` → 403
15. **Invalid status:** `GET /api/v1/sdm/personil?status=invalid` → 400

---

## 3. Summary

| Aspect | Current | Target |
|--------|---------|--------|
| **Search** | ✅ `?search=` on `nama_lengkap` + `nrp` | Unchanged |
| **Filters** | ✅ `?polres_id=`, `?status=`, `?polda_id=` | Unchanged |
| **JOINs** | ✅ 4 LEFT JOINs for display names | Unchanged |
| **Pagination** | ❌ Returns all rows | `?page=1&limit=10` with `LIMIT`/`OFFSET` |
| **Count-first** | ❌ No count query | `count_all_results('', false)` before ORDER/LIMIT |
| **Response** | `{data: [...]}` | `{data: {items: [...], pagination: {...}}}` |
| **Lines changed** | — | ~20 lines added/modified in `personil_get()` |

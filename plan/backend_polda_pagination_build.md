# Backend Polda Pagination & Search — Build Report

> **Status:** Implemented & Verified (E2E green)
> **Date:** 2026-08-03
> **Scope:** `polda_get()` in `application/controllers/Master.php` — real-time search + functional pagination
> **Pre-requisite audit:** `plan/backend_polda_pagination_audit.md`

---

## 1. What Changed

**File:** `application/controllers/Master.php`
**Method:** `polda_get()` (only method touched — verified via `git diff`: 1 hunk, 34 insertions / 2 deletions)

### New Query Parameters

| Parameter | Type | Default | Clamp | Behavior |
|-----------|------|---------|-------|----------|
| `search` | string | `''` | — | `LIKE '%{search}%'` on `nama_polda` (wildcards escaped by CI3) |
| `page` | int | `1` | min 1 | 1-based page; non-numeric/zero → 1 |
| `limit` | int | `10` | 1..100 | Items per page; `0`, negatives, `9999` all safely clamped |

---

## 2. The Rewritten `polda_get()` (Exact Code)

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

    // --- Pagination & real-time search query parameters ---
    $search = trim((string) $this->input->get('search'));
    $page   = max(1, (int) ($this->input->get('page') ?? 1));
    $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // Only active (not soft-deleted) Polda are shown to the frontend.
    $this->db->where('is_active', 1);

    // Optional real-time search: partial (LIKE) match on nama_polda.
    if ($search !== '') {
        $this->db->like('nama_polda', $search);
    }

    // Total rows matching the current filter. The FALSE second argument
    // preserves the Query Builder state (WHERE/LIKE) for the get() below.
    $total_data = $this->db->count_all_results('tbl_polda', false);

    // Stable ordering so pagination pages never overlap between requests.
    $this->db->order_by('id', 'ASC');

    // Pagination: LIMIT {limit} OFFSET {(page - 1) * limit}
    // NOTE: get() is intentionally called WITHOUT a table name —
    // count_all_results() already set qb_from; passing the table again
    // would compile "FROM tbl_polda, tbl_polda" (cartesian product).
    $this->db->limit($limit, ($page - 1) * $limit);
    $rows = $this->db->get()->result_array();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Polda berhasil dimuat.',
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

## 3. ⚠️ Critical CI3 Gotcha Discovered (and how it was handled)

The spec called for `count_all_results('tbl_polda', false)` **then** `get('tbl_polda')`. Source audit of this repo's `system/database/DB_query_builder.php` proved that is a **cartesian-product bug**:

- `count_all_results()` (line 1401) calls `from($table)` → `qb_from = ['tbl_polda']`
- `get('tbl_polda')` (line 1371) **also** calls `from($table)` → `qb_from = ['tbl_polda', 'tbl_polda']`
- Compiled SQL becomes `SELECT * FROM tbl_polda, tbl_polda WHERE ...` → 38×38 = 1,444-row cartesian

**Fix:** `get()` is called **without** the table argument — the preserved `qb_from` from `count_all_results(..., false)` already carries it. This honors the original intent ("preserve the query builder state for the next get") exactly, verified by the E2E test showing **zero duplicate IDs** across all paginated responses.

---

## 4. Response Envelope (New Shape)

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

**Breaking change note:** `data` changed from a plain array `[...]` to `{items: [...], pagination: {...}}`. The Flutter frontend must read `data.items`. Flagged in the audit report (§2.9) — coordinate the frontend deploy.

---

## 5. Verification Results

### 5.1 Static Checks

| Check | Result |
|-------|--------|
| `php -l application/controllers/Master.php` | ✅ No syntax errors |
| `git diff` — only `polda_get()` modified | ✅ 1 hunk, 34+/2- |
| CI3 `like()` wildcard escaping (source, line 954/965) | ✅ Default ON — `%`/`_` in search input are escaped |

### 5.2 Live E2E (PHP built-in server + `tests/router.php`, real MySQL `sindomondb`, 38 Polda rows)

**32/32 assertions passed:**

| Test Case | Result |
|-----------|--------|
| No params → 200, 10 items, total=38, pages=4, page=1, per=10 | ✅ |
| `?page=2` → current_page=2, 10 items | ✅ |
| `?page=4` → 8 items (last page, 38−3×10) | ✅ |
| `?page=5` (beyond) → 0 items, total_data still 38 | ✅ |
| `?search=Jawa` → exactly 3 items (Barat/Tengah/Timur), total=3 | ✅ |
| `?search=ZZZNOTFOUND` → 0 items, total=0, pages=0 | ✅ |
| `?search=Polda&page=2&limit=10` → 10 items, total=38 | ✅ |
| `?limit=5` → 5 items, pages=8 | ✅ |
| `?limit=9999` → clamped per_page=100, 38 items | ✅ |
| `?page=0` → current_page=1 | ✅ |
| `?limit=0` → clamped per_page=1 | ✅ |
| `?page=abc` → current_page=1 | ✅ |
| No token → 401 | ✅ |
| **Duplicate-ID check on every paginated response** (cartesian sentinel) | ✅ 0 dupes everywhere |
| `/master/wilayah` untouched → 38 items | ✅ |
| `/master/polres` untouched → 200 | ✅ |

### 5.3 Project Regression Suite (`npm test`)

**13 passed, 2 failed, 1 flaky.**

A/B verification (stash my change → rerun the 2 failing tests → restore):

> The **identical 2 tests fail on the unmodified codebase**:
> - `master_polres.spec.ts:149` — DELETE Conflict Trap (409)
> - `seeder_master.spec.ts:94` — org-tree vacancy alert
>
> Both are timing/environment-sensitive tests **unrelated to `polda_get()`** (they exercise `polres_delete` conflict logic and the SDM org-tree). Pre-existing, not caused by this change.

---

## 6. Generated SQL (for reference)

```sql
-- count (run first, with preserved state)
SELECT COUNT(*) AS numrows FROM tbl_polda WHERE is_active = 1 AND nama_polda LIKE '%Jawa%'

-- data query
SELECT * FROM tbl_polda
WHERE is_active = 1 AND nama_polda LIKE '%Jawa%'
ORDER BY id ASC
LIMIT 10 OFFSET 10
```

---

## 7. Files Touched

| File | Change |
|------|--------|
| `application/controllers/Master.php` | `polda_get()` refactored (only method) |
| `plan/backend_polda_pagination_build.md` | This report (new) |
| `plan/backend_polda_pagination_audit.md` | Prior audit (unchanged, from debug phase) |

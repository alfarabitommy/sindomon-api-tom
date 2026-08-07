# Sarpras & Almatsus — Pagination & Real-Time Search Audit

**Date:** 2026-08-07  
**Endpoint:** `GET /api/v1/logistik/sarpras`  
**Controller:** `application/controllers/Logistik.php` → `sarpras_get()` (line 1331)  
**Audit scope:** Compare `sarpras_get()` against the established pagination pattern in `amunisi_get()` (line 626) and `senjata_get()` (line 289).

---

## 1. Current Logic

### 1.1 Table

`tbl_sarpras` — a flat table with no foreign-key relationships:

```sql
CREATE TABLE IF NOT EXISTS `tbl_sarpras` (
    `sarpras_id`      varchar(36) NOT NULL,
    `polda_id`        int(11) DEFAULT NULL,
    `kode_barang`     varchar(100) NOT NULL,        -- UNIQUE
    `nama_barang`     varchar(255) NOT NULL,
    `kategori`        varchar(50) DEFAULT NULL,       -- plain VARCHAR, NOT a FK
    `kondisi`         enum('Baik','Rusak Ringan','Rusak Berat') DEFAULT 'Baik',
    `tahun_pengadaan` varchar(10) DEFAULT NULL,
    `foto_url`        varchar(500) DEFAULT NULL,
    `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at`      datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`sarpras_id`),
    UNIQUE KEY `uq_kode_barang` (`kode_barang`)
);
```

**Key observation:** `kategori` is a raw VARCHAR, not a foreign key. There is **no** `tbl_kategori_sarpras` or similar lookup table. This means the refactor is simpler than `amunisi_get` — no JOIN is needed.

### 1.2 JOINs

**None.** `sarpras_get()` queries `tbl_sarpras` directly with no aliased table, no LEFT JOIN to any category dimension.

Compare:

| Method | Table Alias | JOIN |
|--------|-------------|------|
| `sarpras_get()` | none (`from('tbl_sarpras')`) | None |
| `senjata_get()` | `s` (`from('tbl_senjata s')`) | `LEFT JOIN tbl_kategori_senjata k ON s.kategori_id = k.kategori_id AND k.is_active = 1` |
| `amunisi_get()` | `a` (`from('tbl_amunisi_batch a')`) | `LEFT JOIN tbl_kategori_senjata k ON a.kategori_id = k.kategori_id AND k.is_active = 1` |

### 1.3 Jurisdiction Filter (BUG — matches the same bug we fixed in `amunisi_get`)

**Current code (sarpras_get, line 1345–1352):**

```php
// ── 2. JURISDICTION ──
$polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

// ...

if ($polda_id > 0) {
    $this->db->where('polda_id', $polda_id);
}
```

**Problem — missing role-based delegation:**

There is **no** `role_id` check and **no** `?polda_id=` query param override. This is the exact same bug that was fixed in `amunisi_get()`.

| Role | Expected Behavior | Current Behavior (BUG) |
|------|------------------|----------------------|
| Super Admin (role_id=1) | Can optionally pass `?polda_id=` to filter; `polda_id=0` means "show all" | Always locked to JWT `polda_id`; if JWT has `polda_id=0`, returns **all rows** (unfiltered). Cannot use `?polda_id=` override. |
| Operator Polda (role_id=2) | Locked to JWT `polda_id` — cannot cross jurisdictions | Works correctly (gets `polda_id` from JWT). |
| Eksekutif (role_id=3) | Can optionally pass `?polda_id=` to filter; `polda_id=0` means "show all" | Same as Super Admin: locked to JWT `polda_id`, no override. |

**The fix (from `amunisi_get`, lines 643–652):**

```php
$role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
$polda_id = 0;
if ($role_id == 2) {
    $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
} else if ($role_id == 1 || $role_id == 3) {
    $query_polda = $this->input->get('polda_id');
    if ($query_polda !== null && $query_polda !== '') {
        $polda_id = (int) $query_polda;
    }
}
```

**Note:** The same bug also affects `senjata_get()` (line 303), which uses the same simplified pattern.

### 1.4 Search Filter (partially implemented)

**Current code (lines 1357–1363):**

```php
$search = $this->input->get('search');
if ($search !== null && $search !== '') {
    $this->db->group_start();
    $this->db->like('nama_barang', $search);
    $this->db->or_like('kode_barang', $search);
    $this->db->group_end();
}
```

Search is already **partially implemented** — it uses `group_start()`/`group_end()` to keep the OR inside parentheses so it never bypasses the jurisdiction filter. The search columns are appropriate: `kode_barang` (unique code) and `nama_barang` (descriptive name).

**Potential enhancement:** `kategori` and `kondisi` could also be added as searchable columns, but the current two-column search is a reasonable minimum. We will keep the existing columns and NOT expand the search scope unless the user explicitly requests it — this avoids scope creep.

### 1.5 Pagination — MISSING

**Current code (lines 1365–1367):**

```php
$this->db->order_by('created_at', 'DESC');
$query = $this->db->get();
$rows = $query->result_array();
```

- **No `limit`/`offset`** — returns every row in the table.
- **No `count_all_results`** — no total_data for pagination metadata.
- **No query-param parsing** for `?page=` or `?limit=`.

### 1.6 Response Envelope — MISSING pagination wrapper

**Current (flat array):**

```php
"data" => $mapped   // e.g. [{...}, {...}, ...]
```

**Expected (paginated envelope, per `amunisi_get`):**

```php
"data" => array(
    "items" => $mapped,
    "pagination" => array(
        "total_data"   => (int) $total_data,
        "total_pages"  => (int) ceil($total_data / $limit),
        "current_page" => $page,
        "per_page"     => $limit
    )
)
```

**Flutter impact:** The frontend must be updated to unwrap `data.items` instead of iterating `data` directly. This is a **breaking change** to the response shape.

### 1.7 Integer Casting

Current `sarpras_get()` casts:
- `polda_id` → `(int)`

**Missing casts** (compared to other endpoints):
- No auto-increment `sarpras_id` to cast (it's a UUID VARCHAR).
- `tahun_pengadaan` is a VARCHAR in the schema, not an INT — no cast needed.
- Other columns are strings — no cast needed.

This is fine as-is; no additional integer casting is required.

---

## 2. Refactor Blueprint

### 2.1 Changes Overview

We will transform `sarpras_get()` to match the `amunisi_get()` pattern with three changes:

1. **Add role-based jurisdiction logic** (fix the role_id=1/3 `?polda_id=` override bug).
2. **Add pagination** (`?page=`, `?limit=`, `count_all_results`, pagination envelope).
3. **Preserve existing search** (already correct — just integrate with count-first pattern).

**No JOIN changes needed** — `tbl_sarpras` has no foreign-key category table.

### 2.2 Step-by-Step Implementation Plan

#### Step A: Replace Jurisdiction Block (lines 1345–1352)

Replace the simple `$polda_id = isset($payload['polda_id']) ...` block with the full role-gated version from `amunisi_get` (lines 643–652):

```php
// ── 2. ROLE & JURISDICTION ──
$role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
$polda_id = 0;
if ($role_id == 2) {
    $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
} else if ($role_id == 1 || $role_id == 3) {
    $query_polda = $this->input->get('polda_id');
    if ($query_polda !== null && $query_polda !== '') {
        $polda_id = (int) $query_polda;
    }
}
```

#### Step B: Add Query Param Parsing (insert after jurisdiction, before query build)

```php
// ── 3. QUERY PARAMS (pagination & real-time search) ──
$search = $this->input->get('search');
$page   = max(1, (int) ($this->input->get('page') ?? 1));
$limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));
```

#### Step C: Add Table Alias (optional but recommended for consistency)

Change `$this->db->from('tbl_sarpras');` to `$this->db->from('tbl_sarpras s');` and prefix all column references with `s.` (e.g., `s.polda_id`, `s.nama_barang`, `s.kode_barang`, `s.created_at`). This is not strictly necessary (no JOIN ambiguity) but matches the codebase convention (`senjata_get` uses `s.*`, `amunisi_get` uses `a.*`).

**If we skip the alias**, we must be careful with `count_all_results('', false)` — see note in Step E.

**Recommendation:** Add the alias for consistency and forward-compatibility. The risk of skipping it is low since there's only one table.

#### Step D: Wire Search Into Count-First

Keep the existing search block but prefix columns with `s.` if using an alias:

```php
if ($search !== null && $search !== '') {
    $this->db->group_start();
    $this->db->like('s.nama_barang', $search);   // or 'nama_barang' without alias
    $this->db->or_like('s.kode_barang', $search);
    $this->db->group_end();
}
```

#### Step E: Insert Count-First Pattern

After the search block and BEFORE `order_by`/`get()`, insert:

```php
// ── 5. COUNT-FIRST: total rows matching the current filters ──
$total_data = $this->db->count_all_results('', false);

// ── 6. ORDER & PAGINATION ──
$this->db->order_by('s.created_at', 'DESC');   // or 'created_at' without alias
$this->db->limit($limit, ($page - 1) * $limit);
$query = $this->db->get(); // NO table name — qb_from is already set
$rows  = $query->result_array();
```

**Critical gotcha with `count_all_results('', false)`:**
- First argument `''` (empty string) means "use the table already set by `->from()`" — do NOT pass `'tbl_sarpras'` again or it will duplicate the FROM clause.
- Second argument `false` means "don't reset the query builder" — preserves WHERE, LIKE, and JOIN state for the subsequent `->get()`.
- This is the exact pattern from `senjata_get` (line 339) and `amunisi_get` (line 687).

#### Step F: Replace Response Envelope

Replace the flat `"data" => $mapped` with the paginated envelope:

```php
// ── 8. SUCCESS RESPONSE (paginated envelope) ──
$this->output->set_content_type('application/json')->set_status_header(200);
echo json_encode(array(
    "status" => 200,
    "message" => "Daftar sarpras termuat.",
    "data" => array(
        "items" => $mapped,
        "pagination" => array(
            "total_data"   => (int) $total_data,
            "total_pages"  => (int) ceil($total_data / $limit),
            "current_page" => $page,
            "per_page"     => $limit
        )
    )
));
```

### 2.3 Complete Refactored Method (pseudocode)

```
sarpras_get():
  1. AUTH: get_jwt_payload → 401 if missing
  2. ROLE & JURISDICTION: full role-gated polda_id logic (role_id=2 locked, role_id=1/3 can ?polda_id=)
  3. QUERY PARAMS: parse ?search=, ?page= (1-based), ?limit= (1–100, default 10)
  4. BUILD QUERY: from('tbl_sarpras s'), WHERE polda_id if >0, LIKE on nama_barang/kode_barang if search
  5. COUNT-FIRST: count_all_results('', false)
  6. ORDER & PAGINATION: order_by created_at DESC, limit($limit, offset)
  7. MAP: integer-cast polda_id, map all columns
  8. RESPONSE: paginated envelope { items, pagination: { total_data, total_pages, current_page, per_page } }
```

### 2.4 Files to Modify

| File | Change |
|------|--------|
| `application/controllers/Logistik.php` | Refactor `sarpras_get()` method (~60 lines changed) |

**No other files need changes** — no models, no routes, no config.

### 2.5 Breaking Change Notice

The response shape changes from:
```json
{ "status": 200, "message": "...", "data": [{...}, {...}] }
```
to:
```json
{ "status": 200, "message": "...", "data": { "items": [{...}, {...}], "pagination": {...} } }
```

The **Flutter frontend** must be updated to:
- Read `response.data.items` instead of `response.data`
- Render pagination controls using `response.data.pagination`

### 2.6 Default Values

| Param | Default | Min | Max | Notes |
|-------|---------|-----|-----|-------|
| `page` | 1 | 1 | — | 1-based |
| `limit` | 10 | 1 | 100 | Matches `amunisi_get` and `senjata_get` |
| `search` | `null` | — | — | When null/empty, no LIKE filter applied |
| `polda_id` | `null` | — | — | Only for role_id=1/3; ignored for role_id=2 |

### 2.7 Side Bug: `senjata_get()` Has the Same polda_id Issue

During this audit, we discovered that `senjata_get()` (line 303) also uses the simplified `$polda_id = isset($payload['polda_id']) ? ...` pattern without role-based delegation. This is **out of scope** for the current task but should be tracked separately.

---

## 3. Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| Breaking response shape for Flutter client | **HIGH** | Coordinate with frontend team; deploy together |
| `count_all_results('', false)` misuse (passing table name) | **MEDIUM** | Follow the exact pattern from working `amunisi_get` |
| If we skip table alias, column ambiguity is zero (single table) | **LOW** | Either way works; alias is cosmetic |
| Search regressions | **LOW** | Existing search logic is preserved verbatim; only wiring changes |
| Role bypass (polda_id override not working for Admin) | **LOW** | This is a **fix**, not a risk — current behavior is the bug |

---

## 4. Test Plan

After refactoring:

1. **No params:** `GET /api/v1/logistik/sarpras` → returns page 1, 10 items, pagination metadata.
2. **Pagination:** `?page=2&limit=5` → returns page 2, 5 items, correct `total_pages`.
3. **Search:** `?search=HT` → returns items where `nama_barang` OR `kode_barang` LIKE '%HT%', paginated.
4. **Search + pagination:** `?search=HT&page=1&limit=5` → search filtered, then paginated.
5. **Role=2 (Operator):** JWT with `polda_id=5` → returns only polda_id=5 items. `?polda_id=3` is ignored.
6. **Role=1 (Super Admin):** JWT with any polda_id → no filter by default (all rows). `?polda_id=5` → filters to polda_id=5.
7. **Role=3 (Eksekutif):** Same as Super Admin.
8. **Limit boundary:** `?limit=200` → clamped to 100. `?limit=0` → clamped to 1.
9. **Empty search:** `?search=` (empty string) → treated as no search (no LIKE filter).
10. **Page boundary:** `?page=99999` → returns empty items array, correct total_data.

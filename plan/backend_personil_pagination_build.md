# Backend Personil Pagination & Search — Build Report

> **Status:** Implemented — code complete, syntax verified (`php -l` green). E2E to be run in the MySQL-enabled dev environment.
> **Date:** 2026-08-08
> **Scope:** `personil_get()` in `application/controllers/Sdm.php` — functional pagination + real-time search
> **Pre-requisite audit:** `plan/backend_personil_pagination_audit.md`
> **Diff footprint:** 1 file, 8 hunks, 35 insertions / 11 deletions — **all inside `personil_get()`** (lines 117–261). No other method touched.

---

## 1. What Changed

**File:** `application/controllers/Sdm.php`
**Method:** `personil_get()` (only method touched — verified via `git diff`: every hunk `@@` falls within lines 117–261)

### Query Parameters (final)

| Parameter | Type | Default | Clamp | Behavior |
|-----------|------|---------|-------|----------|
| `search` | string | `''` | — | **Pre-existing.** `LIKE` on `p.nama_lengkap` **OR** `p.nrp` via `group_start()/or_like()/group_end()` (wildcards escaped by CI3) |
| `polres_id` | int | — | — | **Pre-existing.** Exact match on `p.polres_id` |
| `status` | enum | — | — | **Pre-existing.** Whitelist `Aktif\|Mutasi\|Pensiun`, else 400 |
| `polda_id` | int | — | — | **Pre-existing.** Admin/Eksekutif only (role_id 1/3); role_id=2 locked to JWT |
| `page` | int | `1` | min 1 | **NEW.** 1-based page; non-numeric/zero → 1 |
| `limit` | int | `10` | 1..100 | **NEW.** Items per page; `0`, negatives, `9999` all safely clamped |

### Preserved Without Change (safety checklist)

- ✅ JWT extraction (`_extract_jwt_payload()`) — unchanged
- ✅ Role & Polda jurisdiction block (role_id=2 locked / 1,3 optional `polda_id`) — unchanged
- ✅ `$this->db->select(...)` — all 12 columns, unchanged
- ✅ All **4 LEFT JOINs** — `tbl_pangkat pkt`, `tbl_jabatan jbt`, `tbl_polres prs`, `tbl_polda pda` (with `pda.is_active = 1`) — unchanged
- ✅ `search` filter (`group_start()` + `or_like()`) — unchanged
- ✅ `polres_id` filter — unchanged
- ✅ `status` filter with 400 early-return — unchanged
- ✅ Type-casting loop (FK IDs → `(int)`, null-safe) — unchanged
- ✅ `ORDER BY p.nrp ASC` — unchanged
- ✅ CORS headers in `__construct` — untouched
- ✅ All other methods in `Sdm.php` (`org_tree_get`, `personil_post`, `personil_put`, `personil_delete`, `hukum_post`, helpers) — **untouched**

---

## 2. The Rewritten `personil_get()` (Exact Code)

```php
/**
 * GET /api/v1/sdm/personil
 * Tarik Daftar Personel (Desentralisasi)
 *
 * Authorization:
 *   - role_id=1 (Administrator) / role_id=3 (Eksekutif): optional ?polda_id=, ?polres_id=, ?search=, ?status=
 *   - role_id=2 (Operator Polda): locked to JWT polda_id
 *
 * Query params: ?search= (nama_lengkap/nrp LIKE), ?polres_id=, ?status=,
 *               ?page= (1-based, min 1), ?limit= (1..100, default 10)
 */
public function personil_get()
{
    // ── 1. AUTH ──
    $payload = $this->_extract_jwt_payload();
    if (!$payload) {
        $this->output->set_status_header(401);
        echo json_encode(array(
            "message" => "Token tidak ditemukan",
            "status" => 401,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 2. ROLE & JURISDICTION ──
    $role_id = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
    $jwt_polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

    if ($role_id == 2) {
        // Operator Polda: locked to JWT polda_id
        $this->db->where('p.polda_id', $jwt_polda_id);
    } else if ($role_id == 1 || $role_id == 3) {
        // Admin / Eksekutif: optional ?polda_id= query param
        $query_polda = $this->input->get('polda_id');
        if ($query_polda !== null && $query_polda !== '') {
            $this->db->where('p.polda_id', (int) $query_polda);
        }
    } else {
        $this->output->set_status_header(403);
        echo json_encode(array(
            "message" => "Akses ditolak",
            "status" => 403,
            "data" => new stdClass()
        ));
        return;
    }

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
    ->join('tbl_polres prs', 'p.polres_id = prs.polres_id', 'left')
    ->join('tbl_polda pda', 'p.polda_id = pda.id AND pda.is_active = 1', 'left');

    // ── 4. DYNAMIC FILTERS (GET params) ──

    // ?page= & ?limit= (pagination: page is 1-based, limit clamped 1..100)
    $page  = max(1, (int) ($this->input->get('page') ?? 1));
    $limit = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // ?search= (nama_lengkap OR nrp)
    $search = $this->input->get('search');
    if ($search !== null && $search !== '') {
        $this->db->group_start()
            ->like('p.nama_lengkap', $search)
            ->or_like('p.nrp', $search)
            ->group_end();
    }

    // ?polres_id= (int)
    $polres_id = $this->input->get('polres_id');
    if ($polres_id !== null && $polres_id !== '') {
        $this->db->where('p.polres_id', (int) $polres_id);
    }

    // ?status= (enum: Aktif, Mutasi, Pensiun)
    $status = $this->input->get('status');
    if ($status !== null && $status !== '') {
        $valid = array('Aktif', 'Mutasi', 'Pensiun');
        if (in_array($status, $valid)) {
            $this->db->where('p.status_aktif', $status);
        } else {
            $this->output->set_status_header(400);
            echo json_encode(array(
                "message" => "Parameter status tidak valid. Gunakan: Aktif, Mutasi, atau Pensiun.",
                "status" => 400,
                "data" => new stdClass()
            ));
            return;
        }
    }

    // ── 5. COUNT-FIRST: total rows matching the current filters ──
    // NOTE: count_all_results('', false) with an EMPTY string keeps the
    // qb_from state ('tbl_personil p') set by ->from() above. Passing the
    // table name again would duplicate FROM -> cartesian product. FALSE
    // preserves all WHERE/LIKE/JOIN state for the get() below.
    $total_data = $this->db->count_all_results('', false);

    // ── 6. ORDER & PAGINATION ──
    $this->db->order_by('p.nrp', 'ASC');
    $this->db->limit($limit, ($page - 1) * $limit);
    $query = $this->db->get(); // NO table name — qb_from is already set
    $rows = $query->result_array();

    // ── 7. TYPE CAST relational IDs (Flutter compatibility) ──
    foreach ($rows as &$row) {
        $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;
        $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;
        $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
        $row['polda_id'] = (int) $row['polda_id'];
    }
    unset($row);

    // ── 8. SUCCESS ──
    $this->output->set_status_header(200);
    echo json_encode(array(
        "message" => "Daftar personel berhasil dimuat.",
        "status" => 200,
        "data" => array(
            "items" => $rows,
            "pagination" => array(
                "total_data"   => (int) $total_data,
                "total_pages"  => (int) ceil($total_data / $limit),
                "current_page" => $page,
                "per_page"     => $limit
            )
        )
    ));
}
```

---

## 3. ⚠️ CI3 Gotchas Handled (verified against `system/database/DB_query_builder.php`)

### 3.1 Empty-string `count_all_results('', false)` — avoids duplicate FROM

- `count_all_results()` (line 1401) only calls `from($table)` when `$table !== ''` (line 1403).
- Here `->from('tbl_personil p')` was **already called** in section 3, so `qb_from = ['tbl_personil p']`.
- Passing the table name again (e.g. `count_all_results('tbl_personil p', false)`) would append a second entry → `FROM tbl_personil p, tbl_personil p` → cartesian product (same bug class as the Polda build, `plan/backend_polda_pagination_build.md` §3).
- **Fix:** empty string → `from()` skipped → `qb_from` intact.

### 3.2 `get()` called WITHOUT table name

- `get()` (line 1371) also calls `from($table)` when `$table !== ''`.
- Called with no argument → reuses the preserved `qb_from` from `count_all_results('', false)`. Exactly the pattern already proven E2E-green in `polda_get()`.

### 3.3 WHERE state survives the count

- `count_all_results(..., FALSE)` skips `_reset_select()` (line 1420–1428) and restores `qb_orderby`.
- `_compile_select()` (line 2318) starts with `_merge_cache()` (line 2321), so the compiled/moved WHERE conditions are merged back for the final `get()`. The count query and the data query see identical `WHERE` + `JOIN` + `LIKE` conditions.
- JOINs in the COUNT are harmless: all 4 are LEFT JOINs, all N:1 from `tbl_personil` → `COUNT(*)` equals the true personil count.

---

## 4. Response Envelope (before → after)

**Before (flat array):**
```json
{
  "status": 200,
  "message": "Daftar personel berhasil dimuat.",
  "data": [ { "personil_id": "...", "nrp": "...", "nama_lengkap": "...", ... } ]
}
```

**After (paginated):**
```json
{
  "status": 200,
  "message": "Daftar personel berhasil dimuat.",
  "data": {
    "items": [
      {
        "personil_id": "uuid",
        "nrp": "91020304",
        "nama_lengkap": "BRIPDA Andi Pratama",
        "status_aktif": "Aktif",
        "polda_id": 1,
        "polres_id": 3,
        "pangkat_id": 4,
        "jabatan_id": 2,
        "nama_pangkat": "Brigadir Polisi Dua",
        "nama_jabatan": "Anggota",
        "nama_polres": "Polres Metro Jakarta Selatan",
        "nama_polda": "Polda Metro Jaya"
      }
    ],
    "pagination": {
      "total_data": 50,
      "total_pages": 5,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

Shape matches the project convention already shipped in `polda_get()`, `polres_get()`, `kategori_senjata_get()`, and `Auth::all()`.

---

## 5. Verification Performed

| Check | Result |
|-------|--------|
| `php -l application/controllers/Sdm.php` | ✅ No syntax errors |
| `git diff` hunk scope | ✅ All 8 hunks inside `personil_get()` (lines 117–261); no other method modified |
| CI3 `count_all_results('', false)` semantics | ✅ Verified against `system/database/DB_query_builder.php:1401-1437` |
| CI3 `_compile_select()` cache-merge | ✅ Verified at `DB_query_builder.php:2321` |
| Existing E2E tests referencing `GET /sdm/personil` | ✅ None found (`grep personil tests/` — only POST/PUT tests exist), so no existing assertion breaks |
| E2E runtime test | ⏳ Deferred — MySQL not available in this sandbox; run `npm test` (or targeted spec) in the dev environment |

### Suggested E2E scenarios (dev environment)

1. `GET /api/v1/sdm/personil` → 200, ≤10 items, `pagination.total_data` > 0
2. `GET /api/v1/sdm/personil?page=2&limit=5` → `current_page`=2, 5 items
3. `GET /api/v1/sdm/personil?search=andi` → items match `nama_lengkap`/`nrp`
4. `GET /api/v1/sdm/personil?search=ZZZNOTFOUND` → empty `items`, `total_data`=0
5. `GET /api/v1/sdm/personil?limit=9999` → `per_page`=100 (clamped)
6. `GET /api/v1/sdm/personil?page=-5` → `current_page`=1 (clamped)
7. `GET /api/v1/sdm/personil?status=Aktif` → all items `status_aktif`="Aktif"
8. `GET /api/v1/sdm/personil?polres_id=1` → all items `polres_id`=1
9. No token → 401; wrong role → 403; `status=invalid` → 400
10. **Join integrity:** every item carries non-null `nama_pangkat`, `nama_jabatan`, `nama_polda` (and `nama_polres` where assigned) — proves the 4 LEFT JOINs survived

---

## 6. Summary

| Aspect | Before | After |
|--------|--------|-------|
| **Search** | ✅ `?search=` on `nama_lengkap` + `nrp` | Unchanged (kept verbatim) |
| **Filters** | ✅ `?polres_id=`, `?status=`, `?polda_id=` | Unchanged (kept verbatim) |
| **JOINs** | ✅ 4 LEFT JOINs for display names | Unchanged (kept verbatim) |
| **Pagination** | ❌ Returns all rows | `?page=1&limit=10` with `LIMIT`/`OFFSET` |
| **Count-first** | ❌ None | `count_all_results('', false)` before ORDER/LIMIT |
| **Response** | `{data: [...]}` | `{data: {items: [...], pagination: {...}}}` |
| **Methods touched** | — | Only `personil_get()` |

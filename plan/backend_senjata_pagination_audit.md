# Weapon Inventory Pagination & Search Audit

**Endpoint:** `GET /api/v1/logistik/senjata`  
**Controller:** `application/controllers/Logistik.php` → `senjata_get()` (line 286)  
**Date:** 2025-01-20  
**Status:** MISSING pagination, MISSING paginated envelope

---

## 1. Current Logic

### 1.1 Auth & Jurisdiction (lines 288–313)

```
Line 289: $payload = get_jwt_payload($this);     // JWT extraction via helper
Line 301: $polda_id = (int) $payload['polda_id']; // 0 for Super Admin / Eksekutif
```

| Role | Behavior |
|------|----------|
| Super Admin (1) / Eksekutif (3) | `polda_id = 0` → NO jurisdiction filter → sees ALL weapons |
| Operator Polda (2) | `polda_id > 0` → `WHERE s.polda_id = $polda_id` enforced |

**Key point:** There is NO `polres_id` column on `tbl_senjata`. Jurisdiction is `polda_id` only.

### 1.2 JOINs (lines 304–308)

```sql
SELECT s.*, k.tipe_laras, k.kaliber
FROM tbl_senjata s
LEFT JOIN tbl_kategori_senjata k
  ON s.kategori_id = k.kategori_id AND k.is_active = 1
```

| Aspect | Detail |
|--------|--------|
| Join type | `LEFT JOIN` (not INNER) — weapons still appear even if their kategori was soft-deleted |
| Extra join condition | `AND k.is_active = 1` — prevents soft-deleted kategori **labels** from leaking |
| Columns fetched from join | `k.tipe_laras` (enum: `Panjang`/`Pendek`), `k.kaliber` (varchar) |
| Fallback for deleted kategori | PHP side: `isset($row['tipe_laras']) ? $row['tipe_laras'] : null` |

### 1.3 Existing Search (lines 315–319)

```php
$search = $this->input->get('search');
if ($search !== null && $search !== '') {
    $this->db->like('s.nomor_seri', $search);
}
```

- Only searches **one** column: `s.nomor_seri` (serial number)
- Uses CodeIgniter Query Builder `like()` → generates `WHERE nomor_seri LIKE '%search%'`
- Already reads the `?search=` query parameter (good — no breaking change needed)

### 1.4 Ordering (line 321)

```php
$this->db->order_by('s.created_at', 'DESC');
```

Stable, deterministic. Newest weapons first.

### 1.5 Query Execution (line 322–323)

```php
$query = $this->db->get();          // NO limit, NO offset → fetches ALL rows
$rows = $query->result_array();
```

**This is the core problem.** For 35 seeded weapons it's fine, but in production with thousands of weapons this will degrade performance.

### 1.6 Response Mapping (lines 326–342)

Each row is mapped to:

```php
[
    'senjata_id'       => string,   // UUID v4
    'nomor_seri'       => string,
    'kategori_id'      => int,      // cast
    'polda_id'         => int,      // cast
    'tahun_pengadaan'  => string,
    'status_kelayakan' => string,
    'kategori'         => [         // nested object from JOIN
        'tipe_laras' => string|null,  // Panjang / Pendek / null
        'kaliber'    => string|null,
    ],
    'foto_url'         => string|null,
    'created_at'       => string,   // datetime
]
```

### 1.7 Current Response Envelope (lines 345–350)

```json
{
    "status": 200,
    "message": "Daftar senjata termuat.",
    "data": [...]        // ← FLAT ARRAY, no pagination metadata
}
```

**Problem:** Flutter frontend expects `data.items` + `data.pagination`, not a raw array.

### 1.8 Database Schema Reference

**`tbl_senjata`** (source table):
| Column | Type | Notes |
|--------|------|-------|
| `senjata_id` | varchar(36) | PK, UUID v4 |
| `nomor_seri` | varchar(100) | **Search column** |
| `kategori_id` | int(11) | FK → tbl_kategori_senjata |
| `polda_id` | int(11) | Jurisdiction FK |
| `tahun_pengadaan` | varchar(10) | Procurement year |
| `status_kelayakan` | varchar(50) | Condition (e.g. "Layak", "Tidak Layak") |
| `foto_url` | varchar(500) | Photo path |
| `created_at` | datetime | |

**`tbl_kategori_senjata`** (joined table):
| Column | Type | Notes |
|--------|------|-------|
| `kategori_id` | int(11) PK | |
| `tipe_laras` | enum('Panjang','Pendek') | **Search column candidate** |
| `kaliber` | varchar(20) | **Search column candidate** |
| `is_active` | tinyint(1) | Soft-delete flag (1 = active) |

---

## 2. Refactor Blueprint

### 2.1 Goal

Transform `senjata_get()` from "return everything as flat array" to the established **count-first pagination pattern** with multi-column real-time search — without breaking any existing JOIN, jurisdiction filter, or response mapping.

### 2.2 Reference Implementation

The canonical pattern already exists in this codebase at:

> `application/controllers/Master.php` → `kategori_senjata_get()` (line 596)

That method has been tested and proven. We follow the exact same structure.

### 2.3 Query Parameter Contract

| Parameter | Type | Default | Range | Notes |
|-----------|------|---------|-------|-------|
| `search` | string | `""` | any | Empty/absent = no filter |
| `page` | int | `1` | ≥ 1 | Clamped to `max(1, …)` |
| `limit` | int | `10` | 1–100 | Clamped to `max(1, min(100, …))` |

### 2.4 Search Columns — Multi-column LIKE (Recommended)

The existing search only hits `s.nomor_seri`. We extend it to also match `k.kaliber` and `k.tipe_laras` using `group_start()`/`or_like()`/`group_end()` so the search is scoped inside parentheses:

```php
if ($search !== '') {
    $this->db->group_start();
    $this->db->like('s.nomor_seri', $search);
    $this->db->or_like('k.kaliber', $search);
    $this->db->or_like('k.tipe_laras', $search);
    $this->db->group_end();
}
```

This translates to SQL:

```sql
WHERE (s.nomor_seri LIKE '%search%' OR k.kaliber LIKE '%search%' OR k.tipe_laras LIKE '%search%')
```

**Why these three columns:**
- `s.nomor_seri` — primary identifier; users type serial numbers
- `k.kaliber` — users may search "9mm" or ".45" to find all weapons of that caliber
- `k.tipe_laras` — users may search "Panjang" or "Pendek" to filter by barrel type

### 2.5 Count-First Pattern — Step by Step

```
┌─────────────────────────────────────────────────┐
│ 1. Parse ?search=, ?page=, ?limit= from GET    │
├─────────────────────────────────────────────────┤
│ 2. Apply JURISDICTION filter (s.polda_id)      │
│    (unchanged — keep existing logic)            │
├─────────────────────────────────────────────────┤
│ 3. Apply SEARCH filter                          │
│    group_start() → like() / or_like() → group_end()│
├─────────────────────────────────────────────────┤
│ 4. count_all_results('tbl_senjata s', FALSE)    │
│    FALSE preserves the QB state (WHERE, JOIN,   │
│    LIKE are kept for the next get() call)        │
│    → $total_data                                │
├─────────────────────────────────────────────────┤
│ 5. Re-apply ORDER BY (s.created_at DESC)        │
├─────────────────────────────────────────────────┤
│ 6. Apply LIMIT $limit OFFSET ($page-1)*$limit   │
├─────────────────────────────────────────────────┤
│ 7. get() → result_array()                       │
│    ⚠️ Call get() WITHOUT a table name —          │
│       count_all_results() already set qb_from   │
├─────────────────────────────────────────────────┤
│ 8. Map rows (existing mapping logic preserved)  │
├─────────────────────────────────────────────────┤
│ 9. Return paginated envelope:                   │
│    data.items + data.pagination                 │
└─────────────────────────────────────────────────┘
```

### 2.6 Critical CI3 Query Builder Quirk

`count_all_results('tbl_senjata s', false)` sets `qb_from` internally. If `get('tbl_senjata s')` is called afterward, CI3 compiles:

```sql
FROM tbl_senjata s, tbl_senjata s   -- CARTESIAN PRODUCT! ❌
```

**Fix:** Call `get()` with **no arguments** (or `get(null)`) — this is what `kategori_senjata_get()` does at line 640. The JOIN is already registered before `count_all_results` and survives the count call because of the `false` reset flag.

### 2.7 New Response Envelope

```json
{
    "status": 200,
    "message": "Daftar senjata termuat.",
    "data": {
        "items": [
            {
                "senjata_id": "...",
                "nomor_seri": "...",
                "kategori_id": 1,
                "polda_id": 31,
                "tahun_pengadaan": "2023",
                "status_kelayakan": "Layak",
                "kategori": {
                    "tipe_laras": "Pendek",
                    "kaliber": "9mm"
                },
                "foto_url": "uploads/senjata/...",
                "created_at": "2024-01-15 10:30:00"
            }
        ],
        "pagination": {
            "total_data": 35,
            "total_pages": 4,
            "current_page": 1,
            "per_page": 10
        }
    }
}
```

### 2.8 What Must NOT Change

| Element | Why |
|---------|-----|
| `LEFT JOIN … AND k.is_active = 1` | Prevents weapons with deleted categories from disappearing; prevents deleted category labels from leaking |
| `WHERE s.polda_id = $polda_id` (when > 0) | Jurisdiction enforcement — Operator Polda must stay locked to their polda |
| `s.*, k.tipe_laras, k.kaliber` in SELECT | Frontend expects `kategori.tipe_laras` and `kategori.kaliber` nested object |
| Integer casting on `kategori_id`, `polda_id` | Flutter JSON parsing requires int, not string |
| `null` fallback for JOIN columns (`isset($row['tipe_laras']) ? … : null`) | LEFT JOIN can return NULL for deleted categories |
| `order_by('s.created_at', 'DESC')` | Stable ordering ensures no page overlap between requests |

### 2.9 Edge Cases Handled

| Edge Case | How Handled |
|-----------|-------------|
| `page=0` or negative | `max(1, …)` clamps to page 1 |
| `limit=0` or negative | `max(1, …)` clamps to 1 |
| `limit=1000` (excessive) | `min(100, …)` caps at 100 |
| `search=""` (empty string) | `if ($search !== '')` skips the LIKE block entirely |
| `search` not present in query string | `$this->input->get('search')` returns `null` → falls through to default `""` → no filter |
| Page beyond last page | `count_all_results` gives truthful `total_data` → `total_pages` math is correct → frontend handles empty `items` gracefully |

### 2.10 Estimated Changes

**File:** `application/controllers/Logistik.php`  
**Method:** `senjata_get()` (lines 286–351, ~65 lines)

Changes:
1. **Add** parameter parsing block (~4 lines)
2. **Replace** single-column `like('s.nomor_seri')` with `group_start()`/`group_end()` multi-column block (~8 lines)
3. **Insert** `count_all_results('tbl_senjata s', false)` after filters, before ordering (~1 line)
4. **Add** `limit($limit, ($page - 1) * $limit)` before `get()` (~1 line)
5. **Change** `get()` to `get()` (no args) — already no-args, so no change needed there
6. **Replace** flat `"data" => $mapped` with paginated envelope `"data" => ["items" => $mapped, "pagination" => […]]` (~8 lines)

**Total:** ~25 lines changed, all within one method. Zero schema changes. Zero route changes.

---

## 3. Verification Checklist

After implementation, verify:

- [ ] `GET /api/v1/logistik/senjata` returns `data.items` (array) + `data.pagination`
- [ ] `GET /api/v1/logistik/senjata?page=2&limit=5` returns correct slice
- [ ] `GET /api/v1/logistik/senjata?search=9mm` returns only matching weapons
- [ ] `GET /api/v1/logistik/senjata?search=Panjang` matches `tipe_laras`
- [ ] `GET /api/v1/logistik/senjata?search=XYZ123` matches `nomor_seri`
- [ ] Operator Polda (role_id=2) still sees only their polda's weapons
- [ ] Super Admin sees all weapons, can optionally filter with `?polda_id=`
- [ ] `total_pages` math is correct: `ceil(total_data / limit)`
- [ ] Weapons with soft-deleted kategori still appear (kategori fields are `null`)
- [ ] Integer fields (`kategori_id`, `polda_id`, pagination numbers) are actual integers in JSON
- [ ] Playwright test passes: `npx playwright test tests/api/` (or relevant test file)

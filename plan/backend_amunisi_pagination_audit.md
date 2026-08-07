# Amunisi Pagination & Real-Time Search Audit

**Endpoint:** `GET /api/v1/logistik/amunisi`  
**Controller:** `application/controllers/Logistik.php` → `amunisi_get()` (lines 622–692)  
**Date:** 2025-07-18  
**Status:** ⚠️ Needs refactor — no pagination, single-column search, flat response

---

## 1. Current Logic

### 1.1 Auth & Jurisdiction (lines 624–637)

```php
$payload = get_jwt_payload($this);  // JWT decode
$polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
```

- ❌ **No role check.** Unlike `Sdm.php` (role_id 2 locked to JWT polda, role_id 1/3 can override via `?polda_id=`), this method blindly uses the JWT `polda_id` for all roles. Super Admin cannot cross-filter by `?polda_id=`.
- ❌ **No `?polda_id=` query param override.** The `senjata_get()` sibling has the same gap — this is a consistent pattern in Logistik controller, but a divergence from Sdm/Master controllers.

### 1.2 Query Build (lines 639–658)

```php
$this->db->select('a.*, k.kaliber');
$this->db->from('tbl_amunisi_batch a');
$this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

if ($polda_id > 0) {
    $this->db->where('a.polda_id', $polda_id);
}

$search = $this->input->get('search');
if ($search !== null && $search !== '') {
    $this->db->like('a.kode_batch', $search);
}

$this->db->order_by('a.created_at', 'DESC');
$query = $this->db->get();
$rows = $query->result_array();
```

**JOINs in play (must preserve):**

| Join | Table | Alias | Condition | Type | Purpose |
|------|-------|-------|-----------|------|---------|
| 1 | `tbl_kategori_senjata` | `k` | `a.kategori_id = k.kategori_id AND k.is_active = 1` | LEFT | Fetch `kaliber` name; soft-deleted categories excluded from label but batch still appears |

**Filters in play (must preserve):**

| Filter | Column | Logic | Notes |
|--------|--------|-------|-------|
| Jurisdiction | `a.polda_id` | `=` JWT `polda_id` (if > 0) | Applied unconditionally for all roles |
| Search | `a.kode_batch` | `LIKE '%search%'` | Single-column only |

**What's missing — pagination:**
- No `?page=` or `?limit=` query params parsed
- No `COUNT(*)` query for total rows
- No `LIMIT` / `OFFSET` applied
- Returns **all** rows every call — will break at scale

### 1.3 H-90 Alert Engine & Data Mapping (lines 661–683)

```php
$today = time();
$mapped = array();
foreach ($rows as $row) {
    $expiry = strtotime($row['tanggal_kedaluwarsa']);
    $hari_tersisa = (int) floor(($expiry - $today) / 86400);

    $mapped[] = array(
        'batch_id'            => (int) $row['batch_id'],
        'polda_id'            => (int) $row['polda_id'],
        'kode_batch'          => $row['kode_batch'],
        'kategori'            => array(
            'kaliber' => isset($row['kaliber']) ? $row['kaliber'] : null
        ),
        'jumlah_butir'        => (int) $row['jumlah_butir'],
        'tanggal_masuk'       => $row['tanggal_masuk'],
        'tanggal_kedaluwarsa' => $row['tanggal_kedaluwarsa'],
        'is_h90_alert'        => ($hari_tersisa <= 90) ? true : false,
        'hari_tersisa'        => $hari_tersisa,
        'created_at'          => $row['created_at'],
        'updated_at'          => $row['updated_at']
    );
}
```

- ✅ `hari_tersisa` and `is_h90_alert` computed row-by-row in PHP (same post-query loop pattern as current code — works fine with pagination since it's per-row math)
- ✅ All ID columns cast to `(int)` for Flutter compatibility
- ✅ `kaliber` wrapped in nested `kategori` object for Flutter model consistency
- ✅ `updated_at` can be `NULL` if never updated — handled gracefully

### 1.4 Response Envelope (lines 685–691)

```php
echo json_encode(array(
    "status" => 200,
    "message" => "Daftar amunisi termuat.",
    "data" => $mapped   // ← flat array, no pagination wrapper
));
```

- ❌ **Flat array.** The `senjata_get()` sibling returns `{ items: [...], pagination: { total_data, total_pages, current_page, per_page } }`. This must be upgraded to match.

### 1.5 Database Schema (from `Seeder.php` line 174)

```sql
CREATE TABLE `tbl_amunisi_batch` (
    `batch_id`            int(11) NOT NULL AUTO_INCREMENT,
    `polda_id`            int(11) DEFAULT NULL,
    `kode_batch`          varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `kategori_id`         int(11) DEFAULT NULL,
    `jumlah_butir`        int(11) DEFAULT 0,
    `tanggal_masuk`       date DEFAULT NULL,
    `tanggal_kedaluwarsa` date DEFAULT NULL,
    `created_at`          datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at`          datetime DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`batch_id`)
);

CREATE TABLE `tbl_kategori_senjata` (
    `kategori_id` int(11) NOT NULL AUTO_INCREMENT,
    `tipe_laras`  enum('Panjang','Pendek') NOT NULL,
    `kaliber`     varchar(20) NOT NULL,
    `is_active`   tinyint(1) NOT NULL DEFAULT 1,
    `updated_at`  datetime DEFAULT NULL,
    PRIMARY KEY (`kategori_id`)
);
```

### 1.6 Route Configuration

```php
// application/config/routes.php lines 91-96
$route['api/v1/logistik/amunisi']['GET']     = 'logistik/amunisi_get';
$route['api/v1/logistik/amunisi']['POST']    = 'logistik/amunisi_post';
$route['api/v1/logistik/amunisi']['OPTIONS'] = 'logistik/amunisi_options';
$route['api/v1/logistik/amunisi/(:any)']['PUT']    = 'logistik/amunisi_put/$1';
$route['api/v1/logistik/amunisi/(:any)']['DELETE'] = 'logistik/amunisi_delete/$1';
```

---

## 2. Refactor Blueprint

### 2.1 Target: Match `senjata_get()` Pagination Pattern

The `senjata_get()` method (lines 288–381) is the **canonical paginated GET** in this controller. The amunisi refactor will mirror it exactly, adapted for the amunisi-specific columns and the H-90 alert computation.

### 2.2 Step-by-Step Injection Plan

#### Step A: Parse Query Params (add after jurisdiction block, before query)

```php
$search = $this->input->get('search');
$page   = max(1, (int) ($this->input->get('page') ?? 1));
$limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));
```

- `page` defaults to 1, clamped to ≥1
- `limit` defaults to 10, clamped to 1..100
- Matches `senjata_get()` line 307–309 exactly

#### Step B: Upgrade Search to Multi-Column (replace single `like`)

**Current (single-column):**
```php
if ($search !== null && $search !== '') {
    $this->db->like('a.kode_batch', $search);
}
```

**New (multi-column with group_start/group_end):**
```php
if ($search !== null && $search !== '') {
    $this->db->group_start();
    $this->db->like('a.kode_batch', $search);
    $this->db->or_like('k.kaliber', $search);
    $this->db->group_end();
}
```

- `kode_batch` — the batch code (e.g., "AMO-2025-001")
- `kaliber` — from the JOINed `tbl_kategori_senjata` (e.g., "9mm", "5.56mm")
- `group_start/group_end` keeps the OR inside parentheses so it never bypasses the jurisdiction (`polda_id`) filter
- Same pattern as `senjata_get()` lines 326–332

#### Step C: Inject Count-First Query (before ORDER BY)

```php
// COUNT-FIRST: total rows matching the current filters
// count_all_results('', false) with EMPTY string keeps the qb_from state
// ('tbl_amunisi_batch a') set by ->from() above. FALSE preserves all
// WHERE/LIKE/JOIN state for the get() below.
$total_data = $this->db->count_all_results('', false);
```

- Must be placed **after** all WHERE/JOIN clauses and **before** `order_by` + `limit`
- Uses the same `count_all_results('', false)` trick as `senjata_get()` line 339
- The empty string avoids duplicating the FROM table; `false` preserves query builder state

#### Step D: Add ORDER BY + Pagination (after count)

```php
$this->db->order_by('a.created_at', 'DESC');
$this->db->limit($limit, ($page - 1) * $limit);
$query = $this->db->get();
$rows = $query->result_array();
```

- Same as `senjata_get()` lines 342–345
- `($page - 1) * $limit` converts 1-based page to 0-based offset

#### Step E: Keep H-90 Alert Engine + Mapping (untouched)

The existing post-query loop (lines 661–683) stays exactly as-is — `hari_tersisa` and `is_h90_alert` are computed per-row and work correctly on a paginated subset.

#### Step F: Upgrade Response to Paginated Envelope (replace final echo)

```php
$this->output->set_content_type('application/json')->set_status_header(200);
echo json_encode(array(
    "status" => 200,
    "message" => "Daftar amunisi termuat.",
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

- Matches `senjata_get()` lines 367–380 structure
- Casts `total_data` and `total_pages` to `(int)` for Flutter

### 2.3 What Must NOT Change

| Element | Reason |
|---------|--------|
| `LEFT JOIN tbl_kategori_senjata k ON a.kategori_id = k.kategori_id AND k.is_active = 1` | Preserves soft-delete safety — batches with deleted categories still appear, just without a label |
| `WHERE a.polda_id = $polda_id` (when > 0) | Jurisdiction enforcement — MUST remain before search group |
| H-90 alert computation (`hari_tersisa`, `is_h90_alert`) | Business logic — computed per-row in PHP loop, independent of pagination |
| `kategori.kaliber` nested object shape | Flutter model compatibility — `kategori` is an object, not a flat field |
| `batch_id`, `polda_id`, `jumlah_butir` cast to `(int)` | Flutter JSON parsing — IDs must be integers not strings |
| `ORDER BY a.created_at DESC` | Default sort order — newest batches first |

### 2.4 Out-of-Scope (Deferred to Future PR)

- **Role-based `?polda_id=` override:** The current code (and `senjata_get()`) doesn't support Super Admin cross-filtering. Adding this would require a role check block similar to `Sdm.php` lines 48–68. Not in scope for this pagination refactor — doing it now would also require updating `senjata_get()` for consistency.
- **`?sort_by=` / `?order=` params:** Not required by PRD. Keep `created_at DESC` as the hardcoded default.

### 2.5 Files Changed

| File | Change |
|------|--------|
| `application/controllers/Logistik.php` | Refactor `amunisi_get()` method only (lines 622–692) — no other method touched |

### 2.6 Testing Considerations

- No existing Playwright E2E tests for amunisi GET (confirmed: `grep -r amunisi tests/` returned zero results)
- Manual verification checklist:
  1. `GET /api/v1/logistik/amunisi` → returns paginated envelope with `items` + `pagination`
  2. `?page=1&limit=5` → returns 5 items, `total_pages` computed correctly
  3. `?search=AMO` → filters by `kode_batch` OR `kaliber` containing "AMO"
  4. `?search=9mm` → finds batches whose kaliber is "9mm"
  5. `?page=99999` → returns empty `items`, correct `total_data`/`total_pages`
  6. H-90 alert fields (`is_h90_alert`, `hari_tersisa`) still computed correctly
  7. Jurisdiction: Operator Polda only sees own polda's batches

---

## 3. Quick Reference: Diff Summary

The refactor touches exactly **one method** (`amunisi_get`) and follows the **established `senjata_get()` pattern** already proven in this controller. The count-first approach with `count_all_results('', false)` is the project convention (also used in `personil_get` in `Sdm.php`).

**Risk:** Low. The only behavior change for existing consumers is the response shape — `data` changes from a flat array to `{ items: [...], pagination: {...} }`. Flutter clients must be updated to unwrap `data.items` and render pagination controls.

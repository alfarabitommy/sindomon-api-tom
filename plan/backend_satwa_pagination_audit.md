# Satwa K9 & Turangga — Pagination & Search Audit

**Endpoint:** `GET /api/v1/logistik/satwa`  
**Controller:** `application/controllers/Logistik.php` → `satwa_get()` (lines 1075–1137)  
**Audit Date:** 2025-07-15  
**Reference Implementations:** `amunisi_get()` (lines 625–733), `sarpras_get()` (lines 1334–1432), `senjata_get()` (lines 288–381)

---

## 1. Current Logic

### 1.1 Auth & Jurisdiction (BROKEN — legacy pattern)

```php
// satwa_get(), lines 1077–1090
$payload = get_jwt_payload($this);
$polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
```

**Problem:** `role_id` is never read. The method uses the **old pre-fix pattern** that was already corrected in `amunisi_get`, `sarpras_get`, and `senjata_get`. The fixed endpoints do:

```php
// Fixed pattern (from amunisi_get lines 639–652)
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

**Impact of the bug in `satwa_get`:**
| Role | JWT `polda_id` | Behavior | Correct? |
|------|---------------|----------|----------|
| Operator Polda (role_id=2) | e.g. 5 | Filtered to polda_id=5 | ✅ Works |
| Super Admin (role_id=1) | 0 or absent | No filter → sees all | ⚠️ Sees all (ok), but **cannot** use `?polda_id=` to filter |
| Eksekutif (role_id=3) | 0 or absent | No filter → sees all | ⚠️ Sees all (ok), but **cannot** use `?polda_id=` to filter |

The `?polda_id=` query-param override for Super Admin / Eksekutif is entirely missing.

### 1.2 Query Structure — No JOINs

```php
// satwa_get(), lines 1093–1110
$this->db->from('tbl_satwa');

if ($polda_id > 0) {
    $this->db->where('polda_id', $polda_id);
}

$search = $this->input->get('search');
if ($search !== null && $search !== '') {
    $this->db->group_start();
    $this->db->like('nomor_registrasi', $search);
    $this->db->or_like('nama_satwa', $search);
    $this->db->group_end();
}

$this->db->order_by('created_at', 'DESC');
$query = $this->db->get();
$rows = $query->result_array();
```

- **No JOINs at all.** `tbl_satwa` is a standalone table.
- `nama_handler` is stored as a **plain VARCHAR column** directly in `tbl_satwa` (see seeder schema at Seeder.php:221). It is **NOT** a foreign key to `tbl_personil` — no JOIN needed.
- **Search columns:** `nomor_registrasi` OR `nama_satwa` (2 columns). `jenis_satwa` (K9/Turangga) is **not** searchable, which is a useful addition.

### 1.3 Pagination — COMPLETELY ABSENT

```php
// There is NO:
//   - $page / $limit parsing
//   - count_all_results() call
//   - $this->db->limit() / offset()
```

The method fetches **all rows** and returns them as a flat array. With 25+ satwa records this is manageable today, but will not scale.

### 1.4 Response Envelope — Flat Array (Not Paginated)

```php
// satwa_get(), lines 1130–1136
echo json_encode(array(
    "status" => 200,
    "message" => "Daftar satwa termuat.",
    "data" => $mapped    // ← FLAT ARRAY: [{...}, {...}, ...]
));
```

**Expected paginated envelope** (per `amunisi_get` / `sarpras_get`):

```json
{
  "status": 200,
  "message": "Daftar satwa termuat.",
  "data": {
    "items": [{...}, {...}],
    "pagination": {
      "total_data": 25,
      "total_pages": 3,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

### 1.5 Field Mapping — Complete but no `jenis_satwa` in search

Current mapped fields (lines 1116–1127):

| Field | Type | Notes |
|-------|------|-------|
| `satwa_id` | VARCHAR(36) | UUID |
| `polda_id` | INT | Type-cast ✅ |
| `nomor_registrasi` | VARCHAR(100) | UNIQUE |
| `jenis_satwa` | VARCHAR(50) | "K9" or "Turangga" — NOT in search |
| `nama_satwa` | VARCHAR(255) | In search ✅ |
| `nama_handler` | VARCHAR(255) | Plain text, no FK |
| `kualifikasi` | VARCHAR(100) | |
| `jadwal_vaksin` | DATE | |
| `foto_url` | VARCHAR(500) | |
| `created_at` | DATETIME | |

No `updated_at` column exists in `tbl_satwa` schema.

---

## 2. Refactor Blueprint

### 2.1 Three Changes to `satwa_get()`

All changes follow the **exact same "count-first" pattern** already proven in `amunisi_get` (3 commits, zero regressions) and `sarpras_get`.

#### Change A: Role-Based Jurisdiction (Bug Fix)

Replace:

```php
// OLD (line 1090)
$polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
```

With the fixed 3-role pattern from `amunisi_get` lines 639–652:

```php
// NEW
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

**Why this matters:** Super Admin must be able to scope the satwa list to a specific Polda via `?polda_id=`. Without this, the Flutter frontend's Polda filter dropdown is dead for satwa.

#### Change B: Add `?search=`, `?page=`, `?limit=` Query Params

Insert before the query builder block:

```php
// ── 3. QUERY PARAMS (pagination & real-time search) ──
$search = $this->input->get('search');
$page   = max(1, (int) ($this->input->get('page') ?? 1));
$limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));
```

**Search columns:** Expand from 2 to 3 — add `jenis_satwa`:

```php
if ($search !== null && $search !== '') {
    $this->db->group_start();
    $this->db->like('nomor_registrasi', $search);
    $this->db->or_like('nama_satwa', $search);
    $this->db->or_like('jenis_satwa', $search);   // ← NEW: K9 / Turangga
    $this->db->group_end();
}
```

Rationale: An operator searching "K9" should find all K9 satwa, not just those with "K9" in the registration number.

#### Change C: Count-First Pagination + Paginated Envelope

Replace the tail of the method (from `$this->db->order_by(...)` onward) with:

```php
// ── 5. COUNT-FIRST: total rows matching the current filters ──
$total_data = $this->db->count_all_results('', false);

// ── 6. ORDER & PAGINATION ──
$this->db->order_by('created_at', 'DESC');
$this->db->limit($limit, ($page - 1) * $limit);
$query = $this->db->get();
$rows = $query->result_array();

// ── 7. INTEGER CASTING & MAP ──
$mapped = array();
foreach ($rows as $row) {
    $mapped[] = array(
        'satwa_id'         => $row['satwa_id'],
        'polda_id'         => (int) $row['polda_id'],
        'nomor_registrasi' => $row['nomor_registrasi'],
        'jenis_satwa'      => $row['jenis_satwa'],
        'nama_satwa'       => $row['nama_satwa'],
        'nama_handler'     => $row['nama_handler'],
        'kualifikasi'      => $row['kualifikasi'],
        'jadwal_vaksin'    => $row['jadwal_vaksin'],
        'foto_url'         => $row['foto_url'],
        'created_at'       => $row['created_at'],
    );
}

// ── 8. SUCCESS RESPONSE (paginated envelope) ──
$this->output->set_content_type('application/json')->set_status_header(200);
echo json_encode(array(
    "status" => 200,
    "message" => "Daftar satwa termuat.",
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

### 2.2 What Does NOT Need to Change

| Item | Status | Reason |
|------|--------|--------|
| `satwa_post()` | ✅ No changes | Already correct; uses `$polda_id` from JWT for INSERT/UPDATE |
| `satwa_delete()` | ✅ No changes | Already correct; jurisdiction check via `polda_id` in WHERE |
| `satwa_options()` | ✅ No changes | CORS preflight — stateless |
| JOINs | ✅ No JOINs needed | `nama_handler` is a plain VARCHAR in `tbl_satwa`, not a FK to `tbl_personil` |
| `tbl_satwa` schema | ✅ No changes | All needed columns exist |
| Database/migration | ✅ No changes | No schema change needed |

### 2.3 Test Impact

No existing Playwright tests for `satwa_get` (confirmed: zero matches in `tests/`). The refactor is **net-new test territory**. After the refactor:

- **Existing tests:** Zero risk — no test touches `satwa_get` response shape.
- **New tests needed:** A `tests/api/logistik_satwa.spec.ts` should cover:
  1. Paginated response shape (`items` + `pagination` keys)
  2. `?page=` / `?limit=` clamping
  3. `?search=` on `nomor_registrasi`, `nama_satwa`, `jenis_satwa`
  4. `?polda_id=` override for Super Admin
  5. Operator Polda locked to own `polda_id` (cannot override)

### 2.4 Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| Response shape change breaks Flutter | **MEDIUM** | Flutter must update from `List<Satwa>` to `PaginatedResponse<Satwa>` — same pattern already used for amunisi/sarpras/senjata, so Flutter team knows the drill |
| `count_all_results('', false)` edge case | **LOW** | Pattern is battle-tested in 3 other `_get` methods |
| `jenis_satwa` added to search | **LOW** | Additive only — no existing behavior removed |
| Role jurisdiction change | **LOW** | Pattern is identical to `amunisi_get` and `sarpras_get` which are already in production |

---

## 3. Summary

`satwa_get()` is the **last remaining `_get` method in Logistik.php** that uses the legacy (pre-fix) pattern. It is missing:

1. **Role-based `?polda_id=` override** for Super Admin / Eksekutif
2. **Pagination** (`page`/`limit` params, `count_all_results`, `limit()`/`offset()`)
3. **Paginated response envelope** (`items` + `pagination` instead of flat array)
4. **`jenis_satwa` in search** (nice-to-have addition)

The fix is a straightforward 3-part copy-paste from `amunisi_get` / `sarpras_get` with column names adjusted. No JOIN changes, no schema changes, no migration needed.

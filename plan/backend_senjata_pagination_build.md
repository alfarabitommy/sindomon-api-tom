# Weapon Inventory — Pagination & Search Build Report

**Endpoint:** `GET /api/v1/logistik/senjata`  
**File:** `application/controllers/Logistik.php` → `senjata_get()` (now lines 288–381)  
**Status:** ✅ IMPLEMENTED — PHP lint passes, only `senjata_get()` touched

---

## 1. What Changed

| Area | Before | After |
|------|--------|-------|
| Query params | `?search=` only (nomor_seri) | `?search=` (3 columns), `?page=` (1-based), `?limit=` (1..100, default 10) |
| Search | `like('s.nomor_seri', $search)` | `group_start()` → `like(s.nomor_seri)` + `or_like(k.kaliber)` + `or_like(k.tipe_laras)` → `group_end()` |
| Pagination | ❌ none — fetched ALL rows | `count_all_results('', false)` → `order_by` → `limit($limit, ($page-1)*$limit)` → `get()` |
| Response envelope | `data` = flat array | `data.items` + `data.pagination` (matches `personil_get`) |
| `total_pages` | n/a | `ceil($total_data / $limit)` |

## 2. Safety — What Was Preserved (verified by diff)

- ✅ JWT auth block (401 envelope untouched)
- ✅ Jurisdiction filter: `if ($polda_id > 0) { $this->db->where('s.polda_id', $polda_id); }`
- ✅ `LEFT JOIN tbl_kategori_senjata k ON s.kategori_id = k.kategori_id AND k.is_active = 1`
- ✅ `SELECT s.*, k.tipe_laras, k.kaliber`
- ✅ `ORDER BY s.created_at DESC`
- ✅ Row mapping loop (integer casts on `kategori_id`/`polda_id`, nested `kategori` object, `null` fallbacks for soft-deleted kategori)
- ✅ No other methods in `Logistik.php` were modified (git diff shows 1 file, 1 method)

## 3. The Exact Rewritten Method

```php
/**
 * GET /api/v1/logistik/senjata
 *
 * Inventarisasi senjata api — joined with kategori for readable labels.
 * Auth: JWT (polda_id for jurisdiction).
 * Query params: ?search= (nomor_seri OR kaliber OR tipe_laras),
 *               ?page= (1-based, default 1), ?limit= (1..100, default 10).
 */
public function senjata_get()
{
    // ── 1. AUTH: JWT ──
    $payload = get_jwt_payload($this);
    if (!$payload) {
        $this->output->set_status_header(401);
        echo json_encode(array(
            "message" => "Token tidak ditemukan",
            "status" => 401,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 2. JURISDICTION ──
    $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

    // ── 3. QUERY PARAMS (pagination & real-time search) ──
    // ?page= is 1-based; ?limit= is clamped to 1..100 like personil_get.
    $search = $this->input->get('search');
    $page   = max(1, (int) ($this->input->get('page') ?? 1));
    $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

    // ── 4. BUILD QUERY ──
    $this->db->select('s.*, k.tipe_laras, k.kaliber');
    $this->db->from('tbl_senjata s');
    // LEFT JOIN so senjata still appear even if kategori was soft-deleted,
    // but the deleted kategori labels must not leak into the response.
    $this->db->join('tbl_kategori_senjata k', 's.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

    // Jurisdiction filter
    if ($polda_id > 0) {
        $this->db->where('s.polda_id', $polda_id);
    }

    // Search filter — nomor_seri OR kaliber OR tipe_laras.
    // group_start/group_end keep the OR inside parentheses so the search
    // never bypasses the jurisdiction (polda_id) filter above.
    if ($search !== null && $search !== '') {
        $this->db->group_start();
        $this->db->like('s.nomor_seri', $search);
        $this->db->or_like('k.kaliber', $search);
        $this->db->or_like('k.tipe_laras', $search);
        $this->db->group_end();
    }

    // ── 5. COUNT-FIRST: total rows matching the current filters ──
    // NOTE: count_all_results('', false) with an EMPTY string keeps the
    // qb_from state ('tbl_senjata s') set by ->from() above. Passing the
    // table name again would duplicate FROM -> cartesian product. FALSE
    // preserves all WHERE/LIKE/JOIN state for the get() below.
    $total_data = $this->db->count_all_results('', false);

    // ── 6. ORDER & PAGINATION ──
    $this->db->order_by('s.created_at', 'DESC');
    $this->db->limit($limit, ($page - 1) * $limit);
    $query = $this->db->get(); // NO table name — qb_from is already set
    $rows = $query->result_array();

    // ── 7. INTEGER CASTING & MAP ──
    $mapped = array();
    foreach ($rows as $row) {
        $mapped[] = array(
            'senjata_id'       => $row['senjata_id'],
            'nomor_seri'       => $row['nomor_seri'],
            'kategori_id'      => (int) $row['kategori_id'],
            'polda_id'         => (int) $row['polda_id'],
            'tahun_pengadaan'  => $row['tahun_pengadaan'],
            'status_kelayakan' => $row['status_kelayakan'],
            'kategori'         => array(
                'tipe_laras' => isset($row['tipe_laras']) ? $row['tipe_laras'] : null,
                'kaliber'    => isset($row['kaliber']) ? $row['kaliber'] : null,
            ),
            'foto_url'         => $row['foto_url'],
            'created_at'       => $row['created_at'],
        );
    }

    // ── 8. SUCCESS RESPONSE (paginated envelope) ──
    $this->output->set_content_type('application/json')->set_status_header(200);
    echo json_encode(array(
        "status" => 200,
        "message" => "Daftar senjata termuat.",
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
}
```

## 4. Resulting SQL (example: Operator Polda, `?search=9mm&page=2&limit=5`)

```sql
-- COUNT (count_all_results('', false))
SELECT COUNT(*) AS numrows
FROM tbl_senjata s
LEFT JOIN tbl_kategori_senjata k
  ON s.kategori_id = k.kategori_id AND k.is_active = 1
WHERE s.polda_id = 31
  AND (s.nomor_seri LIKE '%9mm%' OR k.kaliber LIKE '%9mm%' OR k.tipe_laras LIKE '%9mm%');

-- DATA (get())
SELECT s.*, k.tipe_laras, k.kaliber
FROM tbl_senjata s
LEFT JOIN tbl_kategori_senjata k
  ON s.kategori_id = k.kategori_id AND k.is_active = 1
WHERE s.polda_id = 31
  AND (s.nomor_seri LIKE '%9mm%' OR k.kaliber LIKE '%9mm%' OR k.tipe_laras LIKE '%9mm%')
ORDER BY s.created_at DESC
LIMIT 5 OFFSET 5;
```

## 5. New Response Envelope

```json
{
    "status": 200,
    "message": "Daftar senjata termuat.",
    "data": {
        "items": [
            {
                "senjata_id": "uuid...",
                "nomor_seri": "SEN-001",
                "kategori_id": 1,
                "polda_id": 31,
                "tahun_pengadaan": "2023",
                "status_kelayakan": "Layak",
                "kategori": { "tipe_laras": "Pendek", "kaliber": "9mm" },
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

## 6. Design Decision — Why `count_all_results('', false)` (not `'tbl_senjata s'`)

The task brief suggested `count_all_results('tbl_senjata s', false)`, but CI3's source
(`system/database/DB_query_builder.php:1401`) shows:

```php
if ($table !== '')
{
    $this->_track_aliases($table);
    $this->from($table);   // ← appends ANOTHER FROM clause
}
```

Since `senjata_get()` already calls `$this->db->from('tbl_senjata s')` (step 4), passing the
table name again would compile `FROM tbl_senjata s, tbl_senjata s` → **cartesian product**.

The empty-string form `count_all_results('', false)` keeps the existing `qb_from` and is
exactly the pattern already proven in `personil_get()` (`Sdm.php:229`) — the reference the
task told us to match.

## 7. Verification Performed

- [x] `php -l application/controllers/Logistik.php` → **No syntax errors detected**
- [x] `git diff --stat` → only `Logistik.php` changed (plus pre-existing `.reasonix/` metadata)
- [x] `git diff` → no other method in `Logistik.php` touched
- [x] Existing tests (`tests/api/*.spec.ts`) have **no coverage** of `logistik/senjata` — no test updates required; adding coverage is a recommended follow-up

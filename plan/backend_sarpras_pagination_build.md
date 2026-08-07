# Sarpras & Almatsus — Pagination & Real-Time Search Build Report

**Date:** 2026-08-07  
**Endpoint:** `GET /api/v1/logistik/sarpras`  
**File:** `application/controllers/Logistik.php` → `sarpras_get()` (line 1334)  
**Status:** ✅ IMPLEMENTED — syntax verified with `php -l` (no errors)

---

## 1. Changes Applied

| # | Change | Detail |
|---|--------|--------|
| 1 | **Jurisdiction fix (role-gated `polda_id`)** | Operator Polda (role_id=2) locked to JWT `polda_id`; Super Admin (role_id=1) / Eksekutif (role_id=3) may override with `?polda_id=` query param. This fixes the same bug previously fixed in `amunisi_get()`. |
| 2 | **Pagination** | `?page=` (1-based, min 1) + `?limit=` (1..100, default 10), count-first via `count_all_results('', false)`, `limit($limit, ($page - 1) * $limit)`. |
| 3 | **Real-time search** | Multi-column `LIKE` on `s.nama_barang` OR `s.kode_barang`, wrapped in `group_start()`/`group_end()` so the OR can never bypass the jurisdiction filter. |
| 4 | **Table alias** | `from('tbl_sarpras s')` — alias `s` for consistency with `senjata_get()` / `amunisi_get()`. All column refs prefixed `s.`. |
| 5 | **Paginated envelope** | Response restructured from flat `data` array → `data.items` + `data.pagination`. |

**CRITICAL SAFETY:** No other methods in `Logistik.php` were modified (verified: `git diff` shows hunks only inside `sarpras_get()`, lines 1326–1432).

---

## 2. Exact Rewritten Method (as committed to source)

```php
    /**
     * GET /api/v1/logistik/sarpras
     *
     * Inventarisasi Sarpras & Altmatsus.
     * Auth: JWT (role-based jurisdiction: Operator locked to polda_id,
     *        Super Admin/Eksekutif may ?polda_id= override),
     * ?search= filters nama_barang OR kode_barang,
     * ?page= (1-based) + ?limit= (1..100, default 10) pagination.
     */
    public function sarpras_get()
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

        // ── 2. ROLE & JURISDICTION ──
        // Operator Polda (role_id=2) is locked to the JWT polda_id.
        // Super Admin (role_id=1) / Eksekutif (role_id=3) may optionally
        // override with ?polda_id= to inspect another jurisdiction.
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

        // ── 3. QUERY PARAMS (pagination & real-time search) ──
        // ?page= is 1-based; ?limit= is clamped to 1..100 like senjata_get.
        $search = $this->input->get('search');
        $page   = max(1, (int) ($this->input->get('page') ?? 1));
        $limit  = max(1, min(100, (int) ($this->input->get('limit') ?? 10)));

        // ── 4. BUILD QUERY ──
        $this->db->from('tbl_sarpras s');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }

        // Search filter — nama_barang OR kode_barang.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('s.nama_barang', $search);
            $this->db->or_like('s.kode_barang', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_sarpras s') set by ->from() above. Passing the
        // table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE state for the get() below.
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
                'sarpras_id'      => $row['sarpras_id'],
                'polda_id'        => (int) $row['polda_id'],
                'kode_barang'     => $row['kode_barang'],
                'nama_barang'     => $row['nama_barang'],
                'kategori'        => $row['kategori'],
                'kondisi'         => $row['kondisi'],
                'tahun_pengadaan' => $row['tahun_pengadaan'],
                'foto_url'        => $row['foto_url'],
                'created_at'      => $row['created_at'],
                'updated_at'      => $row['updated_at'],
            );
        }

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
    }
```

---

## 3. Resulting SQL Shapes

### No filters (default page 1, limit 10)
```sql
SELECT *
FROM tbl_sarpras s
ORDER BY s.created_at DESC
LIMIT 10 OFFSET 0;
```

### Operator Polda with search
```sql
SELECT *
FROM tbl_sarpras s
WHERE s.polda_id = 5
  AND (s.nama_barang LIKE '%HT%' OR s.kode_barang LIKE '%HT%')
ORDER BY s.created_at DESC
LIMIT 10 OFFSET 0;
```

### Super Admin cross-jurisdiction with pagination
```sql
-- ?polda_id=3&page=2&limit=25
SELECT *
FROM tbl_sarpras s
WHERE s.polda_id = 3
ORDER BY s.created_at DESC
LIMIT 25 OFFSET 25;
```

### Count query (executed first, same WHERE/LIKE state)
```sql
SELECT COUNT(*) AS numrows
FROM tbl_sarpras s
WHERE s.polda_id = 5
  AND (s.nama_barang LIKE '%HT%' OR s.kode_barang LIKE '%HT%');
```

---

## 4. Response Contract (new)

```json
{
  "status": 200,
  "message": "Daftar sarpras termuat.",
  "data": {
    "items": [
      {
        "sarpras_id": "uuid-...",
        "polda_id": 5,
        "kode_barang": "SRP-001",
        "nama_barang": "Helm Baja",
        "kategori": "Almatsus",
        "kondisi": "Baik",
        "tahun_pengadaan": "2020",
        "foto_url": "uploads/sarpras/xxx.jpg",
        "created_at": "2026-08-01 10:00:00",
        "updated_at": null
      }
    ],
    "pagination": {
      "total_data": 30,
      "total_pages": 3,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

**⚠ Breaking change for Flutter:** `data` changed from a flat array to `{items, pagination}`. Frontend must read `response.data.items` and use `response.data.pagination` for controls.

---

## 5. Verification

| Check | Result |
|-------|--------|
| `php -l application/controllers/Logistik.php` | ✅ No syntax errors |
| `git diff` — only `sarpras_get()` hunks | ✅ No other methods modified |
| `count_all_results('', false)` pattern | ✅ Mirrors proven `senjata_get()` (line 339) / `amunisi_get()` (line 687) |
| Search OR wrapped in `group_start/group_end` | ✅ Cannot bypass `polda_id` jurisdiction filter |
| `limit` clamped 1..100, `page` min 1 | ✅ Matches codebase convention |

---

## 6. Recommended Manual Test (after DB seeded)

```bash
# Start server
php -S localhost:8080 tests/router.php

# Default page
curl "http://localhost:8080/api/v1/logistik/sarpras" -H "Authorization: Bearer <token>"

# Search + pagination
curl "http://localhost:8080/api/v1/logistik/sarpras?search=HT&page=1&limit=5" -H "Authorization: Bearer <token>"

# Super Admin cross-jurisdiction
curl "http://localhost:8080/api/v1/logistik/sarpras?polda_id=3" -H "Authorization: Bearer <super_admin_token>"
```

---

## 7. Out-of-Scope Follow-up (tracked separately)

- `senjata_get()` (line 303) still has the old blind `polda_id` pattern — same jurisdiction bug this refactor fixed for sarpras. Should be patched the same way in a future task.

# Satwa K9 & Turangga — Pagination & Search Build Report

**Endpoint:** `GET /api/v1/logistik/satwa`  
**File:** `application/controllers/Logistik.php` → `satwa_get()` (now lines 1077–1175)  
**Date:** 2025-07-15  
**Status:** ✅ Implemented, PHP lint clean (`php -l` → "No syntax errors detected")

---

## 1. Changes Applied (4 in one method, zero outside)

| # | Change | Type |
|---|--------|------|
| 1 | Role-gated jurisdiction: Super Admin/Eksekutif get `?polda_id=` override; Operator Polda locked to JWT `polda_id` | 🔴 Bug fix |
| 2 | `?page=` (1-based, min 1) + `?limit=` (clamped 1..100, default 10) query params | ➕ New |
| 3 | Multi-column search extended to `nomor_registrasi` OR `nama_satwa` OR **`jenis_satwa`** | ➕ New |
| 4 | Count-first pagination + nested `items`/`pagination` response envelope | ➕ New |

**Safety check:** `git diff` confirms all 3 hunks are inside `satwa_get()` (lines ~1069–1175). No other method in `Logistik.php` was modified. No function signatures changed.

---

## 2. Exact Rewritten Method

```php
    /**
     * GET /api/v1/logistik/satwa
     *
     * Inventarisasi aset satwa (K9 & Turangga).
     * Auth: JWT (role-based jurisdiction: Operator locked to polda_id,
     *        Super Admin/Eksekutif may ?polda_id= override),
     * ?search= filters nomor_registrasi OR nama_satwa OR jenis_satwa,
     * ?page= (1-based) + ?limit= (1..100, default 10) pagination.
     */
    public function satwa_get()
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
        $this->db->from('tbl_satwa');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('polda_id', $polda_id);
        }

        // Search filter — nomor_registrasi OR nama_satwa OR jenis_satwa.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('nomor_registrasi', $search);
            $this->db->or_like('nama_satwa', $search);
            $this->db->or_like('jenis_satwa', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_satwa') set by ->from() above. Passing the
        // table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE state for the get() below.
        $total_data = $this->db->count_all_results('', false);

        // ── 6. ORDER & PAGINATION ──
        $this->db->order_by('created_at', 'DESC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $rows = $this->db->get()->result_array(); // NO table name — qb_from is already set

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
    }
```

---

## 3. Response Shape (Before → After)

**Before (flat array):**
```json
{
  "status": 200,
  "message": "Daftar satwa termuat.",
  "data": [{ "satwa_id": "...", "polda_id": 5, ... }]
}
```

**After (paginated envelope — matches `senjata_get`, `amunisi_get`, `sarpras_get`):**
```json
{
  "status": 200,
  "message": "Daftar satwa termuat.",
  "data": {
    "items": [{ "satwa_id": "...", "polda_id": 5, ... }],
    "pagination": {
      "total_data": 25,
      "total_pages": 3,
      "current_page": 1,
      "per_page": 10
    }
  }
}
```

---

## 4. Query Parameters Supported

| Param | Type | Default | Rules |
|-------|------|---------|-------|
| `search` | string | — | Multi-column `LIKE` on `nomor_registrasi`, `nama_satwa`, `jenis_satwa` (OR-grouped) |
| `page` | int | 1 | `max(1, ...)` — 1-based |
| `limit` | int | 10 | `max(1, min(100, ...))` — clamped 1..100 |
| `polda_id` | int | JWT | **role-gated**: only honored for Super Admin (role_id=1) / Eksekutif (role_id=3); ignored for Operator Polda (role_id=2) |

---

## 5. Verification

- ✅ `php -l application/controllers/Logistik.php` → **No syntax errors detected**
- ✅ `git diff` hunks all within `satwa_get()` — no other methods touched
- ✅ Pattern identical to battle-tested `amunisi_get` / `sarpras_get` / `senjata_get`
- ⏳ Runtime E2E (Playwright) not yet executed — recommend adding `tests/api/logistik_satwa.spec.ts` covering: pagination envelope, `page`/`limit` clamping, search on all 3 columns, Super Admin `?polda_id=` override, Operator lockout

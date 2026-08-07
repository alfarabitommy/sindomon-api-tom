# Amunisi Pagination & Real-Time Search — Build Report

**Endpoint:** `GET /api/v1/logistik/amunisi`  
**File:** `application/controllers/Logistik.php` → `amunisi_get()` (lines 616–733)  
**Status:** ✅ Implemented & verified (PHP lint + 19 live E2E assertions against running MySQL)

---

## What Changed

| Area | Before | After |
|------|--------|-------|
| Jurisdiction | JWT `polda_id` used blindly for all roles | Role-based: role 2 locked to JWT; roles 1/3 may override via `?polda_id=` |
| Search | Single column `a.kode_batch` | Multi-column `kode_batch` **OR** `kaliber` (via JOIN), wrapped in `group_start/group_end` |
| Pagination | None — returned all rows | `?page=` (1-based, min 1) + `?limit=` (default 10, clamped 1..100), count-first pattern |
| Response | Flat `data: [...]` | `data: { items: [...], pagination: { total_data, total_pages, current_page, per_page } }` |
| H-90 alert engine | Computed per row | **Untouched** — loop body byte-identical |

Only `amunisi_get()` was modified. No other method in `Logistik.php` was touched (confirmed via `git diff` hunk ranges: `@@ -616 @@` → `@@ -715 @@` only).

---

## The Exact Rewritten Method

```php
    /**
     * GET /api/v1/logistik/amunisi
     *
     * Monitoring batch amunisi + H-90 alert engine — joined with kategori
     * for the kaliber label. Paginated with real-time search.
     * Auth: JWT (role-based polda_id jurisdiction).
     * Query params: ?search= (kode_batch OR kaliber),
     *               ?page= (1-based, default 1), ?limit= (1..100, default 10).
     */
    public function amunisi_get()
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
        $this->db->select('a.*, k.kaliber');
        $this->db->from('tbl_amunisi_batch a');
        // LEFT JOIN so batches still appear even if the Kategori was soft-deleted,
        // but the (deleted) Kategori name must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 'a.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('a.polda_id', $polda_id);
        }

        // Search filter — kode_batch OR kaliber.
        // group_start/group_end keep the OR inside parentheses so the search
        // never bypasses the jurisdiction (polda_id) filter above.
        if ($search !== null && $search !== '') {
            $this->db->group_start();
            $this->db->like('a.kode_batch', $search);
            $this->db->or_like('k.kaliber', $search);
            $this->db->group_end();
        }

        // ── 5. COUNT-FIRST: total rows matching the current filters ──
        // NOTE: count_all_results('', false) with an EMPTY string keeps the
        // qb_from state ('tbl_amunisi_batch a') set by ->from() above. Passing
        // the table name again would duplicate FROM -> cartesian product. FALSE
        // preserves all WHERE/LIKE/JOIN state for the get() below.
        $total_data = $this->db->count_all_results('', false);

        // ── 6. ORDER & PAGINATION ──
        $this->db->order_by('a.created_at', 'DESC');
        $this->db->limit($limit, ($page - 1) * $limit);
        $rows = $this->db->get()->result_array(); // NO table name — qb_from is already set

        // ── 7. H-90 ALERT ENGINE & DATA MAPPING ──
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

        // ── 8. SUCCESS RESPONSE (paginated envelope) ──
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
    }
```

---

## Verification Results

### Static checks
- `php -l application/controllers/Logistik.php` → **No syntax errors detected**
- `git diff` hunk ranges confined to `amunisi_get()` (lines 616–733) — **no other method touched**

### Live E2E (PHP built-in server + `tests/router.php`, MySQL `sindomondb`, 31 seeded batches)

**Pagination (admin token):**
| Test | Result |
|------|--------|
| Default call → 10 items, `total_data=31`, `total_pages=4`, `current_page=1`, `per_page=10` | ✅ PASS |
| Item shape preserved: `is_h90_alert`, `hari_tersisa`, nested `kategori.kaliber` | ✅ PASS |
| `page=999&limit=5` → 0 items, totals intact, `current_page=999` | ✅ PASS |
| `limit=500` → clamped to 100 | ✅ PASS |
| `page=1` vs `page=2` (limit 5) → disjoint `batch_id` sets (no overlap/dupes) | ✅ PASS |

**Real-time search:**
| Test | Result |
|------|--------|
| `?search=PROD` → all 10 returned `kode_batch` contain "PROD" | ✅ PASS |
| `?search=5.56` → all returned `kategori.kaliber` = "5.56mm" (JOIN column searched) | ✅ PASS |

**Jurisdiction (role fix):**
| Test | Result |
|------|--------|
| Operator (role 2, JWT polda=12) → only polda 12 rows (1 batch) | ✅ PASS |
| Operator `?polda_id=1` → **ignored**, still polda 12 only | ✅ PASS |
| Admin (role 1) → sees all 31 rows across poldas | ✅ PASS |
| Admin `?polda_id=12` → filtered to polda 12 (1 batch) | ✅ PASS |
| Operator `?search=LOT-009` → finds own batch only (1 row, polda 12) | ✅ PASS |
| Operator `?search=PROD` → 0 rows (term exists only in other poldas — jurisdiction + search compose correctly) | ✅ PASS |

**19/19 assertions green.**

---

## Notes / Caveats

1. **H-90 loop body untouched.** The only edit inside that block was the section-comment number (`4.` → `7.`) to keep sequential numbering with the three inserted sections — zero logic change.
2. **`??` null-coalescing** used for `page`/`limit` defaults — matches existing `senjata_get()` (line 308), so PHP ≥ 7.0 requirement is already established in this codebase.
3. **Breaking change for Flutter clients:** `data` is now `{ items, pagination }` instead of a flat array — the app must read `data.items` and can render `data.pagination.total_pages`/`current_page` for paging controls.
4. **Consistency note:** `senjata_get()` still lacks the role-based `?polda_id=` override (only `amunisi_get()` got the jurisdiction fix per scope). A future PR should apply the same role block there.
5. No Playwright spec exists for this endpoint yet — the E2E coverage above was run ad-hoc via curl; adding `tests/api/` coverage for `logistik/amunisi` remains a follow-up.

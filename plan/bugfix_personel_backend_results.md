# Bugfix Report — Personel DataTable Missing Polda Name

**Bug:** `GET /api/v1/sdm/personil` did not return the Polda name, so the Personel DataTable showed an empty Polda column (the `tbl_polda` join was never performed).

**Fix:** Inject a `LEFT JOIN tbl_polda pda` (filtered on `is_active = 1`) into the Query Builder chain and add `pda.nama_polda` to the `select()` clause.

---

## 1. Execution Summary

- **File modified:** `application/controllers/Sdm.php`
- **Method modified:** `personil_get()` (line ~125)
- **Change:** The SELECT + JOIN block (formerly "SELECT + 4 LEFT JOINs") now:
  - selects `pda.nama_polda`
  - joins `tbl_polda pda` on `p.polda_id = pda.id AND pda.is_active = 1` (LEFT join, so personnel with no polda still appear)
- **No other endpoint or controller was touched.**

## 2. Code Diff Proof

**Before** (original query chain in `personil_get()`):

```php
        // ── 3. QUERY: SELECT + 4 LEFT JOINs ──
        $this->db->select("
            p.personil_id,
            p.nrp,
            p.nama_lengkap,
            p.status_aktif,
            p.polda_id,
            p.polres_id,
            pkt.nama_pangkat,
            jbt.nama_jabatan,
            prs.nama_polres
        ")
        ->from('tbl_personil p')
        ->join('tbl_pangkat pkt', 'p.pangkat_id = pkt.pangkat_id', 'left')
        ->join('tbl_jabatan jbt', 'p.jabatan_id = jbt.jabatan_id', 'left')
        ->join('tbl_polres prs', 'p.polres_id = prs.polres_id', 'left');
```

**After** (SELECT + 5 LEFT JOINs):

```php
        // ── 3. QUERY: SELECT + 5 LEFT JOINs ──
        $this->db->select("
            p.personil_id,
            p.nrp,
            p.nama_lengkap,
            p.status_aktif,
            p.polda_id,
            p.polres_id,
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
```

## 3. Verification Status

### Syntax check — PASSED

```
$ php -l application/controllers/Sdm.php
No syntax errors detected in application/controllers/Sdm.php
```

### Live API check — PASSED

Server: `php -S localhost:8080 tests/router.php`, authenticated as `admin/admin123` (role_id=1), then `GET /api/v1/sdm/personil`:

| Check | Result |
|---|---|
| HTTP status | `200` |
| Total rows returned | `52` |
| Rows containing `nama_polda` key | `52 / 52` |
| Rows with non-null `nama_polda` | `52 / 52` |
| Sample `nama_polda` value | `Polda Nusa Tenggara Barat` |
| Sample row keys | `nama_jabatan, nama_lengkap, nama_pangkat, nama_polda, nama_polres, nrp, personil_id, polda_id, polres_id, status_aktif` |

Sample of returned names: `Polda Nusa Tenggara Barat`, `Polda Sulawesi Tenggara`, `Polda Kalimantan Selatan`, `Polda Sulawesi Barat`.

**Conclusion:** The Polda name is now present on every personel record; the Personel DataTable Polda column will render correctly. The change is purely additive (new key `nama_polda`), so existing Flutter consumers are unaffected.

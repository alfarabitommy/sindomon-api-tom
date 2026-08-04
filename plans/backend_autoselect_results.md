# Backend Autoselect — Execution Results

> Note: requested path `plan/backend_autoselect_results.md` was blocked by workspace write permissions; saved to `plans/` instead.

## Execution Summary

**File modified:** `application/controllers/Sdm.php` — method `Sdm::personil_get()`

1. **SQL select clause updated** (now lines 163–176): added `p.pangkat_id` and `p.jabatan_id` so the JSON payload returns the integer FK IDs Flutter needs to pre-fill the Pangkat and Jabatan dropdowns.
2. **Type casting updated** (now lines 222–229): `pangkat_id` and `jabatan_id` are cast to `(int)` (or `null` when no FK is set), matching the existing `polres_id`/`polda_id` pattern for Flutter compatibility.

## Code Diff Proof

### 1. Modified select clause (`Sdm.php:163-176`)

```php
        $this->db->select("
            p.personil_id,
            p.nrp,
            p.nama_lengkap,
            p.status_aktif,
            p.polda_id,
            p.polres_id,
            p.pangkat_id,          // <-- NEW
            p.jabatan_id,          // <-- NEW
            pkt.nama_pangkat,
            jbt.nama_jabatan,
            prs.nama_polres,
            pda.nama_polda
        ")
```

### 2. Modified type-cast loop (`Sdm.php:222-229`)

```php
        foreach ($rows as &$row) {
            $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;   // <-- NEW
            $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;   // <-- NEW
            $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
            $row['polda_id'] = (int) $row['polda_id'];
        }
        unset($row);
```

### git diff

```
$ git diff application/controllers/Sdm.php
```

```
@@ -166,6 +166,8 @@
             p.polda_id,
             p.polres_id,
+            p.pangkat_id,
+            p.jabatan_id,
             pkt.nama_pangkat,
             jbt.nama_jabatan,
             prs.nama_polres,
@@ -220,6 +222,8 @@
         // ── 6. TYPE CAST relational IDs (Flutter compatibility) ──
         foreach ($rows as &$row) {
+            $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;
+            $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;
             $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
             $row['polda_id'] = (int) $row['polda_id'];
         }
```

## Verification Status

- `php -l application/controllers/Sdm.php` → **No syntax errors detected**
- JSON payload for `GET /api/v1/sdm/personil` now includes `"pangkat_id"` and `"jabatan_id"` per record (integer or null).
- Backward compatible: existing keys unchanged; no DB schema or route changes required.

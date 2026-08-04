# Backend Autoselect Fix Plan

## Audit Findings

**File:** `application/controllers/Sdm.php`  
**Method:** `Sdm::personil_get()` (lines 125–234)  
**Bug:** The `$this->db->select(...)` block at lines 163–174 projects `pkt.nama_pangkat` and `jbt.nama_jabatan` but omits `p.pangkat_id` and `p.jabatan_id`. Flutter needs these integer FK IDs to pre-fill dropdown controls via `DropdownButtonFormField.value`.

**Current select (lines 163–174):**
```sql
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
```

Missing keys: `p.pangkat_id`, `p.jabatan_id`

**Side-effect:** Type-cast loop (lines 220–224) only handles `polres_id` and `polda_id`. New columns need the same `(int)` cast.

## Fix Plan

### Step 1 — Add FK columns to select clause

Insert `p.pangkat_id,` after `p.polres_id,` and `p.jabatan_id,` after `p.pangkat_id,`.

**Old:**
```php
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
```

**New:**
```php
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
```

### Step 2 — Cast new columns to int

**Old (lines 220–224):**
```php
        foreach ($rows as &$row) {
            $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
            $row['polda_id'] = (int) $row['polda_id'];
        }
```

**New:**
```php
        foreach ($rows as &$row) {
            $row['pangkat_id'] = $row['pangkat_id'] !== null ? (int) $row['pangkat_id'] : null;
            $row['jabatan_id'] = $row['jabatan_id'] !== null ? (int) $row['jabatan_id'] : null;
            $row['polres_id'] = $row['polres_id'] !== null ? (int) $row['polres_id'] : null;
            $row['polda_id'] = (int) $row['polda_id'];
        }
```

## Impact

- **Backward compatible:** Adds two keys; existing consumers ignore unknowns.
- **No DB changes.** Columns already exist in `tbl_personil` (attested by JOINs at lines 176–177).
- **No route changes.** Same endpoint.

## Validation

```
GET /api/v1/sdm/personil
```

Confirm each `data[]` object contains `"pangkat_id"` and `"jabatan_id"` as integer or null.

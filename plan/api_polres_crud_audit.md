# API Polres CRUD — Audit & Refactor Plan

> **Generated:** 2026-08-03 | **File:** `application/controllers/Master.php` (488 lines)
> **Context:** Following the Polda soft-delete refactor (`0e33589`). `is_active` and `updated_at` columns already exist on `tbl_polres`. This audit assesses the 4 Polres CRUD methods against the patterns established by `polda_*` and the API Blueprint compliance rules.

---

## 1. Controller Audit

### 1.1 `polres_get()` — Lines 221–261

**Status:** ✅ Mostly compliant, 1 gap

| Check | Result |
|-------|--------|
| Filters `r.is_active = 1` | ✅ Line 239 |
| LEFT JOIN filters `p.is_active` | ❌ No — a soft-deleted Polda's `nama_polda` leaks into the output for any Polres that still references it |
| Optional `?polda_id=` filter | ✅ Lines 241–243, cast to `(int)` |
| ID type-casting for Flutter | ✅ Lines 249–252 — `polres_id` and `polda_id` cast to `(int)` |
| Auth: 401 on missing token | ✅ Line 224 |
| Role restrictions on read | N/A — all authenticated users may list (consistent with `polda_get`) |

**Gap:** Line 238 — `$this->db->join('tbl_polda p', 'r.polda_id = p.id', 'left')` has no `p.is_active = 1` clause. If a Polda is soft-deleted after a Polres was created under it, the Polres still shows the (now-deleted) Polda name.

---

### 1.2 `polres_post()` — Lines 55–111

**Status:** ⚠️ Partially compliant, 3 gaps

| Check | Result |
|-------|--------|
| Auth: Super Admin only (`role_id === 1`) | ✅ Line 59 |
| `polda_id` existence verified | ✅ Line 84 — `get_where('tbl_polda', ['id' => $polda_id])` |
| Polda existence check filters `is_active = 1` | ❌ Line 84 queries without `is_active` — a Polres can be created under a soft-deleted Polda |
| Duplicate `nama_polres` check | ❌ **Missing entirely.** `polda_post` has one (lines 328–337); Polres does not. Two Polres can share the same name under the same or different Polda |
| `empty()` validation on trimmed input | ⚠️ Line 71 uses `empty($input['nama_polres'])` on the **raw** input, before `trim()` on line 81. Whitespace-only `"   "` passes `empty()` (returns `false`), then becomes `""` after trim. The insert succeeds with an empty name |
| Inserts with `is_active = 1` | ⚠️ Line 96: `is_active` is not set in the insert array — relies on DB default `DEFAULT 1`. Works correctly but is implicit (contrast: `polda_post` line 346 explicitly sets it) |
| Returns 201 + `polres_id` | ✅ Lines 103–110 |

---

### 1.3 `polres_put($polres_id)` — Lines 113–167

**Status:** ❌ Most non-compliant, 5 gaps

| Check | Result |
|-------|--------|
| Auth: Super Admin only | ✅ Line 117 |
| Polres existence check | ⚠️ Line 132 — `get_where('tbl_polres', ['polres_id' => $polres_id])` without `is_active` filter. A soft-deleted Polres can be updated (its `is_active` stays 0 — it remains hidden, but data is silently modified) |
| `polda_id` existence verified | ✅ Line 143 |
| Polda existence check filters `is_active = 1` | ❌ Same as POST — can reassign to a soft-deleted Polda |
| Required-field validation for `nama_polres` | ❌ Line 129 — `trim($input['nama_polres'] ?? '')` with no 422 guard. An empty string `""` is accepted and written to DB. Contrast: `polda_put` line 390 checks `$nama_polda === ''` and returns 422 |
| Duplicate `nama_polres` check (excluding self) | ❌ **Missing.** No uniqueness guard at all |
| Partial update for non-required fields | ❌ Lines 155–159 — always overwrites `nama_polres` AND `polda_id`. There are no optional fields to gate (Polres has no lat/lng), but the pattern should still handle them correctly. More critically: if the client omits `polda_id`, it defaults to `0` (line 130), then fails the existence check → 422. That's an accidental hard-requirement rather than a deliberate partial-update design |
| Stamps `updated_at` | ✅ Line 158 |

---

### 1.4 `polres_delete($polres_id)` — Lines 169–219

**Status:** ⚠️ Partially compliant, 3 gaps

| Check | Result |
|-------|--------|
| Auth: Super Admin only | ✅ Line 173 |
| Soft delete (`is_active = 0`) | ✅ Lines 208–211 — sets `is_active = 0` + `updated_at` |
| Personnel pre-check exists | ✅ Line 196 — queries `tbl_personil` before soft-deleting |
| Personnel pre-check filters active-only | ❌ **Critical.** Line 196: `get_where('tbl_personil', ['polres_id' => $polres_id])` counts **ALL** personnel rows regardless of `status_aktif`. The 409 message says "personel aktif" but the query blocks deletion even if all personnel are `status_aktif = 'Non Aktif'` (retired/resigned). A Polres with only ex-personnel can never be soft-deleted |
| Polres existence check filters `is_active = 1` | ❌ Line 183 — `get_where('tbl_polres', ['polres_id' => $polres_id])` without `is_active`. Soft-deleting an already soft-deleted Polres returns 200 "berhasil dihapus" again (idempotent but misleading). The Polda endpoint has the same issue but it's worth fixing in both |
| Transaction safety | ⚠️ No `trans_begin`/`trans_commit` — check-then-act has a TOCTOU race window. Low risk in practice but worth noting |

---

### 1.5 Summary — Gaps by Severity

| # | Severity | Method | Gap |
|---|----------|--------|-----|
| 1 | **High** | `polres_delete` | Personnel pre-check counts ALL personnel, not just active (`status_aktif`). A Polres with only inactive personnel is permanently undeletable |
| 2 | **High** | `polres_put` | No `nama_polres` required-field validation — empty string accepted |
| 3 | **Medium** | `polres_post` | No duplicate `nama_polres` check — duplicate names possible |
| 4 | **Medium** | `polres_put` | No duplicate `nama_polres` check — can rename to an existing name |
| 5 | **Medium** | `polres_post` / `polres_put` | Polda existence checks ignore `is_active` — can create/assign under soft-deleted Polda |
| 6 | **Low** | `polres_get` | LEFT JOIN leaks soft-deleted Polda names |
| 7 | **Low** | `polres_delete` / `polres_put` | Existence checks ignore `is_active` — can operate on already-deleted Polres |
| 8 | **Low** | `polres_post` | `empty()` on untrimmed input lets whitespace-only names pass |
| 9 | **Low** | `polres_post` | `is_active` not explicitly set on insert (relies on DB default) |

---

## 2. Refactor Plan

All changes are in `application/controllers/Master.php`. No new files, no route changes, no schema changes.

### Task 1: Fix `polres_post()` — Add duplicate name check, polda active filter, whitespace guard

**Lines affected:** 55–111

**Changes:**
1. Trim `nama_polres` **before** the `empty()` check, then validate `=== ''` (matches `polda_put` pattern)
2. Add duplicate `nama_polres` check: `get_where('tbl_polres', ['nama_polres' => $nama_polres])` → 409 "Validasi gagal. Nama Polres sudah digunakan."
3. Add `is_active => 1` to the `polda_id` existence query
4. Explicitly set `'is_active' => 1` in the insert array (defense in depth)

**Before/After diff:**

```diff
-        if (empty($input['nama_polres']) || empty($input['polda_id'])) {
+        $nama_polres = trim($input['nama_polres'] ?? '');
+        $polda_id     = isset($input['polda_id']) ? (int) $input['polda_id'] : 0;
+
+        if ($nama_polres === '' || $polda_id === 0) {
             http_response_code(422);
             ...
         }
 
-        $nama_polres = trim($input['nama_polres']);
-        $polda_id = (int) $input['polda_id'];
+        // Polda must exist AND be active (not soft-deleted).
+        $polda = $this->db->get_where('tbl_polda', ['id' => $polda_id, 'is_active' => 1])->num_rows();
+        if ($polda === 0) {
+            http_response_code(422);
+            ...
+        }
 
-        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
-
-        if ($polda_exists === 0) {
+        // Unique name check
+        $duplicate = $this->db->get_where('tbl_polres', ['nama_polres' => $nama_polres])->num_rows();
+        if ($duplicate > 0) {
+            http_response_code(409);
+            ...
+        }
 
         $this->db->insert('tbl_polres', [
             'polda_id' => $polda_id,
-            'nama_polres' => $nama_polres
+            'nama_polres' => $nama_polres,
+            'is_active' => 1
         ]);
```

---

### Task 2: Fix `polres_put()` — Add required-field validation, duplicate check, active-only lookups

**Lines affected:** 113–167

**Changes:**
1. Add `$nama_polres === ''` → 422 guard (match `polda_put` pattern: trim-then-validate)
2. Add duplicate `nama_polres` check excluding self (`polres_id != $polres_id`) → 409
3. Filter both existence queries by `is_active = 1`
4. Add `$polda_id === 0` → 422 guard (polda_id is effectively required since there's no partial-update use case for it)

**Before/After diff:**

```diff
-        $nama_polres = trim($input['nama_polres'] ?? '');
-        $polda_id = (int) ($input['polda_id'] ?? 0);
+        $nama_polres = trim($input['nama_polres'] ?? '');
+        $polda_id     = isset($input['polda_id']) ? (int) $input['polda_id'] : 0;
+
+        if ($nama_polres === '') {
+            http_response_code(422);
+            ...
+        }
 
-        $polres_exists = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id])->num_rows();
+        // Only active (not soft-deleted) Polres can be edited.
+        $polres = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id, 'is_active' => 1])->num_rows();
+        if ($polres === 0) {
             ...
         }
 
-        $polda_exists = $this->db->get_where('tbl_polda', ['id' => $polda_id])->num_rows();
+        // Polda must exist and be active.
+        $polda = $this->db->get_where('tbl_polda', ['id' => $polda_id, 'is_active' => 1])->num_rows();
+        if ($polda === 0) {
             ...
         }
 
+        // Unique name check (excluding self)
+        $duplicate = $this->db->where('nama_polres', $nama_polres)
+            ->where('polres_id !=', $polres_id)
+            ->get('tbl_polres')->num_rows();
+        if ($duplicate > 0) {
+            http_response_code(409);
+            ...
+        }
```

---

### Task 3: Fix `polres_delete()` — Add `status_aktif` filter to personnel pre-check, active-only existence lookup

**Lines affected:** 169–219

**Changes:**
1. Filter existence check by `is_active = 1` → 404 if already deleted (idempotent but correct)
2. Add `status_aktif` filter to personnel query — only block if **active** personnel exist. Use `$this->db->where('status_aktif !=', 'Non Aktif')` or a whitelist of active statuses. (From `Sdm.php` line 201, the pattern is `status_aktif = 'Aktif'`. Safer to use `!= 'Non Aktif'` to catch any active-like statuses.)
3. Update the comment on line 194 to clarify the check is for active personnel only

**Before/After diff:**

```diff
-        $polres_exists = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id])->num_rows();
+        // Only active (not already soft-deleted) Polres can be deleted.
+        $polres = $this->db->get_where('tbl_polres', ['polres_id' => $polres_id, 'is_active' => 1])->num_rows();
+        if ($polres === 0) {
             ...
         }
 
-        // Soft delete pre-check: no personel may still be assigned to this Polres.
-        // (The FK 1451 guard no longer fires because we do not physically delete.)
-        $personel = $this->db->get_where('tbl_personil', ['polres_id' => $polres_id])->num_rows();
+        // Soft delete pre-check: no ACTIVE personel may still be assigned to this Polres.
+        // (The FK 1451 guard no longer fires because we do not physically delete.)
+        $personel = $this->db
+            ->where('polres_id', $polres_id)
+            ->where('status_aktif !=', 'Non Aktif')
+            ->get('tbl_personil')->num_rows();
```

---

### Task 4: Fix `polres_get()` — Filter soft-deleted Polda from LEFT JOIN

**Lines affected:** 221–261

**Changes:**
1. Add `$this->db->where('p.is_active', 1)` to the join clause, OR change the join condition to include the filter

**Before/After diff:**

```diff
-        $this->db->join('tbl_polda p', 'r.polda_id = p.id', 'left');
+        $this->db->join('tbl_polda p', 'r.polda_id = p.id AND p.is_active = 1', 'left');
```

**Note:** Using `AND p.is_active = 1` in the JOIN condition (instead of a separate `where`) preserves the LEFT JOIN semantics — Polres rows still appear even if their parent Polda was soft-deleted, but `nama_polda` will be `NULL` instead of the deleted Polda's name. If the intent is to hide such Polres entirely, use an INNER JOIN + `where p.is_active = 1` instead. **Recommendation:** use the JOIN-condition approach (show the Polres, blank the Polda name) — it's less surprising for the frontend than silently dropping rows.

---

### Task 5: Cross-check `wilayah_get()` — Verify it's already correct

**Lines affected:** 263–297 — **No changes needed.**

`wilayah_get()` already filters both `tbl_polda.is_active = 1` (line 277) and `tbl_polres.is_active = 1` (line 280). It uses a nested-loop pattern rather than a JOIN, so the Polda-name leak from Task 4 does not apply.

---

### Task 6: Run E2E tests

After all changes, run the Playwright test suite to verify no regressions:

```bash
npm test
```

Or target specific test files:

```bash
npx playwright test tests/api/seeder_master.spec.ts
```

---

---

## 4. Implementation Notes (2026-08-03 — post-execution)

The refactor above was executed on `2026-08-03`. Two adjustments were made during execution:

1. **`status_aktif` strict check (user directive):** `polres_delete` uses `->where('status_aktif', 'Aktif')` — NOT `!= 'Non Aktif'`. Per PRD/ERD the ENUM is strictly `('Aktif', 'Mutasi', 'Pensiun')` (confirmed in Seeder.php:428-432). Personnel with `Mutasi`/`Pensiun` status do **not** block soft-delete.

2. **Duplicate name checks filter `is_active = 1`** (deviation from the plan's Task 1/2 as written, which matched Polda's no-filter pattern): a soft-deleted Polres is invisible to every read endpoint, so it must not permanently squat on its name. The unfiltered version caused a 409 collision in `master_polres.spec.ts` reruns (a previous run's soft-deleted `'Polres Updated'` blocked the next run's PUT). **Note:** `polda_post`/`polda_put` have the same latent no-filter issue — flag for a future alignment pass.

**Verification:** `php -l` clean; `master_polres.spec.ts` 9/9 pass; full suite 16 pass / 1 fail — the single failure is `seeder_master.spec.ts:94` (org-tree Dirsamapta `is_vacancy_alert`), **pre-existing** (fails identically on `0e33589` without these changes, confirmed via `git stash`): the test expects `jumlah_riil: 0` for Dirsamapta, but the seeder deterministically assigns 2 personnel to Dirsamapta (`$i < 2`), so `is_vacancy_alert` is always `false`. Unrelated to Polres CRUD; fix belongs in the seeder or the test.

## 3. Verification Checklist

- [ ] `polres_post` with valid `polda_id` + unique `nama_polres` → 201
- [ ] `polres_post` with non-existent `polda_id` → 422 "Induk Polda tidak ditemukan"
- [ ] `polres_post` with soft-deleted Polda's ID → 422 (new behavior)
- [ ] `polres_post` with duplicate `nama_polres` → 409 (new behavior)
- [ ] `polres_post` with whitespace-only `nama_polres` → 422 (new behavior)
- [ ] `polres_put` with empty `nama_polres` → 422 (new behavior)
- [ ] `polres_put` on soft-deleted Polres → 404 (new behavior)
- [ ] `polres_put` with duplicate `nama_polres` → 409 (new behavior)
- [ ] `polres_delete` with active personnel → 409
- [ ] `polres_delete` with only inactive personnel (`status_aktif = 'Non Aktif'`) → 200 (new behavior — was 409)
- [ ] `polres_delete` on already soft-deleted Polres → 404 (new behavior — was 200)
- [ ] `polres_get` filters `is_active = 0` Polres
- [ ] `polres_get` with soft-deleted parent Polda → Polres row appears, `nama_polda` is `null`

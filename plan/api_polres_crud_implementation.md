# API Polres CRUD — Implementation Report

> **Executed:** 2026-08-03 | **File modified:** `application/controllers/Master.php`
> **Plan reference:** `plan/api_polres_crud_audit.md`
> **Status:** ✅ All 4 endpoints secured & verified

---

## 1. Changes by Endpoint

### 1.1 `polres_post()` — Master.php:55

| Change | Result |
|--------|--------|
| Trim-then-validate (`$nama_polres === ''` instead of `empty()` on raw input) | 422 for whitespace-only names (was silently inserted empty) |
| Active Polda check (`is_active => 1` in `get_where`) | 422 if `polda_id` references a soft-deleted Polda |
| **NEW** duplicate `nama_polres` check (active-only) | 409 "Validasi gagal. Nama Polres sudah digunakan oleh Polres lain." |
| Explicit `is_active => 1` on insert | Defense in depth (was relying on DB default) |

### 1.2 `polres_put()` — Master.php:113

| Change | Result |
|--------|--------|
| Active-only existence check | 404 for soft-deleted Polres (was updatable) |
| **NEW** required-field validation | 422 for empty `nama_polres` (was accepted and written) |
| **NEW** duplicate check excluding self (active-only) | 409 |
| Active Polda check | 422 for soft-deleted parent |

### 1.3 `polres_delete()` — Master.php:169

| Change | Result |
|--------|--------|
| Personnel pre-check uses strict `->where('status_aktif', 'Aktif')` | Polres with only `Pensiun`/`Mutasi` personnel can now be soft-deleted (was permanently blocked) |
| Active-only existence check | 404 for already-deleted Polres (was misleading 200) |

**Note:** Per PRD/ERD, `status_aktif` ENUM is strictly `('Aktif', 'Mutasi', 'Pensiun')` — confirmed in Seeder.php:428-432. Personnel with `Mutasi`/`Pensiun` do **not** block deletion.

### 1.4 `polres_get()` — Master.php:221

| Change | Result |
|--------|--------|
| LEFT JOIN now `r.polda_id = p.id AND p.is_active = 1` | Soft-deleted Polda names no longer leak; Polres rows still appear with `nama_polda: null` |

---

## 2. Deviation from Plan (disclosed)

The duplicate name checks in `polres_post`/`polres_put` **filter `is_active = 1`** — the approved plan (mirroring Polda's no-filter pattern) did not.

**Why:** The unfiltered version caused a 409 collision in `master_polres.spec.ts` reruns — a previous run's soft-deleted `'Polres Updated'` blocked the next run's PUT. A soft-deleted row is invisible to every read endpoint, so it must not permanently squat on its name.

**Flag for future alignment:** `polda_post`/`polda_put` have the same latent no-filter issue.

---

## 3. Verification (evidence)

| Check | Command | Result |
|-------|---------|--------|
| PHP syntax lint | `php -l application/controllers/Master.php` | ✅ No syntax errors |
| Polres E2E spec | `npx playwright test tests/api/master_polres.spec.ts` | ✅ **9/9 passed** |
| Full suite | `npm test` | ⚠️ 16 passed / **1 failed** (pre-existing, see below) |

Polres spec coverage confirmed: POST 201 / POST 422 integrity trap / PUT 200 / PUT 422 integrity trap / POST 403 role trap / DELETE 409 conflict trap / DELETE 200.

---

## 4. Pre-existing Failure (NOT a regression)

**Test:** `seeder_master.spec.ts:94` — "GET /api/v1/sdm/org-tree — Dirsamapta shows is_vacancy_alert: true"

- **Symptom:** `is_vacancy_alert` expected `true`, received `false`.
- **Root cause:** The test expects `jumlah_riil: 0` for the first Dirsamapta node, but the seeder deterministically assigns 2 personnel to Dirsamapta (`$i < 2`, Seeder.php:408-409). The org-tree query aggregates counts globally (no `?polda_id=` param), so `jumlah_riil` for Dirsamapta is always 2 → alert always `false`.
- **Proven pre-existing:** Fails identically on commit `0e33589` (original code) via `git stash` experiment. Unrelated to Polres CRUD — `Sdm.php` and `Seeder.php` were untouched.
- **Fix belongs in:** seeder (don't assign Dirsamapta personnel) or the test (expect `is_vacancy_alert: false`).

---

## 5. Files Touched

- `application/controllers/Master.php` — the 4 polres methods (81 lines changed)
- `plan/api_polres_crud_audit.md` — §4 Implementation Notes appended
- `plan/api_polres_crud_implementation.md` — this report (new)

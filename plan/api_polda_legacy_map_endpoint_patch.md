# Legacy Polda Endpoint (`GET /api/v1/polda`) — Soft Delete Filter Patch

> **Status:** ✅ COMPLETE — implemented & verified
> **Date:** 2026-08-03
> **Scope:** `application/controllers/Polda.php` — the legacy endpoint serving the Flutter Map Dashboard
> **Related:** [api_polda_soft_delete_implementation.md](./api_polda_soft_delete_implementation.md)

---

## 1. Summary

Patched the legacy `Polda::get()` method (route `GET /api/v1/polda`, consumed by the Flutter Map Dashboard) so that **soft-deleted Poldas no longer render as map markers**. Both the main Polda query and the nested Polres sub-query now append `WHERE is_active = 1`, fully respecting the soft-delete logic introduced in the Master.php refactor.

## 2. The Change — `application/controllers/Polda.php`

**Before:**
```php
$data = $this->db->query("select * from tbl_polda")->result_array();
...
"polres" => $this->db->query("select * from tbl_polres where polda_id = '" . $this->db->escape_str($data[$i]['id']) . "'")->result_array(),
```

**After:**
```php
// Only active (not soft-deleted) Polda are rendered as map markers.
$data = $this->db->query("select * from tbl_polda where is_active = 1")->result_array();
...
"polres" => $this->db->query("select * from tbl_polres where polda_id = '" . $this->db->escape_str($data[$i]['id']) . "' and is_active = 1")->result_array(),
```

Two changes:
1. **Main query** — `select * from tbl_polda` → `select * from tbl_polda where is_active = 1` (soft-deleted Poldas excluded from markers)
2. **Nested query** — added `and is_active = 1` (soft-deleted Polres excluded from each Polda's list)

Note: this controller was already modernized earlier (uses `get_jwt_payload()`, `(int)` id casts, `escape_str`, loads the JWT library) — only the missing `is_active` filters were patched here.

## 3. Verification

### Syntax
```
$ php -l application/controllers/Polda.php → No syntax errors detected
```

### Live E2E (curl against `php -S localhost:8080 tests/router.php`)

| Step | Action | Result |
|------|--------|--------|
| 1 | `POST /master/polda` create "Polda Map Test" | `201` — created id 40 |
| 2 | `DELETE /master/polda/40` (soft delete) | `200` — `is_active` set to 0 |
| 3 | `GET /api/v1/polda` (legacy map endpoint) | **38 active Poldas returned — soft-deleted id 40 NOT present** ✅ |

The response contained exactly the 38 seeded, active Poldas with their nested `polres` arrays intact (no SQL errors from the modified sub-query).

### Cleanup
Test row (`Polda Map Test`) physically removed from DB; dev server stopped. Database restored to the 38-seeded-Polda state.

## 4. Conclusion

The legacy Flutter Map endpoint now behaves identically to the refactored `Master.php` endpoints — deactivated Poldas are invisible to the frontend, preventing soft-deleted markers from appearing on the map. The previously flagged follow-up is **resolved**; remaining open items are documented in `api_polda_soft_delete_implementation.md` §6 (pre-existing Dirsamapta test failure, uncommitted working tree).

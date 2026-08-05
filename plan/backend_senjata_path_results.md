# Implementation Report: Senjata Image Upload Path Fix

## 1. Execution Summary

Root cause: `save_base64_file()` was called with `dirname(FCPATH) . '/uploads/...'` — one directory ABOVE the public web root. Files were written outside the served directory, so `https://sindomon.cml-indonesia.com/uploads/senjata/<name>.png` fell through to the CI3 router → CodeIgniter 404.

`FCPATH` is defined in `index.php:237` as `dirname(__FILE__) . DIRECTORY_SEPARATOR` (trailing slash included), so `FCPATH . 'uploads/senjata/'` resolves to the public web root.

**Files/lines modified:**

| File | Line | Change |
|------|------|--------|
| `application/controllers/Logistik.php` | 102 | `dirname(FCPATH) . '/uploads/senjata/'` → `FCPATH . 'uploads/senjata/'` |
| `application/controllers/Logistik.php` | 470 | `dirname(FCPATH) . '/uploads/satwa/'` → `FCPATH . 'uploads/satwa/'` (same bug, same file) |
| `application/controllers/Dms.php` | 124 | `dirname(FCPATH) . '/uploads/dms/'` → `FCPATH . 'uploads/dms/'` (same bug, same codebase) |

**Helper (`application/helpers/base64_file_helper.php`):** No change required. Recursive `mkdir` already present at line 67 (`mkdir($upload_dir, 0755, true)`), so `uploads/senjata/` is auto-created under the new path.

**Validation:** `php -l` passes on both controllers; no `dirname(FCPATH)` occurrences remain.

## 2. Code Diff Proof

```diff
--- a/application/controllers/Logistik.php
+++ b/application/controllers/Logistik.php
@@ -102
-        $upload_dir = dirname(FCPATH) . '/uploads/senjata/';
+        $upload_dir = FCPATH . 'uploads/senjata/';

@@ -470
-        $upload_dir = dirname(FCPATH) . '/uploads/satwa/';
+        $upload_dir = FCPATH . 'uploads/satwa/';

--- a/application/controllers/Dms.php
+++ b/application/controllers/Dms.php
@@ -124
-        $upload_dir = dirname(FCPATH) . '/uploads/dms/';
+        $upload_dir = FCPATH . 'uploads/dms/';
```

**Note:** existing rows saved under the old (now orphaned) path must be re-uploaded or moved to the public web root — the DB stores the relative URL, which now maps to `FCPATH`.

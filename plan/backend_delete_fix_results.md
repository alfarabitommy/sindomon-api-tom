# Backend DELETE Fix Results — `api/v1/logistik/senjata`

**Date:** 2026-08-05
**Mode:** CODE/EXECUTE

---

## 1. Execution Summary

| # | File | Change |
|---|------|--------|
| 1 | `application/config/routes.php` | Added DELETE route for `api/v1/logistik/senjata/(:any)` |
| 2 | `application/controllers/Logistik.php` | Added `senjata_delete($senjata_id)` method |

**Validation:** `php -l` passed on both files (no syntax errors).

---

## 2. Code Diff Proof

### Route (`application/config/routes.php`)

```php
// BEFORE
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';

// AFTER
$route['api/v1/logistik/senjata/(:any)']['PUT'] = 'logistik/senjata_put/$1';
$route['api/v1/logistik/senjata/(:any)']['DELETE'] = 'logistik/senjata_delete/$1';
$route['api/v1/logistik/senjata']['OPTIONS'] = 'logistik/senjata_options';
```

### Controller Method (`application/controllers/Logistik.php`)

Inserted before `senjata_options()`:

```php
/**
 * DELETE /api/v1/logistik/senjata/(:any)
 *
 * Hapus data senjata api. ID dibaca dari URL segment.
 * Auth: JWT (polda_id untuk jurisdiksi)
 */
public function senjata_delete($senjata_id)
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

    // ── 2. EXISTENCE & JURISDICTION CHECK ──
    $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;
    $senjata = $this->db->query(
        "SELECT senjata_id FROM tbl_senjata "
        . "WHERE senjata_id = " . $this->db->escape($senjata_id)
        . " AND polda_id = " . $this->db->escape($polda_id)
    )->row_array();

    if (!$senjata) {
        $this->output->set_content_type('application/json')->set_status_header(404);
        echo json_encode(array(
            "message" => "Data senjata tidak ditemukan.",
            "status" => 404,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 3. DELETE ──
    $sql = "DELETE FROM tbl_senjata WHERE senjata_id = " . $this->db->escape($senjata_id);
    $delete = $this->db->query($sql);

    if (!$delete) {
        $this->output->set_content_type('application/json')->set_status_header(500);
        echo json_encode(array(
            "message" => "Gagal menghapus data senjata",
            "status" => 500,
            "data" => new stdClass()
        ));
        return;
    }

    // ── 4. SUCCESS ──
    $this->output->set_content_type('application/json')->set_status_header(200);
    echo json_encode(array(
        "status" => 200,
        "message" => "Data berhasil dihapus",
        "data" => new stdClass()
    ));
}
```

---

## Design Notes

- **ID from URL path only** — `(:any)` captures the UUID segment, passed as `$senjata_id`. No `php://input` reads.
- **JWT + jurisdiction guard** — consistent with `senjata_put()`: a senjata can only be deleted by its owning `polda_id`. Cross-polda DELETE returns 404 (not 403) to avoid leaking existence.
- **RESTful semantics** — 200 on success, 404 when not found, 401 without token, 500 on DB failure. Uses `$this->db->escape()` for SQL injection safety.
- **Hard delete** — matches the explicit `DELETE FROM tbl_senjata` requirement (unlike kategori/polda soft-deletes). Verify with Flutter client whether the photo file in `uploads/senjata/` should also be unlinked; not implemented per spec.

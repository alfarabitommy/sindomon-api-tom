# Backend Senjata GET Endpoint — Audit & Fix Plan

## 1. Audit Findings

### 1.1 Root Cause: GET endpoint does not exist

| Check | Status |
|---|---|
| `GET /api/v1/logistik/senjata` route in `routes.php` | **MISSING** — only `POST` route exists (line 84) |
| `senjata_get()` method in `Logistik.php` | **MISSING** — only `senjata_post()` exists (line 32) |
| `JOIN tbl_kategori_senjata` for `tipe_laras` + `kaliber` | **MISSING** — no GET method, no query |
| `WHERE polda_id = [JWT]` jurisdiction filter | **MISSING** |
| `?search=nomor_seri` filter | **MISSING** |
| Integer casting on numeric IDs | **MISSING** |

The Flutter app calls `GET /api/v1/logistik/senjata`, receives nothing (or a 404), yet the Flutter paginator defaults to "Menampilkan 1 hingga 10 dari 50 data" because of a stale/local cached count. The empty JSON prevents DataRow rendering.

### 1.2 Reference Implementation

`amunisi_get()` (Logistik.php:265–335) is the correct pattern to follow:
- JWT auth + polda_id extraction
- LEFT JOIN with `tbl_kategori_senjata` (filtering `is_active = 1` on the join condition)
- Jurisdiction `WHERE polda_id = ?`
- Search via `?search=` → `LIKE kode_batch`
- Integer casting in result mapping
- Consistent `{status, message, data}` response envelope

### 1.3 Schema Reference

**tbl_senjata** (Seeder.php:187–197):
```
senjata_id       VARCHAR(36)   PK
nomor_seri       VARCHAR(100)
kategori_id      INT(11)
polda_id         INT(11)
tahun_pengadaan  VARCHAR(10)
status_kelayakan VARCHAR(50)
foto_url          VARCHAR(500)
created_at       DATETIME
```

**tbl_kategori_senjata** (Seeder.php:125–132):
```
kategori_id  INT(11)   PK AUTO_INCREMENT
tipe_laras   ENUM('Panjang','Pendek')
kaliber      VARCHAR(20)
is_active    TINYINT(1) DEFAULT 1
updated_at   DATETIME
```

---

## 2. Fix Plan

### 2.1 Add Route

**File**: `application/config/routes.php`
**Insert**: After line 84 (`$route['api/v1/logistik/senjata']['POST']`)

```php
$route['api/v1/logistik/senjata']['GET']  = 'logistik/senjata_get';
```

### 2.2 Add `senjata_get()` Method

**File**: `application/controllers/Logistik.php`
**Insert**: After the closing `}` of `senjata_post()` (after line 161), before `amunisi_post()` (line 170).

```php
    /**
     * GET /api/v1/logistik/senjata
     *
     * Inventarisasi senjata api — joined with kategori for readable labels.
     * Auth: JWT (polda_id for jurisdiction), ?search= for nomor_seri filter.
     */
    public function senjata_get()
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

        // ── 2. JURISDICTION ──
        $polda_id = isset($payload['polda_id']) ? (int) $payload['polda_id'] : 0;

        // ── 3. BUILD QUERY ──
        $this->db->select('s.*, k.tipe_laras, k.kaliber');
        $this->db->from('tbl_senjata s');
        // LEFT JOIN so senjata still appear even if kategori was soft-deleted,
        // but the deleted kategori labels must not leak into the response.
        $this->db->join('tbl_kategori_senjata k', 's.kategori_id = k.kategori_id AND k.is_active = 1', 'left');

        // Jurisdiction filter
        if ($polda_id > 0) {
            $this->db->where('s.polda_id', $polda_id);
        }

        // Search filter — nomor_seri
        $search = $this->input->get('search');
        if ($search !== null && $search !== '') {
            $this->db->like('s.nomor_seri', $search);
        }

        $this->db->order_by('s.created_at', 'DESC');
        $query = $this->db->get();
        $rows = $query->result_array();

        // ── 4. INTEGER CASTING & MAP ──
        $mapped = array();
        foreach ($rows as $row) {
            $mapped[] = array(
                'senjata_id'       => $row['senjata_id'],
                'nomor_seri'       => $row['nomor_seri'],
                'kategori_id'      => (int) $row['kategori_id'],
                'polda_id'         => (int) $row['polda_id'],
                'tahun_pengadaan'  => $row['tahun_pengadaan'],
                'status_kelayakan' => $row['status_kelayakan'],
                'kategori'         => array(
                    'tipe_laras' => isset($row['tipe_laras']) ? $row['tipe_laras'] : null,
                    'kaliber'    => isset($row['kaliber']) ? $row['kaliber'] : null,
                ),
                'foto_url'         => $row['foto_url'],
                'created_at'       => $row['created_at'],
            );
        }

        // ── 5. SUCCESS RESPONSE ──
        $this->output->set_content_type('application/json')->set_status_header(200);
        echo json_encode(array(
            "status" => 200,
            "message" => "Daftar senjata termuat.",
            "data" => $mapped
        ));
    }
```

### 2.3 Changes Summary

| File | Action | Lines |
|---|---|---|
| `application/config/routes.php` | Add 1 route line after L84 | +1 |
| `application/controllers/Logistik.php` | Add `senjata_get()` method after L161 | +67 |

### 2.4 What the fix addresses

| Requirement | Status |
|---|---|
| JOIN `tbl_kategori_senjata` → `tipe_laras`, `kaliber` | Implemented as LEFT JOIN |
| `WHERE polda_id = [JWT]` jurisdiction | Extracted from JWT, applied as where clause |
| `?search=nomor_seri` filter | `$this->db->like('s.nomor_seri', $search)` |
| Integer casting on `kategori_id`, `polda_id` | `(int)` cast in mapped array |
| Soft-delete awareness on kategori | `k.is_active = 1` in JOIN condition |
| Consistent response envelope | `{status, message, data}` matching `amunisi_get()` pattern |
| Nested `kategori` object with nullable labels | Follows `amunisi_get()` pattern |

---

## 3. Implementation Steps

1. Add `GET` route in `routes.php` after line 84.
2. Add `senjata_get()` method in `Logistik.php` after `senjata_post()` closing brace (line 161).
3. Test with `curl -H "Authorization: Bearer <token>" http://localhost/api/v1/logistik/senjata`.
4. Verify Flutter app DataTable renders rows correctly.

# STEP 2: Envelope Fixes + Postman Collection Generation — Execution Plan

## Overview
Two-phase execution after APPROVE:
1. **Phase A** — Fix D1–D7 JSON envelope violations in 5 controllers (envelope-only, no core logic rewrite)
2. **Phase B** — Generate `SINDOMON_API_v1.postman_collection.json` with all 29 endpoints

---

## Phase A: Envelope Fixes (D1–D7)

### Fix D1 — Auth.php (`application/controllers/Auth.php`)

| Line | Change |
|------|--------|
| 34 | Move `jwt_token` inside `data`. `"jwt_token" => $token` → `"data" => array("jwt_token" => $token, "user" => $check)` |
| 36 | `"data" => []` → `"data" => (object)[]` |
| 40 | `"data" => []` → `"data" => (object)[]` |

### Fix D2 — Role.php (`application/controllers/Role.php`)

| Line | Change |
|------|--------|
| 40 | `echo json_encode("Unauthorize")` → `http_response_code(401); echo json_encode(array("status"=>401,"message"=>"Unauthorized","data"=>(object)[]))` |
| 47 | Same replacement |
| 64 | Same replacement |
| 81 | Same replacement |

### Fix D3 — Role.php::delete()

| Line | Change |
|------|--------|
| 87 | `echo "DELETE"` → `echo json_encode(array("status"=>200,"message"=>"success","data"=>(object)[]))` |

### Fix D4 — Profile.php (`application/controllers/Profile.php`)

| Line | Change |
|------|--------|
| 33 | `echo json_encode("Unauthorize")` → `http_response_code(401); echo json_encode(array("status"=>401,"message"=>"Unauthorized","data"=>(object)[]))` |

### Fix D5 — Polda.php (`application/controllers/Polda.php`)

| Line | Change |
|------|--------|
| 34 | `echo json_encode("Unauthorize")` → `http_response_code(401); echo json_encode(array("status"=>401,"message"=>"Unauthorized","data"=>(object)[]))` |
| 51 | Same replacement |

### Fix D6 — Logistik.php::amunisi_post() (`application/controllers/Logistik.php`)

| Line | Change |
|------|--------|
| 218–220 | Add `"data" => (object)[]` to date validation error response |
| 252–253 | Add `"data" => (object)[]` to success response |

### Fix D7 — Logistik.php::satwa_post() (`application/controllers/Logistik.php`)

| Line | Change |
|------|--------|
| 353 | Add `"data" => (object)[]` to Content-Type error |
| 361 | Add `"data" => (object)[]` to JSON parse error |
| 377 | Add `"data" => (object)[]` to missing photo error |
| 385 | Add `"data" => (object)[]` to duplicate registration error |
| 400–401 | Add `"data" => (object)[]` to file save error |
| 425 | Add `"data" => (object)[]` to DB insert error |
| 435 | Add `"data" => (object)[]` to success response |

### Validation after Phase A
- Run `npx playwright test tests/api/ --reporter=list` — all 13 tests must still pass
- Manually verify no raw string `"Unauthorize"` remains anywhere in controllers: `rg '"Unauthorize"' application/controllers/`

---

## Phase B: Postman Collection Generation

### File
- `SINDOMON_API_v1.postman_collection.json` in project root
- Format: Postman Collection v2.1.0

### Variables
- `{{base_url}}` — default `http://localhost:8080`
- `{{token}}` — default empty, populated by Auth/Login tests

### Folder Structure (11 folders, 29 requests)

```
SINDOMON API v1
├── 🔐 Auth
│   ├── POST Login              — /api/v1/auth/login
│   │   Body: {"username":"admin","password":"admin123"}
│   │   Tests: pm.environment.set("token", pm.response.json().data.jwt_token)
│   ├── POST Register           — /api/v1/auth/insert
│   │   Body: {"username":"newuser","password":"pass123","roles_id":2}
│   └── GET All Users           — /api/v1/user
│
├── 🛡️ Role
│   ├── GET Roles               — /api/v1/role
│   ├── POST Role               — /api/v1/role
│   │   Body: {"role":"Operator"}
│   ├── PUT Role                — /api/v1/role
│   │   Body: {"id":1,"role":"Super Admin"}
│   └── DELETE Role             — /api/v1/role
│
├── 👤 Profile
│   └── GET Profile             — /api/v1/profile
│
├── 🏢 Master Data
│   ├── POST Polres             — /api/v1/master/polres
│   │   Body: {"nama_polres":"Polres Bogor","polda_id":1}
│   ├── PUT Polres              — /api/v1/master/polres/{{polres_id}}
│   │   Body: {"nama_polres":"Polres Updated","polda_id":1}
│   └── DELETE Polres           — /api/v1/master/polres/{{polres_id}}
│
├── 🌍 Polda
│   └── GET Polda               — /api/v1/polda
│
├── 👥 SDM
│   ├── GET Org Tree            — /api/v1/sdm/org-tree
│   ├── GET Personil            — /api/v1/sdm/personil?search=&polres_id=&status=
│   ├── POST Personil           — /api/v1/sdm/personil
│   │   Body: {"nrp":"88123456","nama_lengkap":"Test","pangkat_id":1,"jabatan_id":1}
│   ├── PUT Personil            — /api/v1/sdm/personil/{{personil_id}}
│   │   Body: {"nrp":"88123456","nama_lengkap":"Updated","pangkat_id":1,"jabatan_id":1}
│   └── POST Catat Hukum        — /api/v1/sdm/hukum
│       Body: {"personil_id":"...","klasifikasi":"Pidana Umum","status_hukum":"Dalam Penyelidikan","tanggal_mulai":"2026-07-19"}
│
├── 📋 Pengaduan
│   ├── GET Tiket               — /api/v1/pengaduan/tiket?status=&sumber=
│   └── PATCH Ubah Status       — /api/v1/pengaduan/tiket/{{id}}/status
│       Body: {"status":"In Progress"}
│
├── 📚 Knowledge Hub
│   └── GET Dokumen             — /api/v1/knowledge/dokumen?kategori=&search=
│
├── 🚨 Kamtibmas
│   └── POST Laporan            — /api/v1/kamtibmas/laporan
│       Body: {"deskripsi_kejadian":"...","level_kritis":"Aman","foto_tkp":"<base64>"}
│
├── 📄 DMS
│   ├── POST Surat              — /api/v1/dms/surat
│   │   Body: {"nomor_surat":"B/123","judul_surat":"Test","file_dokumen":"<base64>"}
│   ├── GET Inbox/Outbox        — /api/v1/dms/surat?tipe=inbox
│   ├── GET Download Surat      — /api/v1/dms/surat/{{surat_id}}/download
│   └── PATCH Read Surat        — /api/v1/dms/surat/{{surat_id}}/read
│
└── 🔫 Logistik
    ├── POST Senjata            — /api/v1/logistik/senjata
    │   Body: {"nomor_seri":"SN001","kategori_id":1,"tahun_pengadaan":"2026","status_kelayakan":"Laik","foto_fisik":"<base64>"}
    ├── POST Amunisi            — /api/v1/logistik/amunisi
    │   Body: {"kode_batch":"B001","kategori_id":1,"jumlah_butir":500,"tanggal_masuk":"2026-01-01","tanggal_kedaluwarsa":"2028-01-01"}
    ├── GET Amunisi             — /api/v1/logistik/amunisi?search=
    └── POST Satwa              — /api/v1/logistik/satwa
        Body: {"nomor_registrasi":"K9-001","jenis_satwa":"K9","nama_satwa":"Rex","nama_handler":"John","kualifikasi":"Patrol","foto_fisik":"<base64>"}
```

### Collection Details
- All requests that need auth include `Authorization: Bearer {{token}}` header
- Body is raw JSON for all POST/PUT/PATCH requests
- `Content-Type: application/json` on all requests
- Test scripts for Login auto-set `{{token}}` environment variable
- Example payloads match what existing controllers expect

---

## Execution Order
1. Edit Auth.php (D1)
2. Edit Role.php (D2, D3)
3. Edit Profile.php (D4)
4. Edit Polda.php (D5)
5. Edit Logistik.php (D6, D7)
6. Run `rg "Unauthorize" application/controllers/` — confirm 0 matches
7. Run Playwright tests — confirm all 13 pass
8. Generate `SINDOMON_API_v1.postman_collection.json`
9. Validate JSON with `jq . collection.json > /dev/null` or equivalent

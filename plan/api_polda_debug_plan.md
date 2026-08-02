# API Polda — Latitude & Longitude Missing in Response

> **Status:** Investigation Complete — Awaiting APPROVE to execute

## 1. Backend Data Flow Audit

### 1.1 The Flutter App's Expected Endpoint

The Postman collection (`SINDOMON_API_v1.postman_collection.json:264`) confirms the Flutter app calls:

```
GET {{base_url}}/api/v1/polda
```

### 1.2 Route Resolution — MISSING ROUTE

**File: `application/config/routes.php`**

A grep for `polda` in this file returns only 4 matches, all under the `/master/` prefix:

```php
// Lines 65-68
$route['api/v1/master/polda']['GET']           = 'master/polda_get';
$route['api/v1/master/polda']['POST']          = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']    = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE'] = 'master/polda_delete/$1';
```

**There is NO `$route['api/v1/polda']` entry anywhere in routes.php.**

This means `GET /api/v1/polda` has no explicit route mapping. In CodeIgniter 3 with the `.htaccess` rewrite rules active, a URI like `api/v1/polda` would be attempted as auto-routing:
- Segments: `[api, v1, polda]`
- CI3 Router checks `application/controllers/api/` subdirectory → does not exist
- Falls to 404 (the `$route['404_override']` is set to empty string)

**Result: `GET /api/v1/polda` will 404 with the current route configuration.**

### 1.3 Two Controllers Exist for Polda Data

#### Controller A: `Polda` — Unreachable (no route)

**File: `application/controllers/Polda.php:27-55`**

```php
public function get()
{
    $headers = $this->input->request_headers();
    if(isset($headers['Authorization'])){
        $authorization = $headers['Authorization'];
        $payload = jwt_decode($authorization);
        if ($payload === false) {
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
        } else {
            $data = $this->db->query("select * from tbl_polda")->result_array();
            $rows = array();
            for($i=0;$i<count($data);$i++){
                $rows[] = array(
                    "id" => $data[$i]['id'],
                    "nama_polda" => $data[$i]['nama_polda'],
                    "latitude" => $data[$i]['latitude'],     // ✅ INCLUDED
                    "longitude" => $data[$i]['longitude'],   // ✅ INCLUDED
                    "created_at" => $data[$i]['created_at'],
                    "polres" => $this->db->query("select * from tbl_polres where polda_id = '".$data[$i]['id']."'")->result_array(),
                );
            }
            echo json_encode(array("message"=> "success", "status" => 200 , "data" => $rows));
        }
    }else{
        http_response_code(401);
        echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
    }
}
```

**Key observation:** This method explicitly includes `latitude` and `longitude` in the response array. If this endpoint were reachable, it WOULD return the coordinates.

**Issues with this controller:**
- No route defined for it
- Uses `$this->input->request_headers()` (unreliable with PHP built-in server — the dev server)
- Uses raw `jwt_decode()` instead of the more robust `get_jwt_payload()` helper
- N+1 query problem (one query per Polda to fetch Polres)

#### Controller B: `Master::polda_get()` — Reachable at `/api/v1/master/polda`

**File: `application/controllers/Master.php:26-52`**

```php
public function polda_get()
{
    $payload = get_jwt_payload($this);
    if ($payload === null) {
        http_response_code(401);
        echo json_encode([
            'status' => 401,
            'message' => 'Token tidak ditemukan atau tidak valid.',
            'data' => (object)[]
        ]);
        return;
    }

    $rows = $this->db->get('tbl_polda')->result_array();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);

    http_response_code(200);
    echo json_encode([
        'status' => 200,
        'message' => 'Daftar Polda berhasil dimuat.',
        'data' => $rows
    ]);
}
```

**Key observation:** Uses `$this->db->get('tbl_polda')` which generates `SELECT * FROM tbl_polda`. NO column filtering — all columns including `latitude` and `longitude` should be in the response.

---

## 2. Root Cause Analysis

### Findings Matrix

| Aspect | `GET /api/v1/polda` (Postman) | `GET /api/v1/master/polda` (Active) |
|--------|-------------------------------|-------------------------------------|
| Route exists? | ❌ NO | ✅ YES |
| Controller | `Polda::get()` | `Master::polda_get()` |
| Auth pattern | `$this->input->request_headers()` + `jwt_decode()` | `get_jwt_payload($this)` (robust) |
| DB query | `SELECT * FROM tbl_polda` | `SELECT * FROM tbl_polda` |
| lat/lng in response? | ✅ Yes (explicitly mapped) | ✅ Yes (SELECT *, no filter) |

### Primary Root Cause: Missing Route

The route `$route['api/v1/polda']` was never added to `application/config/routes.php`. The `Polda` controller with its `get()` method exists but has no URL mapping. This appears to have happened during the refactor that consolidated Polda CRUD operations into the `Master` controller under the `/master/polda` path prefix — the old `GET /api/v1/polda` route was either omitted or removed.

The `Polda` controller's `get()` method already includes `latitude` and `longitude` in its response, so adding the route alone would fix the missing fields.

### Secondary Issue: Polda Controller Auth Is Fragile

The `Polda` controller uses `$this->input->request_headers()` which is known to be unreliable with PHP's built-in server (`php -S`). Every other controller in the project has been updated to use the `get_jwt_payload($ci)` helper (from `application/helpers/jwt_helper.php`) which bypasses CI3's input class and reads headers directly via `getallheaders()` or `$_SERVER`. If the route is added without also modernizing the Polda controller's auth, the endpoint will fail on the dev server.

---

## 3. Refactor Plan

### Task 1: Add Missing Route

**File: `application/config/routes.php`**

Add the `GET /api/v1/polda` route entry. Insert it near the other polda-related routes (around line 64):

```php
// After the existing master/polda comment on line 64, add:
$route['api/v1/polda']['GET']                      = 'polda/get';
```

Place it right before the existing Master polda routes:

```php
// Polda (legacy — used by Flutter app)
$route['api/v1/polda']['GET']                      = 'polda/get';
// Master / Polda + Polres
$route['api/v1/master/polda']['GET']               = 'master/polda_get';
$route['api/v1/master/polda']['POST']              = 'master/polda_post';
$route['api/v1/master/polda/(:num)']['PUT']        = 'master/polda_put/$1';
$route['api/v1/master/polda/(:num)']['DELETE']     = 'master/polda_delete/$1';
```

### Task 2: Modernize Polda Controller Auth

**File: `application/controllers/Polda.php`**

Replace the `get()` method's auth handling to use the project-standard `get_jwt_payload()` helper and `Jwt` library (matching the pattern used by `Master`, `Sdm`, `Dms`, `Kamtibmas`, `Pengaduan`, etc.).

**Before (lines 27-55):**
```php
public function get()
{
    $headers = $this->input->request_headers();
    if(isset($headers['Authorization'])){
        $authorization = $headers['Authorization'];
        $payload = jwt_decode($authorization);
         if ($payload === false) {
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
         } else {
            $data = $this->db->query("select * from tbl_polda")->result_array();
            $rows = array();
            for($i=0;$i<count($data);$i++){
                $rows[] = array(
                    "id" => $data[$i]['id'],
                    "nama_polda" => $data[$i]['nama_polda'],
                    "latitude" => $data[$i]['latitude'],
                    "longitude" => $data[$i]['longitude'],
                    "created_at" => $data[$i]['created_at'],
                    "polres" => $this->db->query("select * from tbl_polres where polda_id = '".$data[$i]['id']."'")->result_array(),
                );
            }
            echo json_encode(array("message"=> "success", "status" => 200 , "data" => $rows));
         }
    }else{
        http_response_code(401);
        echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
    }
}
```

**After:**
```php
public function get()
{
    $payload = get_jwt_payload($this);
    if ($payload === null) {
        http_response_code(401);
        echo json_encode([
            'status' => 401,
            'message' => 'Token tidak ditemukan atau tidak valid.',
            'data' => (object)[]
        ]);
        return;
    }

    $data = $this->db->query("select * from tbl_polda")->result_array();
    $rows = array();
    for ($i = 0; $i < count($data); $i++) {
        $rows[] = array(
            "id"         => (int) $data[$i]['id'],
            "nama_polda" => $data[$i]['nama_polda'],
            "latitude"   => $data[$i]['latitude'],
            "longitude"  => $data[$i]['longitude'],
            "created_at" => $data[$i]['created_at'],
            "polres"     => $this->db->query("select * from tbl_polres where polda_id = '" . $this->db->escape_str($data[$i]['id']) . "'")->result_array(),
        );
    }

    http_response_code(200);
    echo json_encode([
        'status'  => 200,
        'message' => 'success',
        'data'    => $rows
    ]);
}
```

**What changed:**
- Auth: `$this->input->request_headers()` → `get_jwt_payload($this)` (project-standard helper, works reliably with PHP built-in server)
- `jwt_decode()` → `get_jwt_payload()` (decodes via Jwt library class, not standalone function)
- `id` cast to `(int)` (Flutter compatibility — matches pattern in `Master::polda_get()`)
- `$this->db->escape_str()` on the inner query parameter (prevents SQL injection in the nested Polres query)
- Removed unreachable `else` branch (the `if ($payload === null)` check already returns early)

### Task 3: Update Constructor to Load JWT Library

The Polda controller's constructor already loads `jwt` config, `jwt` helper, and `url` helper, but it **does not load the `Jwt` library** (`$this->load->library('jwt')`). The `get_jwt_payload()` helper calls `$ci->jwt->decode()` which requires the Jwt library to be loaded.

Add `$this->load->library('jwt');` to the constructor:

**File: `application/controllers/Polda.php:6-25`**

```php
public function __construct() {
    parent::__construct();

    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: false");

    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    $this->config->load('jwt');
    $this->load->helper('jwt');
    $this->load->helper('url');
    $this->load->library('session');
    $this->load->helper('uuid');
    $this->load->helper('string');
    $this->load->library('jwt');        // ← ADD THIS LINE
}
```

---

## 4. Verification

After implementing the above changes, verify:

### 4.1 Route exists and resolves
```bash
# Check that the route is registered
grep "api/v1/polda" application/config/routes.php
# Expected: $route['api/v1/polda']['GET'] = 'polda/get';
```

### 4.2 Start dev server and test
```bash
# Terminal 1: Start dev server
php -S localhost:8080 tests/router.php

# Terminal 2: Get JWT token, then call endpoint
# Login to get a token
curl -s -X POST http://localhost:8080/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'

# Use the token from the response to call the polda endpoint
curl -s -X GET http://localhost:8080/api/v1/polda \
  -H "Authorization: Bearer <token>"
```

### 4.3 Verify latitude and longitude are present
Check the JSON response for every Polda object:
```json
{
  "id": 1,
  "nama_polda": "Polda Aceh",
  "latitude": "5.550000",        // ✅ Must be present
  "longitude": "95.316666",      // ✅ Must be present
  "created_at": "...",
  "polres": [...]
}
```

### 4.4 Run the Playwright E2E test suite
```bash
npm test
```
All tests should continue to pass (the `Master::polda_get()` route is unchanged).

---

## 5. Summary of Files to Modify

| File | Change |
|------|--------|
| `application/config/routes.php` | Add `$route['api/v1/polda']['GET'] = 'polda/get';` |
| `application/controllers/Polda.php` (constructor) | Add `$this->load->library('jwt');` |
| `application/controllers/Polda.php` (`get()` method) | Replace auth with `get_jwt_payload()`, add `(int)` cast, add `escape_str()` |

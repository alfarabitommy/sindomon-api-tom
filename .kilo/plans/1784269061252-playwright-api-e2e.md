# Plan: Playwright API E2E Test Suite for Sindomon

## Goal
Comprehensive Playwright API test suite that validates auth JWT integrity, SDM module flows (create, duplicate, hukum, cross-region security), performance (<500ms), and JSON envelope compliance. Zero browser UI — `APIRequestContext` only.

## Files to Create

| File | Purpose |
|---|---|
| `package.json` (root) | Playwright dependency + test script |
| `playwright.config.ts` | Config: webServer `php -S`, no browser projects |
| `tests/seed.php` | Pre-flight DB seeding (standalone PDO, no CI3 bootstrap) |
| `tests/api/sindomon_e2e.spec.ts` | Main E2E test suite |

## Prerequisites

- Node.js ≥ 16 (for Playwright)
- PHP ≥ 7.3 (for `php -S` and seed script)
- MariaDB running on 127.0.0.1:3306, db `sindomondb`, user `root`/`root`
- Phase 0 patching applied to DB (confirmed: all tables + `tbl_users.polda_id` exist)

---

## Step 1: `package.json`

Root-level alongside `composer.json`. Installs Playwright as `devDependency`. Adds `test` script:

```json
{
  "name": "sindomon-api-tests",
  "private": true,
  "scripts": {
    "test": "npx playwright test"
  },
  "devDependencies": {
    "@playwright/test": "^1.50.0"
  }
}
```

After creation: `npm install` to fetch Playwright. No browser binaries needed (`npx playwright install` NOT required for API-only mode).

---

## Step 2: `playwright.config.ts`

```ts
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30000,
  retries: 1,
  webServer: {
    command: 'php -S localhost:8080',
    cwd: '.',
    port: 8080,
    reuseExistingServer: true,  // don't fail if server already running
    timeout: 10000,
  },
  use: {
    baseURL: 'http://localhost:8080',
    extraHTTPHeaders: {
      'Content-Type': 'application/json',
    },
  },
  // No browser projects — API-only testing
  projects: [
    {
      name: 'api',
      testMatch: '**/*.spec.ts',
    },
  ],
});
```

Key decisions:
- `reuseExistingServer: true` — tests can run against already-running server or auto-start one
- `baseURL: 'http://localhost:8080'` — all `/api/v1/...` paths are relative to this
- API-only — no `chromium`/`firefox` browser projects configured

---

## Step 3: `tests/seed.php`

Standalone PDO script. No CodeIgniter bootstrap. Idempotent via `INSERT IGNORE`. Generates bcrypt hash for test user at runtime.

```php
#!/usr/bin/env php
<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'root';
$db   = getenv('DB_NAME') ?: 'sindomondb';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. Create Operator Polda test user (role_id=2, polda_id=12)
$hash = password_hash('operator123', PASSWORD_DEFAULT);
$pdo->exec("INSERT IGNORE INTO tbl_users (username, password, roles_id, polda_id, uuid, token, expired, created_at)
    VALUES ('operator_test', " . $pdo->quote($hash) . ", 2, 12, UUID(), 'testtoken', '30', NOW())");
echo "  ✓ Test user 'operator_test' (role_id=2, polda_id=12) seeded\n";

// 2. Reference data: tbl_pangkat (if empty)
$pdo->exec("INSERT IGNORE INTO tbl_pangkat (pangkat_id, nama_pangkat, created_at) VALUES (1, 'BRIPDA', NOW())");
$pdo->exec("INSERT IGNORE INTO tbl_pangkat (pangkat_id, nama_pangkat, created_at) VALUES (2, 'BRIPTU', NOW())");
echo "  ✓ tbl_pangkat reference data seeded\n";

// 3. Reference data: tbl_jabatan (if empty)
$pdo->exec("INSERT IGNORE INTO tbl_jabatan (jabatan_id, nama_jabatan, formasi_ideal, parent_id, created_at)
    VALUES (1, 'Staff', 10, NULL, NOW())");
$pdo->exec("INSERT IGNORE INTO tbl_jabatan (jabatan_id, nama_jabatan, formasi_ideal, parent_id, created_at)
    VALUES (2, 'Kanit', 2, 1, NOW())");
echo "  ✓ tbl_jabatan reference data seeded\n";

// 4. Cross-Region Trap: personil in polda_id=15 (different from test user's polda_id=12)
$pdo->exec("INSERT IGNORE INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id, created_at)
    VALUES ('00000000-0000-0000-0000-00000000dead', '99999999', 'TRAP_PERSONIL_POLDA15', 1, 1, 'Aktif', 15, NULL, NOW())");
echo "  ✓ Cross-region trap personil seeded (nrp=99999999, polda_id=15)\n";

echo "\nSeed complete. Ready for tests.\n";
```

Execution: `php tests/seed.php` from project root.

---

## Step 4: `tests/api/sindomon_e2e.spec.ts`

### Test File Constants

```ts
const PASSWORD_OPERATOR = 'operator123';
const USERNAME_OPERATOR = 'operator_test';
const CROSS_REGION_PERSONIL_ID = '00000000-0000-0000-0000-00000000dead';
const MAX_LATENCY_MS = 500;
```

### Helper Utilities (in file, no external deps)

```ts
// JWT base64url decode (no external library needed)
function base64UrlDecode(str: string): string {
  str = str.replace(/-/g, '+').replace(/_/g, '/');
  while (str.length % 4) str += '=';
  return Buffer.from(str, 'base64').toString('utf8');
}

function decodeJwtPayload(token: string): Record<string, any> {
  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('Invalid JWT format');
  return JSON.parse(base64UrlDecode(parts[1]));
}

// Envelope assertion
function assertEnvelope(body: any, path: string): void {
  expect(body, `${path}: missing 'status'`).toHaveProperty('status');
  expect(body, `${path}: missing 'message'`).toHaveProperty('message');
  // Note: login response has jwt_token at root, so 'data' is optional
}

// Performance assertion
function assertLatency(startMs: number, path: string): void {
  const duration = Date.now() - startMs;
  expect(duration, `${path}: response took ${duration}ms, exceeding ${MAX_LATENCY_MS}ms`).toBeLessThan(MAX_LATENCY_MS);
}
```

### `beforeAll` — Seed DB

```ts
test.beforeAll(async () => {
  // Run PHP seed script
  const { spawnSync } = require('child_process');
  const result = spawnSync('php', ['tests/seed.php'], { cwd: process.cwd(), timeout: 10000 });
  if (result.status !== 0) {
    console.error(result.stderr.toString());
    throw new Error('Seed failed');
  }
  console.log(result.stdout.toString());
});
```

### `beforeEach` — Fresh context per test

```ts
let apiContext: APIRequestContext;
let operatorJwt: string;
let createdPersonilId: string;

test.beforeEach(async ({ request }) => {
  apiContext = request;
});
```

Note: Not using `test.beforeEach` for the JWT login — it's tested explicitly in Test 1. Subsequent tests use a locally stored `operatorJwt` variable.

### Test 1: Auth Login + JWT Payload Verification

```
Name: "Auth: Login returns valid JWT with role_id and polda_id"
Steps:
  1. POST /api/v1/auth/login { username: USERNAME_OPERATOR, password: PASSWORD_OPERATOR }
  2. Assert status 200
  3. Assert response has jwt_token key
  4. Assert latency < 500ms
  5. Assert envelope: status=200, message="success"
  6. Base64url-decode jwt_token payload
  7. Assert decoded payload.role_id === 2
  8. Assert decoded payload.polda_id === 12
  9. Assert decoded payload has 'uid', 'username', 'iat', 'exp'
  10. Assert exp > iat (token not pre-expired)
  11. Store jwt_token as operatorJwt for subsequent tests
```

Edge case: `data` field in login response is the user row separately from `jwt_token`. Assert it exists but don't require a specific structure.

### Test 2: SDM Personil — Create (201) + Duplicate NRP (422)

```
Name: "SDM: Create personil returns 201, duplicate NRP returns 422"
Prerequisites:
  - operatorJwt from Test 1
  - generated unique NRP (timestamp-based to avoid collision across test runs)

Steps:
  Generate unique NRP: const nrp = `88${Date.now().toString().slice(-6)}`;

  Sub-test 2a — Create:
    1. POST /api/v1/sdm/personil {
         nrp, nama_lengkap: "Test Personel",
         pangkat_id: 1, jabatan_id: 1, polres_id: null
       }
       Header: Authorization: Bearer ${operatorJwt}
    2. Assert status === 201
    3. Assert latency < 500ms
    4. Assert envelope: status=201, message includes "berhasil didaftarkan"
    5. Assert data.personil_id is a non-empty string (UUID v4)
    6. Store createdPersonilId = data.personil_id

  Sub-test 2b — Duplicate NRP:
    1. POST /api/v1/sdm/personil {
         nrp, nama_lengkap: "Duplicate NR",
         pangkat_id: 1, jabatan_id: 1
       }
       Header: Authorization: Bearer ${operatorJwt}
    2. Assert status === 422
    3. Assert latency < 500ms
    4. Assert envelope: status=422
    5. Assert message === "Pendaftaran gagal. NRP sudah terdaftar di sistem."
```

### Test 3: SDM Hukum — Create (201) + Cross-Region (403)

```
Name: "SDM: Catat hukum returns 201 for valid personil, 403 for cross-region"
Prerequisites:
  - operatorJwt from Test 1
  - createdPersonilId from Test 2

  Sub-test 3a — Valid create:
    1. POST /api/v1/sdm/hukum {
         personil_id: createdPersonilId,
         klasifikasi: "Pidana Umum",
         status_hukum: "Dalam Penyelidikan",
         tanggal_mulai: "2026-07-17",
         deskripsi_kasus: "Dugaan penyalahgunaan wewenang"
       }
       Header: Authorization: Bearer ${operatorJwt}
    2. Assert status === 201
    3. Assert latency < 500ms
    4. Assert envelope: status=201, message includes "berhasil ditambahkan"

  Sub-test 3b — Cross-region rejection:
    1. POST /api/v1/sdm/hukum {
         personil_id: CROSS_REGION_PERSONIL_ID,
         klasifikasi: "Sidang Disiplin",
         status_hukum: "Menunggu Sidang",
         tanggal_mulai: "2026-07-17"
       }
       Header: Authorization: Bearer ${operatorJwt}
    2. Assert status === 403
    3. Assert latency < 500ms
    4. Assert envelope: status=403
    5. Assert message === "Akses ditolak. Personel tidak ditemukan atau berada di luar yurisdiksi Anda."
```

### Test 4: Auth — Admin login JWT verification

```
Name: "Auth: Admin login confirms role_id=1 and polda_id exists"
Steps:
  1. POST /api/v1/auth/login { username: "admin", password: "admin123" }
  2. Assert status 200
  3. Assert latency < 500ms
  4. Decode JWT
  5. Assert payload.role_id === 1
  6. Assert payload has 'polda_id' key (value may be 0 — admin has no regional jurisdiction)
```

### `afterAll` — Cleanup (optional, non-blocking)

```ts
test.afterAll(async () => {
  // Clean up test personil created during test run 
  // (soft cleanup: use a unique nrp prefix so test data is identifiable)
  // For simplicity, skip explicit cleanup — test uses unique NRP per run.
});
```

---

## Test Dependency Graph

```
beforeAll (seed DB)
  └─ Test 1: Auth Login (operator) → produces operatorJwt
       ├─ Test 2: SDM Personil Create → produces createdPersonilId
       │    └─ Test 3: SDM Hukum (depends on createdPersonilId from Test 2)
       └─ Test 4: Auth Login (admin) — independent
```

Tests 1 → 2 → 3 form a chain (personil_id used in hukum test). Test 4 is independent.

Use `test.describe.serial` for Tests 1-3 to enforce ordering. Test 4 can be parallel.

```ts
test.describe.serial('Sindomon E2E API Flow', () => {
  test('Auth: Login returns valid JWT with role_id and polda_id', ...);
  test('SDM: Create personil returns 201, duplicate NRP returns 422', ...);
  test('SDM: Catat hukum returns 201, cross-region returns 403', ...);
});

test('Auth: Admin login JWT verification', ...);
```

---

## Execution

```bash
# 1. Install deps
npm install

# 2. Run tests (auto-starts php -S server if not running)
npm test

# Or directly:
npx playwright test
```

---

## Failure Modes & Edge Cases

| Scenario | Expected Behavior |
|---|---|
| Seed.php already ran | `INSERT IGNORE` — idempotent, no error |
| PHP server not running | `webServer` config auto-starts it |
| DB connection fails | Seed script exits non-zero, `beforeAll` throws, tests abort |
| JWT decode fails (malformed token) | `decodeJwtPayload` throws descriptive error |
| Admin password changed | Test 4 fails with 400 "password not match" |
| NRP collision from previous test run | Unique NRP with `Date.now()` timestamp avoids this |
| Server returns non-JSON (PHP error) | Playwright `response.json()` throws — clear error message |

---

## Design Decisions

1. **No external JWT library** — `Buffer.from` base64url decode + `JSON.parse`. The JWT is HS256 and we only assert payload claims (not signature), so no HMAC verification needed.
2. **`test.describe.serial`** — Tests 1-3 share state (JWT, personil_id). Parallelizing would require duplicating the login call. Serial is acceptable for 4 tests.
3. **Root `package.json`** — Alongside `composer.json`. Avoids polluting `.kilo/` with test concerns.
4. **No `globalSetup`** — Seed done in `beforeAll` for debuggability. Playwright `globalSetup` errors are opaque.
5. **Seed via PHP PDO** — Avoids `mysql` CLI dependency. PHP is already required for the server. Uses env vars for DB credentials (falls back to hardcoded defaults matching `database.php`).
6. **Login response envelope exception** — Login returns `{"status", "message", "jwt_token", "data"}`. The `jwt_token` at root level is a known deviation from `{"status", "message", "data"}`. Asserted explicitly in Test 1.

---

## Validation Checklist

- [ ] `npm install` succeeds
- [ ] `php tests/seed.php` runs without errors
- [ ] `npx playwright test` passes all 4 tests
- [ ] Each test has latency assertion < 500ms
- [ ] Each test validates JSON envelope structure
- [ ] JWT payloads contain `role_id` and `polda_id`
- [ ] 422 duplicate NRP returns exact message string
- [ ] 403 cross-region returns exact message string
- [ ] Test cleanup does not leave open PHP server process

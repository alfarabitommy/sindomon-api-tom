# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: api/master_polres.spec.ts >> Master Polres API >> PUT /api/v1/master/polres/:id — Success (200)
- Location: tests/api/master_polres.spec.ts:92:7

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: 200
Received: 409
```

# Test source

```ts
  3   | 
  4   | const MAX_LATENCY_MS = 1000;
  5   | 
  6   | function assertEnvelope(body: Record<string, unknown>, path: string) {
  7   |   expect(body, `${path}: missing 'status'`).toHaveProperty('status');
  8   |   expect(body, `${path}: missing 'message'`).toHaveProperty('message');
  9   | }
  10  | 
  11  | function assertLatency(startMs: number, path: string, maxMs = MAX_LATENCY_MS) {
  12  |   const duration = Date.now() - startMs;
  13  |   expect(
  14  |     duration,
  15  |     `${path}: response took ${duration}ms, exceeding ${maxMs}ms threshold`,
  16  |   ).toBeLessThan(maxMs);
  17  | }
  18  | 
  19  | test.describe.serial('Master Polres API', () => {
  20  |   let adminJwt: string;
  21  |   let operatorJwt: string;
  22  |   let createdPolresId: number;
  23  | 
  24  |   test('Auth: Admin login returns role_id=1', async ({ request }) => {
  25  |     const start = Date.now();
  26  |     const res = await request.post('/api/v1/auth/login', {
  27  |       data: { username: 'admin', password: 'admin123' },
  28  |     });
  29  |     assertLatency(start, '/auth/login (admin)');
  30  |     expect(res.status()).toBe(200);
  31  | 
  32  |     const body = await res.json();
  33  |     expect(body.data).toHaveProperty('jwt_token');
  34  |     assertEnvelope(body, '/auth/login (admin)');
  35  |     adminJwt = body.data.jwt_token;
  36  |   });
  37  | 
  38  |   test('Auth: Operator login returns role_id=2', async ({ request }) => {
  39  |     const start = Date.now();
  40  |     const res = await request.post('/api/v1/auth/login', {
  41  |       data: { username: 'operator_test', password: 'operator123' },
  42  |     });
  43  |     assertLatency(start, '/auth/login (operator)');
  44  |     expect(res.status()).toBe(200);
  45  | 
  46  |     const body = await res.json();
  47  |     expect(body.data).toHaveProperty('jwt_token');
  48  |     operatorJwt = body.data.jwt_token;
  49  |   });
  50  | 
  51  |   test('POST /api/v1/master/polres — Success (201)', async ({ request }) => {
  52  |     const start = Date.now();
  53  |     const res = await request.post('/api/v1/master/polres', {
  54  |       headers: { Authorization: `Bearer ${adminJwt}` },
  55  |       data: {
  56  |         nama_polres: 'Polres Test Sukses',
  57  |         polda_id: 1,
  58  |       },
  59  |     });
  60  |     assertLatency(start, 'POST /master/polres (success)');
  61  | 
  62  |     expect(res.status()).toBe(201);
  63  |     const body = await res.json();
  64  |     assertEnvelope(body, 'POST /master/polres (success)');
  65  |     expect(body.status).toBe(201);
  66  |     expect(body.message).toBe('Data wilayah polres berhasil ditambahkan.');
  67  |     expect(body.data).toHaveProperty('polres_id');
  68  |     expect(typeof body.data.polres_id).toBe('number');
  69  |     expect(body.data.polres_id).toBeGreaterThan(0);
  70  |     createdPolresId = body.data.polres_id;
  71  |   });
  72  | 
  73  |   test('POST /api/v1/master/polres — Integrity Trap (422)', async ({ request }) => {
  74  |     const start = Date.now();
  75  |     const res = await request.post('/api/v1/master/polres', {
  76  |       headers: { Authorization: `Bearer ${adminJwt}` },
  77  |       data: {
  78  |         nama_polres: 'Polres Fake',
  79  |         polda_id: 9999,
  80  |       },
  81  |     });
  82  |     assertLatency(start, 'POST /master/polres (integrity trap)');
  83  | 
  84  |     expect(res.status()).toBe(422);
  85  |     const body = await res.json();
  86  |     assertEnvelope(body, 'POST /master/polres (integrity trap)');
  87  |     expect(body.status).toBe(422);
  88  |     expect(body.message).toBe('Validasi gagal. Induk Polda tidak ditemukan.');
  89  |     expect(body.data).toEqual({});
  90  |   });
  91  | 
  92  |   test('PUT /api/v1/master/polres/:id — Success (200)', async ({ request }) => {
  93  |     const start = Date.now();
  94  |     const res = await request.put(`/api/v1/master/polres/${createdPolresId}`, {
  95  |       headers: { Authorization: `Bearer ${adminJwt}` },
  96  |       data: {
  97  |         nama_polres: 'Polres Updated',
  98  |         polda_id: 1,
  99  |       },
  100 |     });
  101 |     assertLatency(start, 'PUT /master/polres (success)');
  102 | 
> 103 |     expect(res.status()).toBe(200);
      |                          ^ Error: expect(received).toBe(expected) // Object.is equality
  104 |     const body = await res.json();
  105 |     assertEnvelope(body, 'PUT /master/polres (success)');
  106 |     expect(body.status).toBe(200);
  107 |     expect(body.message).toBe('Data polres berhasil diperbarui.');
  108 |     expect(body.data).toEqual({});
  109 |   });
  110 | 
  111 |   test('PUT /api/v1/master/polres/:id — Integrity Trap (422)', async ({ request }) => {
  112 |     const start = Date.now();
  113 |     const res = await request.put(`/api/v1/master/polres/${createdPolresId}`, {
  114 |       headers: { Authorization: `Bearer ${adminJwt}` },
  115 |       data: {
  116 |         nama_polres: 'Polres Fake Update',
  117 |         polda_id: 9999,
  118 |       },
  119 |     });
  120 |     assertLatency(start, 'PUT /master/polres (integrity trap)');
  121 | 
  122 |     expect(res.status()).toBe(422);
  123 |     const body = await res.json();
  124 |     assertEnvelope(body, 'PUT /master/polres (integrity trap)');
  125 |     expect(body.status).toBe(422);
  126 |     expect(body.message).toBe('Validasi gagal. Induk Polda tidak ditemukan.');
  127 |     expect(body.data).toEqual({});
  128 |   });
  129 | 
  130 |   test('POST /api/v1/master/polres — Role Trap (403)', async ({ request }) => {
  131 |     const start = Date.now();
  132 |     const res = await request.post('/api/v1/master/polres', {
  133 |       headers: { Authorization: `Bearer ${operatorJwt}` },
  134 |       data: {
  135 |         nama_polres: 'Polres Banned',
  136 |         polda_id: 1,
  137 |       },
  138 |     });
  139 |     assertLatency(start, 'POST /master/polres (role trap)');
  140 | 
  141 |     expect(res.status()).toBe(403);
  142 |     const body = await res.json();
  143 |     assertEnvelope(body, 'POST /master/polres (role trap)');
  144 |     expect(body.status).toBe(403);
  145 |     expect(body.message).toBe('Akses ditolak. Anda tidak memiliki otoritas Super Admin.');
  146 |     expect(body.data).toEqual({});
  147 |   });
  148 | 
  149 |   test('DELETE /api/v1/master/polres/:id — Conflict Trap (409)', async ({ request }) => {
  150 |     const trapNrp = '88TRAP99';
  151 | 
  152 |     execSync(
  153 |       'mysql -u root -proot sindomondb -e "CREATE TABLE IF NOT EXISTS tbl_personil (personil_id VARCHAR(36) COLLATE utf8mb4_unicode_ci NOT NULL, nrp VARCHAR(20) NOT NULL, nama_lengkap VARCHAR(255) NOT NULL, pangkat_id INT(11) DEFAULT NULL, jabatan_id INT(11) DEFAULT NULL, status_aktif VARCHAR(50) DEFAULT NULL, polda_id INT(11) DEFAULT NULL, polres_id INT(11) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (personil_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"',
  154 |       { cwd: process.cwd(), timeout: 10000 }
  155 |     );
  156 |     execSync(
  157 |       'mysql -u root -proot sindomondb -e "ALTER TABLE tbl_personil ADD CONSTRAINT fk_personil_polres FOREIGN KEY (polres_id) REFERENCES tbl_polres(polres_id) ON DELETE RESTRICT" 2>/dev/null || true',
  158 |       { cwd: process.cwd(), timeout: 10000 }
  159 |     );
  160 |     execSync(
  161 |       `mysql -u root -proot sindomondb -e "INSERT INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id) VALUES ('00000000-0000-0000-0000-00000000trap', '${trapNrp}', 'TRAP_PERSONIL_POLRES', 1, 1, 'Aktif', 1, ${createdPolresId})"`,
  162 |       { cwd: process.cwd(), timeout: 10000 }
  163 |     );
  164 | 
  165 |     const start = Date.now();
  166 |     const res = await request.delete(`/api/v1/master/polres/${createdPolresId}`, {
  167 |       headers: { Authorization: `Bearer ${adminJwt}` },
  168 |     });
  169 |     assertLatency(start, 'DELETE /master/polres (conflict 409)');
  170 | 
  171 |     expect(res.status()).toBe(409);
  172 |     const body = await res.json();
  173 |     assertEnvelope(body, 'DELETE /master/polres (conflict 409)');
  174 |     expect(body.status).toBe(409);
  175 |     expect(body.message).toBe('Polres tidak dapat dihapus karena masih menaungi personel aktif (Restricted by System).');
  176 |     expect(body.data).toEqual({});
  177 |   });
  178 | 
  179 |   test('DELETE /api/v1/master/polres/:id — Success (200)', async ({ request }) => {
  180 |     execSync(
  181 |       `mysql -u root -proot sindomondb -e "DELETE FROM tbl_personil WHERE nrp = '88TRAP99'"`,
  182 |       { cwd: process.cwd(), timeout: 10000 }
  183 |     );
  184 | 
  185 |     const start = Date.now();
  186 |     const res = await request.delete(`/api/v1/master/polres/${createdPolresId}`, {
  187 |       headers: { Authorization: `Bearer ${adminJwt}` },
  188 |     });
  189 |     assertLatency(start, 'DELETE /master/polres (success 200)');
  190 | 
  191 |     expect(res.status()).toBe(200);
  192 |     const body = await res.json();
  193 |     assertEnvelope(body, 'DELETE /master/polres (success 200)');
  194 |     expect(body.status).toBe(200);
  195 |     expect(body.message).toBe('Data polres berhasil dihapus.');
  196 |     expect(body.data).toEqual({});
  197 |   });
  198 | });
  199 | 
```
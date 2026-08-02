# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: api/seeder_master.spec.ts >> Phase 2 — Transactional Seed Triggers >> GET /api/v1/sdm/org-tree — Dirsamapta shows is_vacancy_alert: true
- Location: tests/api/seeder_master.spec.ts:94:7

# Error details

```
Error: expect(received).toBe(expected) // Object.is equality

Expected: true
Received: false
```

# Test source

```ts
  11  | 
  12  | function assertLatency(startMs: number, path: string, maxMs = MAX_LATENCY_MS) {
  13  |   const duration = Date.now() - startMs;
  14  |   expect(
  15  |     duration,
  16  |     `${path}: response took ${duration}ms, exceeding ${maxMs}ms threshold`,
  17  |   ).toBeLessThan(maxMs);
  18  | }
  19  | 
  20  | test.describe.serial('Seeder Master Data', () => {
  21  |   let adminJwt: string;
  22  | 
  23  |   test.beforeAll(() => {
  24  |     execSync('php index.php seeder run', {
  25  |       cwd: process.cwd(),
  26  |       timeout: 30000,
  27  |       stdio: 'inherit',
  28  |     });
  29  |   });
  30  | 
  31  |   test('Auth: Admin login returns valid JWT', async ({ request }) => {
  32  |     const start = Date.now();
  33  |     const res = await request.post('/api/v1/auth/login', {
  34  |       data: { username: 'admin', password: 'admin123' },
  35  |     });
  36  |     assertLatency(start, '/auth/login (admin)');
  37  |     expect(res.status()).toBe(200);
  38  | 
  39  |     const body = await res.json();
  40  |     expect(body.data).toHaveProperty('jwt_token');
  41  |     assertEnvelope(body, '/auth/login (admin)');
  42  |     adminJwt = body.data.jwt_token;
  43  |   });
  44  | 
  45  |   test('GET /api/v1/master/wilayah — returns 38 Polda', async ({ request }) => {
  46  |     const start = Date.now();
  47  |     const res = await request.get('/api/v1/master/wilayah', {
  48  |       headers: { Authorization: `Bearer ${adminJwt}` },
  49  |     });
  50  |     assertLatency(start, 'GET /master/wilayah');
  51  |     expect(res.status()).toBe(200);
  52  | 
  53  |     const body = await res.json();
  54  |     assertEnvelope(body, 'GET /master/wilayah');
  55  |     expect(body.status).toBe(200);
  56  |     expect(Array.isArray(body.data)).toBe(true);
  57  |     expect(body.data).toHaveLength(38);
  58  | 
  59  |     for (const polda of body.data) {
  60  |       expect(polda).toHaveProperty('id');
  61  |       expect(polda).toHaveProperty('nama_polda');
  62  |       expect(polda).toHaveProperty('latitude');
  63  |       expect(polda).toHaveProperty('longitude');
  64  |       expect(polda).toHaveProperty('polres_jajaran');
  65  |       expect(Array.isArray(polda.polres_jajaran)).toBe(true);
  66  |       expect(polda.polres_jajaran).toHaveLength(2);
  67  |     }
  68  |   });
  69  | });
  70  | 
  71  | test.describe.serial('Phase 2 — Transactional Seed Triggers', () => {
  72  |   let adminJwt: string;
  73  | 
  74  |   function findNode(nodes: any[], nama: string): any | null {
  75  |     for (const n of nodes) {
  76  |       if (n.nama_jabatan === nama) return n;
  77  |       if (n.bawahan && n.bawahan.length > 0) {
  78  |         const found = findNode(n.bawahan, nama);
  79  |         if (found) return found;
  80  |       }
  81  |     }
  82  |     return null;
  83  |   }
  84  | 
  85  |   test('Auth: Admin login', async ({ request }) => {
  86  |     const res = await request.post('/api/v1/auth/login', {
  87  |       data: { username: 'admin', password: 'admin123' },
  88  |     });
  89  |     expect(res.status()).toBe(200);
  90  |     const body = await res.json();
  91  |     adminJwt = body.data.jwt_token;
  92  |   });
  93  | 
  94  |   test('GET /api/v1/sdm/org-tree — Dirsamapta shows is_vacancy_alert: true', async ({ request }) => {
  95  |     const start = Date.now();
  96  |     const res = await request.get('/api/v1/sdm/org-tree', {
  97  |       headers: { Authorization: `Bearer ${adminJwt}` },
  98  |     });
  99  |     assertLatency(start, 'GET /sdm/org-tree (vacancy alert)');
  100 |     expect(res.status()).toBe(200);
  101 | 
  102 |     const body = await res.json();
  103 |     assertEnvelope(body, 'GET /sdm/org-tree');
  104 |     expect(body.status).toBe(200);
  105 |     expect(body.data).toHaveProperty('struktur');
  106 |     expect(Array.isArray(body.data.struktur)).toBe(true);
  107 |     expect(body.data.struktur.length).toBeGreaterThan(0);
  108 | 
  109 |     const dirsamapta = findNode(body.data.struktur, 'Dirsamapta');
  110 |     expect(dirsamapta).not.toBeNull();
> 111 |     expect(dirsamapta.is_vacancy_alert).toBe(true);
      |                                         ^ Error: expect(received).toBe(expected) // Object.is equality
  112 |     expect(dirsamapta.formasi_ideal).toBe(1);
  113 |     expect(dirsamapta.jumlah_riil).toBe(0);
  114 |   });
  115 | });
  116 | 
```
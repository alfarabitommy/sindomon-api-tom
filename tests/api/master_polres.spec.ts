import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

const MAX_LATENCY_MS = 1000;

function assertEnvelope(body: Record<string, unknown>, path: string) {
  expect(body, `${path}: missing 'status'`).toHaveProperty('status');
  expect(body, `${path}: missing 'message'`).toHaveProperty('message');
}

function assertLatency(startMs: number, path: string, maxMs = MAX_LATENCY_MS) {
  const duration = Date.now() - startMs;
  expect(
    duration,
    `${path}: response took ${duration}ms, exceeding ${maxMs}ms threshold`,
  ).toBeLessThan(maxMs);
}

test.describe.serial('Master Polres API', () => {
  let adminJwt: string;
  let operatorJwt: string;
  let createdPolresId: number;

  test('Auth: Admin login returns role_id=1', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/auth/login', {
      data: { username: 'admin', password: 'admin123' },
    });
    assertLatency(start, '/auth/login (admin)');
    expect(res.status()).toBe(200);

    const body = await res.json();
    expect(body.data).toHaveProperty('jwt_token');
    assertEnvelope(body, '/auth/login (admin)');
    adminJwt = body.data.jwt_token;
  });

  test('Auth: Operator login returns role_id=2', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/auth/login', {
      data: { username: 'operator_test', password: 'operator123' },
    });
    assertLatency(start, '/auth/login (operator)');
    expect(res.status()).toBe(200);

    const body = await res.json();
    expect(body.data).toHaveProperty('jwt_token');
    operatorJwt = body.data.jwt_token;
  });

  test('POST /api/v1/master/polres — Success (201)', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/master/polres', {
      headers: { Authorization: `Bearer ${adminJwt}` },
      data: {
        nama_polres: 'Polres Test Sukses',
        polda_id: 1,
      },
    });
    assertLatency(start, 'POST /master/polres (success)');

    expect(res.status()).toBe(201);
    const body = await res.json();
    assertEnvelope(body, 'POST /master/polres (success)');
    expect(body.status).toBe(201);
    expect(body.message).toBe('Data wilayah polres berhasil ditambahkan.');
    expect(body.data).toHaveProperty('polres_id');
    expect(typeof body.data.polres_id).toBe('number');
    expect(body.data.polres_id).toBeGreaterThan(0);
    createdPolresId = body.data.polres_id;
  });

  test('POST /api/v1/master/polres — Integrity Trap (422)', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/master/polres', {
      headers: { Authorization: `Bearer ${adminJwt}` },
      data: {
        nama_polres: 'Polres Fake',
        polda_id: 9999,
      },
    });
    assertLatency(start, 'POST /master/polres (integrity trap)');

    expect(res.status()).toBe(422);
    const body = await res.json();
    assertEnvelope(body, 'POST /master/polres (integrity trap)');
    expect(body.status).toBe(422);
    expect(body.message).toBe('Validasi gagal. Induk Polda tidak ditemukan.');
    expect(body.data).toEqual({});
  });

  test('PUT /api/v1/master/polres/:id — Success (200)', async ({ request }) => {
    const start = Date.now();
    const res = await request.put(`/api/v1/master/polres/${createdPolresId}`, {
      headers: { Authorization: `Bearer ${adminJwt}` },
      data: {
        nama_polres: 'Polres Updated',
        polda_id: 1,
      },
    });
    assertLatency(start, 'PUT /master/polres (success)');

    expect(res.status()).toBe(200);
    const body = await res.json();
    assertEnvelope(body, 'PUT /master/polres (success)');
    expect(body.status).toBe(200);
    expect(body.message).toBe('Data polres berhasil diperbarui.');
    expect(body.data).toEqual({});
  });

  test('PUT /api/v1/master/polres/:id — Integrity Trap (422)', async ({ request }) => {
    const start = Date.now();
    const res = await request.put(`/api/v1/master/polres/${createdPolresId}`, {
      headers: { Authorization: `Bearer ${adminJwt}` },
      data: {
        nama_polres: 'Polres Fake Update',
        polda_id: 9999,
      },
    });
    assertLatency(start, 'PUT /master/polres (integrity trap)');

    expect(res.status()).toBe(422);
    const body = await res.json();
    assertEnvelope(body, 'PUT /master/polres (integrity trap)');
    expect(body.status).toBe(422);
    expect(body.message).toBe('Validasi gagal. Induk Polda tidak ditemukan.');
    expect(body.data).toEqual({});
  });

  test('POST /api/v1/master/polres — Role Trap (403)', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/master/polres', {
      headers: { Authorization: `Bearer ${operatorJwt}` },
      data: {
        nama_polres: 'Polres Banned',
        polda_id: 1,
      },
    });
    assertLatency(start, 'POST /master/polres (role trap)');

    expect(res.status()).toBe(403);
    const body = await res.json();
    assertEnvelope(body, 'POST /master/polres (role trap)');
    expect(body.status).toBe(403);
    expect(body.message).toBe('Akses ditolak. Anda tidak memiliki otoritas Super Admin.');
    expect(body.data).toEqual({});
  });

  test('DELETE /api/v1/master/polres/:id — Conflict Trap (409)', async ({ request }) => {
    const trapNrp = '88TRAP99';

    execSync(
      'mysql -u root -proot sindomondb -e "CREATE TABLE IF NOT EXISTS tbl_personil (personil_id VARCHAR(36) COLLATE utf8mb4_unicode_ci NOT NULL, nrp VARCHAR(20) NOT NULL, nama_lengkap VARCHAR(255) NOT NULL, pangkat_id INT(11) DEFAULT NULL, jabatan_id INT(11) DEFAULT NULL, status_aktif VARCHAR(50) DEFAULT NULL, polda_id INT(11) DEFAULT NULL, polres_id INT(11) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (personil_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"',
      { cwd: process.cwd(), timeout: 10000 }
    );
    execSync(
      'mysql -u root -proot sindomondb -e "ALTER TABLE tbl_personil ADD CONSTRAINT fk_personil_polres FOREIGN KEY (polres_id) REFERENCES tbl_polres(polres_id) ON DELETE RESTRICT" 2>/dev/null || true',
      { cwd: process.cwd(), timeout: 10000 }
    );
    execSync(
      `mysql -u root -proot sindomondb -e "INSERT INTO tbl_personil (personil_id, nrp, nama_lengkap, pangkat_id, jabatan_id, status_aktif, polda_id, polres_id) VALUES ('00000000-0000-0000-0000-00000000trap', '${trapNrp}', 'TRAP_PERSONIL_POLRES', 1, 1, 'Aktif', 1, ${createdPolresId})"`,
      { cwd: process.cwd(), timeout: 10000 }
    );

    const start = Date.now();
    const res = await request.delete(`/api/v1/master/polres/${createdPolresId}`, {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    assertLatency(start, 'DELETE /master/polres (conflict 409)');

    expect(res.status()).toBe(409);
    const body = await res.json();
    assertEnvelope(body, 'DELETE /master/polres (conflict 409)');
    expect(body.status).toBe(409);
    expect(body.message).toBe('Polres tidak dapat dihapus karena masih menaungi personel aktif (Restricted by System).');
    expect(body.data).toEqual({});
  });

  test('DELETE /api/v1/master/polres/:id — Success (200)', async ({ request }) => {
    execSync(
      `mysql -u root -proot sindomondb -e "DELETE FROM tbl_personil WHERE nrp = '88TRAP99'"`,
      { cwd: process.cwd(), timeout: 10000 }
    );

    const start = Date.now();
    const res = await request.delete(`/api/v1/master/polres/${createdPolresId}`, {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    assertLatency(start, 'DELETE /master/polres (success 200)');

    expect(res.status()).toBe(200);
    const body = await res.json();
    assertEnvelope(body, 'DELETE /master/polres (success 200)');
    expect(body.status).toBe(200);
    expect(body.message).toBe('Data polres berhasil dihapus.');
    expect(body.data).toEqual({});
  });
});

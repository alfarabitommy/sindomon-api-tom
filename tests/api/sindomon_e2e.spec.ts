import { test, expect, APIRequestContext } from '@playwright/test';
import { execSync } from 'child_process';

const OPERATOR_USER = 'operator_test';
const OPERATOR_PASS = 'operator123';
const CROSS_REGION_PERSONIL_ID = '00000000-0000-0000-0000-00000000dead';
const MAX_LATENCY_MS = 1000;

function base64UrlDecode(str: string): string {
  str = str.replace(/-/g, '+').replace(/_/g, '/');
  while (str.length % 4) str += '=';
  return Buffer.from(str, 'base64').toString('utf8');
}

function decodeJwtPayload(token: string): Record<string, unknown> {
  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('Invalid JWT format');
  return JSON.parse(base64UrlDecode(parts[1]));
}

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

test.beforeAll(() => {
  execSync('php tests/seed.php', { cwd: process.cwd(), timeout: 15000, stdio: 'inherit' });
});

let operatorJwt: string;
let createdPersonilId: string;

test.describe.serial('Sindomon E2E API Flow', () => {
  test('Auth: Login returns valid JWT with role_id and polda_id', async ({ request }) => {
    const start = Date.now();
    const res = await request.post('/api/v1/auth/login', {
      data: { username: OPERATOR_USER, password: OPERATOR_PASS },
    });
    assertLatency(start, '/auth/login');

    expect(res.status()).toBe(200);

    const body = await res.json();
    expect(body.data, 'login: missing jwt_token').toHaveProperty('jwt_token');
    assertEnvelope(body, '/auth/login');

    operatorJwt = body.data.jwt_token;

    const payload = decodeJwtPayload(operatorJwt);
    expect(payload.role_id, 'JWT: role_id should be 2').toBe(2);
    expect(payload.polda_id, 'JWT: polda_id should be 12').toBe(12);
    expect(payload, 'JWT: missing uid').toHaveProperty('uid');
    expect(payload, 'JWT: missing username').toHaveProperty('username');
    expect(payload, 'JWT: missing iat').toHaveProperty('iat');
    expect(payload, 'JWT: missing exp').toHaveProperty('exp');
    expect(payload.exp, 'JWT: exp must be > iat').toBeGreaterThan(payload.iat);
  });

  test('SDM: Create personil returns 201, duplicate NRP returns 422', async ({ request }) => {
    const nrp = `88${Date.now().toString().slice(-6)}`;

    const start1 = Date.now();
    const createRes = await request.post('/api/v1/sdm/personil', {
      headers: { Authorization: `Bearer ${operatorJwt}` },
      data: {
        nrp,
        nama_lengkap: 'Test Personel',
        pangkat_id: 1,
        jabatan_id: 1,
        polres_id: null,
      },
    });
    assertLatency(start1, 'POST /sdm/personil (create)');
    expect(createRes.status()).toBe(201);

    const createBody = await createRes.json();
    assertEnvelope(createBody, '/sdm/personil (create)');
    expect(createBody.status).toBe(201);
    expect(createBody.data.personil_id).toBeTruthy();
    createdPersonilId = createBody.data.personil_id;

    const start2 = Date.now();
    const dupRes = await request.post('/api/v1/sdm/personil', {
      headers: { Authorization: `Bearer ${operatorJwt}` },
      data: {
        nrp,
        nama_lengkap: 'Duplicate NR',
        pangkat_id: 1,
        jabatan_id: 1,
      },
    });
    assertLatency(start2, 'POST /sdm/personil (duplicate)');
    expect(dupRes.status()).toBe(422);

    const dupBody = await dupRes.json();
    assertEnvelope(dupBody, '/sdm/personil (duplicate)');
    expect(dupBody.status).toBe(422);
    expect(dupBody.message).toBe('Pendaftaran gagal. NRP sudah terdaftar di sistem.');
  });

  test('SDM: Catat hukum returns 201, cross-region returns 403', async ({ request }) => {
    const start1 = Date.now();
    const okRes = await request.post('/api/v1/sdm/hukum', {
      headers: { Authorization: `Bearer ${operatorJwt}` },
      data: {
        personil_id: createdPersonilId,
        klasifikasi: 'Pidana Umum',
        status_hukum: 'Dalam Penyelidikan',
        tanggal_mulai: '2026-07-17',
        deskripsi_kasus: 'Dugaan penyalahgunaan wewenang',
      },
    });
    assertLatency(start1, 'POST /sdm/hukum (valid)');
    expect(okRes.status()).toBe(201);

    const okBody = await okRes.json();
    assertEnvelope(okBody, '/sdm/hukum (valid)');
    expect(okBody.status).toBe(201);
    expect(okBody.message).toContain('berhasil ditambahkan');

    const start2 = Date.now();
    const forbidRes = await request.post('/api/v1/sdm/hukum', {
      headers: { Authorization: `Bearer ${operatorJwt}` },
      data: {
        personil_id: CROSS_REGION_PERSONIL_ID,
        klasifikasi: 'Sidang Disiplin',
        status_hukum: 'Menunggu Sidang',
        tanggal_mulai: '2026-07-17',
      },
    });
    assertLatency(start2, 'POST /sdm/hukum (cross-region)');
    expect(forbidRes.status()).toBe(403);

    const forbidBody = await forbidRes.json();
    assertEnvelope(forbidBody, '/sdm/hukum (cross-region)');
    expect(forbidBody.status).toBe(403);
    expect(forbidBody.message).toBe(
      'Akses ditolak. Personel tidak ditemukan atau berada di luar yurisdiksi Anda.',
    );
  });
});

test('Auth: Admin login JWT verification', async ({ request }) => {
  const start = Date.now();
  const res = await request.post('/api/v1/auth/login', {
    data: { username: 'admin', password: 'admin123' },
  });
  assertLatency(start, '/auth/login (admin)');

  expect(res.status()).toBe(200);

  const body = await res.json();
  expect(body.data, 'admin login: missing jwt_token').toHaveProperty('jwt_token');
  assertEnvelope(body, '/auth/login (admin)');

  const payload = decodeJwtPayload(body.data.jwt_token);
  expect(payload.role_id, 'JWT admin: role_id should be 1').toBe(1);
  expect(payload, 'JWT admin: missing polda_id').toHaveProperty('polda_id');
});

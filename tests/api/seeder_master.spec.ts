import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

const MAX_LATENCY_MS = 1000;

function assertEnvelope(body: Record<string, unknown>, path: string) {
  expect(body, `${path}: missing 'status'`).toHaveProperty('status');
  expect(body, `${path}: missing 'message'`).toHaveProperty('message');
  expect(body, `${path}: missing 'data'`).toHaveProperty('data');
}

function assertLatency(startMs: number, path: string, maxMs = MAX_LATENCY_MS) {
  const duration = Date.now() - startMs;
  expect(
    duration,
    `${path}: response took ${duration}ms, exceeding ${maxMs}ms threshold`,
  ).toBeLessThan(maxMs);
}

test.describe.serial('Seeder Master Data', () => {
  let adminJwt: string;

  test.beforeAll(() => {
    execSync('php index.php seeder run', {
      cwd: process.cwd(),
      timeout: 30000,
      stdio: 'inherit',
    });
  });

  test('Auth: Admin login returns valid JWT', async ({ request }) => {
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

  test('GET /api/v1/master/wilayah — returns 38 Polda', async ({ request }) => {
    const start = Date.now();
    const res = await request.get('/api/v1/master/wilayah', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    assertLatency(start, 'GET /master/wilayah');
    expect(res.status()).toBe(200);

    const body = await res.json();
    assertEnvelope(body, 'GET /master/wilayah');
    expect(body.status).toBe(200);
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data).toHaveLength(38);

    for (const polda of body.data) {
      expect(polda).toHaveProperty('id');
      expect(polda).toHaveProperty('nama_polda');
      expect(polda).toHaveProperty('latitude');
      expect(polda).toHaveProperty('longitude');
      expect(polda).toHaveProperty('polres_jajaran');
      expect(Array.isArray(polda.polres_jajaran)).toBe(true);
      expect(polda.polres_jajaran).toHaveLength(2);
    }
  });
});

test.describe.serial('Phase 2 — Transactional Seed Triggers', () => {
  let adminJwt: string;

  function findNode(nodes: any[], nama: string): any | null {
    for (const n of nodes) {
      if (n.nama_jabatan === nama) return n;
      if (n.bawahan && n.bawahan.length > 0) {
        const found = findNode(n.bawahan, nama);
        if (found) return found;
      }
    }
    return null;
  }

  test('Auth: Admin login', async ({ request }) => {
    const res = await request.post('/api/v1/auth/login', {
      data: { username: 'admin', password: 'admin123' },
    });
    expect(res.status()).toBe(200);
    const body = await res.json();
    adminJwt = body.data.jwt_token;
  });

  test('GET /api/v1/sdm/org-tree — Dirsamapta shows is_vacancy_alert: true', async ({ request }) => {
    const start = Date.now();
    const res = await request.get('/api/v1/sdm/org-tree', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    assertLatency(start, 'GET /sdm/org-tree (vacancy alert)');
    expect(res.status()).toBe(200);

    const body = await res.json();
    assertEnvelope(body, 'GET /sdm/org-tree');
    expect(body.status).toBe(200);
    expect(body.data).toHaveProperty('struktur');
    expect(Array.isArray(body.data.struktur)).toBe(true);
    expect(body.data.struktur.length).toBeGreaterThan(0);

    const dirsamapta = findNode(body.data.struktur, 'Dirsamapta');
    expect(dirsamapta).not.toBeNull();
    expect(dirsamapta.is_vacancy_alert).toBe(true);
    expect(dirsamapta.formasi_ideal).toBe(1);
    expect(dirsamapta.jumlah_riil).toBe(0);
  });
});

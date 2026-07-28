import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  timeout: 30000,
  retries: 1,
  webServer: {
    command: 'php -S localhost:8080 tests/router.php',
    cwd: '.',
    port: 8080,
    reuseExistingServer: true,
    timeout: 10000,
  },
  use: {
    baseURL: 'http://localhost:8080',
    extraHTTPHeaders: {
      'Content-Type': 'application/json',
    },
  },
  projects: [
    {
      name: 'api',
      testMatch: '**/*.spec.ts',
    },
  ],
});

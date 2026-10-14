import { defineConfig } from '@playwright/test';

/**
 * Stack E2E (doc 28 §3 item 3, doc 25 §15.3): the production web build against the real Docker Compose stack — Laravel,
 * PostgreSQL, PgBouncer, Redis, the delivery worker and Mailpit. Same origin: the browser only talks to
 * http://localhost:3100 and Next.js proxies /api and /sanctum to the API (ADR-0006), so the session cookie is the real
 * `__Host-` cookie. Build the web app with EORA_API_ORIGIN pointing at the stack's API first (rewrites are fixed at build).
 */
export default defineConfig({
  testDir: './e2e/stack',
  timeout: 180_000,
  expect: { timeout: 15_000 },
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: { baseURL: 'http://localhost:3100', channel: 'chrome', trace: 'retain-on-failure' },
  webServer: {
    command: 'NEXT_TELEMETRY_DISABLED=1 npx next start --port 3100',
    url: 'http://localhost:3100/login',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});

import { defineConfig } from '@playwright/test';

const port = Number(process.env.EORA_TEST_WEB_PORT ?? '3100');
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('EORA_TEST_WEB_PORT must be a valid port.');
const origin = `http://127.0.0.1:${port}`;

/** E2E: responsive, accessibility (axe) and keyboard checks against the production build (doc 22 §4). */
export default defineConfig({
  testDir: './e2e',
  // the real-stack flow has its own config (playwright.stack.config.ts, doc 28 §3 item 3)
  testIgnore: 'stack/**',
  timeout: 60_000,
  fullyParallel: true,
  reporter: [['list']],
  use: { baseURL: origin, channel: 'chrome', trace: 'off' },
  webServer: {
    command: `NEXT_TELEMETRY_DISABLED=1 npx next start --hostname 127.0.0.1 --port ${port}`,
    url: `${origin}/ppic`,
    reuseExistingServer: true,
    timeout: 120_000,
  },
});

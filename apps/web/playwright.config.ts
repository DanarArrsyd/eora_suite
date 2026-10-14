import { defineConfig } from '@playwright/test';

/** E2E: responsive, accessibility (axe) and keyboard checks against the production build (doc 22 §4). */
export default defineConfig({
  testDir: './e2e',
  // the real-stack flow has its own config (playwright.stack.config.ts, doc 28 §3 item 3)
  testIgnore: 'stack/**',
  timeout: 60_000,
  fullyParallel: true,
  reporter: [['list']],
  use: { baseURL: 'http://127.0.0.1:3100', channel: 'chrome', trace: 'off' },
  webServer: {
    command: 'NEXT_TELEMETRY_DISABLED=1 npx next start --port 3100',
    url: 'http://127.0.0.1:3100/ppic',
    reuseExistingServer: true,
    timeout: 120_000,
  },
});

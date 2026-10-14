import { test as base, expect, type Locator, type Page, type Route } from '@playwright/test';

/**
 * Frontend E2E runs without the API (doc 22 §4): the workspace needs a session, so `/api/v1/auth/me` is answered with a
 * synthetic signed-in user. Specs that exercise sign-in flows override the routes they need (`mockApi`). The real
 * end-to-end flow against Laravel runs separately (doc 28 §3.3).
 */
export const SESSION = {
  user: { id: '00000000-0000-4000-8000-000000000001', displayName: 'Review User', email: 'review.user@example.test', locale: 'en' },
  tenants: [{ id: '00000000-0000-7000-8000-0000000000a1', code: 'demo-stamping', name: 'Demo Stamping', timezone: 'Asia/Jakarta' }],
  activeTenantId: '00000000-0000-7000-8000-0000000000a1',
  plants: [
    { id: '00000000-0000-7000-8000-0000000000b1', code: 'P1', name: 'Plant 1' },
    { id: '00000000-0000-7000-8000-0000000000b2', code: 'P2', name: 'Plant 2' },
  ],
  activePlantId: '00000000-0000-7000-8000-0000000000b1',
  permissions: [] as string[],
};

/** Synthetic inbox (doc 07 shape): two items need action, one unread warning, one read. */
export const INBOX = [
  {
    id: '00000000-0000-7000-8000-00000000c001',
    kind: 'approval.requested',
    severity: 'INFO',
    actionRequired: true,
    title: 'Sample: stock adjustment awaits your approval',
    body: 'Sample request ADJ-0012 for RM-WH1.',
    deepLink: '/dashboard/approvals',
    createdAt: '2026-10-09T02:15:00Z',
    readAt: null,
  },
  {
    id: '00000000-0000-7000-8000-00000000c002',
    kind: 'quality.inspection_due',
    severity: 'INFO',
    actionRequired: true,
    title: 'Sample: inspection lot due',
    body: 'Sample lot IL-2207 is waiting for inspection.',
    deepLink: '/quality',
    createdAt: '2026-10-09T01:40:00Z',
    readAt: '2026-10-09T01:50:00Z',
  },
  {
    id: '00000000-0000-7000-8000-00000000c003',
    kind: 'access.sensitive_role_granted',
    severity: 'WARNING',
    actionRequired: false,
    title: 'Sensitive role granted',
    body: 'Sample: CSA (Company Super Admin) was granted to a member at TENANT scope.',
    deepLink: null,
    createdAt: '2026-10-08T09:00:00Z',
    readAt: null,
  },
  {
    id: '00000000-0000-7000-8000-00000000c004',
    kind: 'access.privileged_password_reset',
    severity: 'WARNING',
    actionRequired: false,
    title: 'Password reset on a privileged account',
    body: 'Sample: the password of a member was reset.',
    deepLink: null,
    createdAt: '2026-10-07T09:00:00Z',
    readAt: '2026-10-07T10:00:00Z',
  },
];

export type MockResponse = { status: number; json?: unknown };

/** Answers `path` (relative to /api/v1) with a fixed response; later registrations win. */
export async function mockApi(page: Page, path: string, response: MockResponse | ((route: Route) => MockResponse)) {
  await page.route(`**/api/v1${path}`, (route) => {
    const r = typeof response === 'function' ? response(route) : response;
    return route.fulfill({
      status: r.status,
      contentType: r.status >= 400 ? 'application/problem+json' : 'application/json',
      body: r.json === undefined ? '' : JSON.stringify(r.json),
    });
  });
}

/** Picks `option` in an EORA select (Radix listbox, not a native <select>): open the combobox, click the option. */
export async function choose(page: Page, combobox: Locator, option: string | RegExp) {
  await combobox.click();
  await page.getByRole('option', { name: option, exact: typeof option === 'string' }).click();
  await expect(page.getByRole('listbox')).toBeHidden();
}

export function problem(status: number, code: string, title: string, extra: Record<string, unknown> = {}) {
  return { status, json: { type: `https://errors.eora.app/${code}`, title, status, code, ...extra } };
}

export const test = base.extend<{ session: void; page: Page }>({
  // Workspace pages render after the auth gate resolved the session: navigation resolves once the page landmark exists.
  page: async ({ page }, provide) => {
    const goto = page.goto.bind(page);
    page.goto = async (url, options) => {
      const response = await goto(url, options);
      await page
        .locator('#main')
        .first()
        .waitFor({ timeout: 15_000 })
        .catch(() => undefined); // pages without the landmark (e.g. not found) are asserted by their own tests
      return response;
    };
    await provide(page);
  },
  session: [
    async ({ page }, provide) => {
      await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, headers: { 'set-cookie': 'XSRF-TOKEN=e2e-token; Path=/' } }));
      await mockApi(page, '/auth/me', { status: 200, json: SESSION });
      await mockApi(page, '/inbox', { status: 200, json: { data: INBOX, meta: { unread: INBOX.filter((n) => !n.readAt).length } } });
      await mockApi(page, '/inbox/read', { status: 200, json: { meta: { updated: 1 } } });
      await provide();
    },
    { auto: true },
  ],
});

export { expect, type Page };

import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M1 sign-in surfaces (doc 28 §1, doc 25 §4, ADR-0006) against a mocked API; the real flow runs in the stack E2E. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const TOKEN = 'a'.repeat(64);

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

async function signedOut(page: Page) {
  await mockApi(page, '/auth/me', problem(401, 'UNAUTHENTICATED', 'Authentication required'));
}

test('a workspace page without a session redirects to sign-in and keeps the destination', async ({ page }) => {
  await signedOut(page);
  await page.goto('/ppic/control-tower');
  await expect(page).toHaveURL(/\/login\?next=%2Fppic%2Fcontrol-tower$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
});

test('sign-in: wrong credentials get one uniform message and nothing else', async ({ page }) => {
  await signedOut(page);
  await mockApi(page, '/auth/login', problem(401, 'UNAUTHENTICATED', 'Authentication required'));
  await page.goto('/login');
  await page.getByLabel('E-mail').fill('someone@example.test');
  await page.getByLabel(/^Password/).fill('not the password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('The e-mail or password is not correct, or the account is temporarily locked.');
  await expect(page).toHaveURL(/\/login$/);
});

test('sign-in: a single workspace is opened directly and the user lands on the requested page', async ({ page }) => {
  let session: typeof SESSION | null = null;
  await mockApi(page, '/auth/me', () => (session ? { status: 200, json: session } : problem(401, 'UNAUTHENTICATED', 'Authentication required')));
  await mockApi(page, '/auth/login', { status: 200, json: { ...SESSION, activeTenantId: null, plants: [], activePlantId: null } });
  await mockApi(page, '/auth/tenant', (route) => {
    expect(route.request().headers()['x-xsrf-token']).toBe('e2e-token'); // Sanctum CSRF header on every mutation
    session = SESSION;
    return { status: 200, json: SESSION };
  });
  await page.goto('/login?next=%2Fppic%2Fcontrol-tower');
  await page.getByLabel('E-mail').fill(SESSION.user.email);
  await page.getByLabel(/^Password/).fill('correct horse battery staple');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/ppic\/control-tower$/);
  await expect(page.getByRole('button', { name: /Account menu, Review User, Demo Stamping/ })).toBeVisible();
});

test('sign-in ignores an off-site destination (no open redirect)', async ({ page }) => {
  let session: typeof SESSION | null = null;
  await mockApi(page, '/auth/me', () => (session ? { status: 200, json: session } : problem(401, 'UNAUTHENTICATED', 'x')));
  await mockApi(page, '/auth/login', () => {
    session = SESSION;
    return { status: 200, json: SESSION };
  });
  await page.goto('/login?next=%2F%2Fevil.example%2Fsteal');
  await page.getByLabel('E-mail').fill(SESSION.user.email);
  await page.getByLabel(/^Password/).fill('correct horse battery staple');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/127\.0\.0\.1:3100\/(ppic)?$/);
});

test('workspace picker lists only the user’s workspaces and opens the chosen one', async ({ page }) => {
  const two = {
    ...SESSION,
    activeTenantId: null,
    plants: [],
    activePlantId: null,
    tenants: [...SESSION.tenants, { id: '00000000-0000-7000-8000-0000000000a2', code: 'demo-machining', name: 'Demo Machining' }],
  };
  let chosen: string | null = null;
  await mockApi(page, '/auth/me', () => ({ status: 200, json: chosen ? { ...two, activeTenantId: chosen } : two }));
  await mockApi(page, '/auth/tenant', (route) => {
    chosen = (route.request().postDataJSON() as { tenantId: string }).tenantId;
    return { status: 200, json: { ...two, activeTenantId: chosen } };
  });
  await page.goto('/ppic');
  await expect(page).toHaveURL(/\/select-tenant\?next=%2Fppic$/);
  const list = page.getByRole('list', { name: 'Workspaces' });
  await expect(list.getByRole('button')).toHaveCount(2);
  await axe(page);
  await list.getByRole('button', { name: /Demo Machining/ }).click();
  await expect(page).toHaveURL(/\/ppic$/);
  expect(chosen).toBe('00000000-0000-7000-8000-0000000000a2');
});

test('forgot password answers the same way for any address', async ({ page }) => {
  await signedOut(page);
  await mockApi(page, '/auth/password/forgot', { status: 202 });
  await page.goto('/password/forgot');
  await axe(page);
  await page.getByLabel('E-mail').fill('maybe@example.test');
  await page.getByRole('button', { name: 'Send reset link' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Check your e-mail' })).toBeVisible();
  await expect(page.getByText('If maybe@example.test belongs to an account')).toBeVisible();
});

test('invitation: a link without a token explains what to do', async ({ page }) => {
  await page.goto('/invitations/accept');
  await expect(page.getByRole('main').getByRole('alert')).toContainText('This invitation link is incomplete');
});

test('invitation: password rules are checked before sending, then the account is activated', async ({ page }) => {
  let body: Record<string, string> | null = null;
  await mockApi(page, '/auth/invitations/accept', (route) => {
    body = route.request().postDataJSON() as Record<string, string>;
    return { status: 204 };
  });
  await page.goto(`/invitations/accept#token=${TOKEN}`);
  await axe(page);
  await page.getByLabel(/^New password/).fill('short');
  await page.getByLabel(/^Repeat password/).fill('different');
  await page.getByRole('button', { name: 'Activate account' }).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('Fix 2 fields');
  expect(body).toBeNull();

  await page.getByLabel(/^New password/).fill('correct horse battery staple');
  await page.getByLabel(/^Repeat password/).fill('correct horse battery staple');
  await page.getByRole('button', { name: 'Activate account' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Your account is ready' })).toBeVisible();
  expect(body).toEqual({ token: TOKEN, password: 'correct horse battery staple', password_confirmation: 'correct horse battery staple' });
});

test('reset: an expired link and a breached password are explained', async ({ page }) => {
  let attempt = 0;
  await mockApi(page, '/auth/password/reset', () => {
    attempt += 1;
    return attempt === 1
      ? problem(422, 'VALIDATION_FAILED', 'Validation failed', { errors: { password: ['This password appears in a list of breached passwords.'] } })
      : problem(422, 'VALIDATION_FAILED', 'Validation failed', { errors: { token: ['This reset link is invalid or has expired.'] } });
  });
  await page.goto(`/password/reset#token=${TOKEN}`);
  await page.getByLabel(/^New password/).fill('password12345678');
  await page.getByLabel(/^Repeat password/).fill('password12345678');
  await page.getByRole('button', { name: 'Change password' }).click();
  await expect(page.getByText('This password appears in a list of breached passwords.').first()).toBeVisible();
  await page.getByRole('button', { name: 'Change password' }).click();
  await expect(page.getByRole('main').getByRole('alert')).toContainText('This reset link is invalid, already used or expired.');
});

test('topbar: plant switch and sign-out talk to the API', async ({ page }) => {
  let plant = SESSION.activePlantId;
  let signedOutNow = false;
  await mockApi(page, '/auth/me', () => (signedOutNow ? problem(401, 'UNAUTHENTICATED', 'x') : { status: 200, json: { ...SESSION, activePlantId: plant } }));
  await mockApi(page, '/auth/plant', (route) => {
    plant = (route.request().postDataJSON() as { plantId: string }).plantId;
    return { status: 200, json: { ...SESSION, activePlantId: plant } };
  });
  await mockApi(page, '/auth/logout', () => {
    signedOutNow = true;
    return { status: 204 };
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic');
  await page.getByRole('button', { name: /^Workspace: Demo Stamping, P1 · Plant 1/ }).click();
  await page.getByRole('dialog', { name: 'Workspace' }).getByLabel('Plant').selectOption({ label: 'P2 · Plant 2' });
  await expect(page.getByRole('button', { name: /^Workspace: Demo Stamping, P2 · Plant 2/ })).toBeVisible();

  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: /Account menu/ }).click();
  await page.getByRole('menuitem', { name: 'Sign out' }).click();
  await expect(page).toHaveURL(/\/login(\?next=%2Fppic)?$/); // signing in again returns to the last page
});

for (const [label, width] of [
  ['desktop', 1440],
  ['phone', 375],
] as const) {
  test(`sign-in pages: axe and no horizontal overflow (${label})`, async ({ page }) => {
    await signedOut(page);
    await page.setViewportSize({ width, height: 800 });
    for (const route of ['/login', '/password/forgot', `/password/reset#token=${TOKEN}`, `/invitations/accept#token=${TOKEN}`]) {
      await page.goto(route);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      await axe(page);
    }
  });
}

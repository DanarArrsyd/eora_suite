import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test, type BrowserContext, type Cookie, type Page } from '@playwright/test';
import { choose } from '../fixtures';

/*
 * M1 exit, doc 28 §3 item 3 / doc 25 §15.3: provision → invitation delivered → accept → login → workspace picker →
 * plant switch → forgot/reset → logout against the real stack, same origin, cookies asserted. Nothing is mocked: the
 * operator commands run in the one-shot runner container, invitations and reset links arrive through the delivery
 * worker in Mailpit.
 *
 * EORA_STACK_COMPOSE  compose command prefix (default `docker compose`, e.g. `docker compose -p eora_x --env-file f`)
 * EORA_STACK_RUN_ARGS extra `compose run` arguments, space-separated (e.g. source mounts while iterating locally)
 * EORA_STACK_MAILPIT  Mailpit base URL (default http://127.0.0.1:8025)
 */

const COMPOSE = (process.env.EORA_STACK_COMPOSE ?? 'docker compose').split(' ').filter(Boolean);
const RUN_ARGS = (process.env.EORA_STACK_RUN_ARGS ?? '').split(' ').filter(Boolean);
const MAILPIT = process.env.EORA_STACK_MAILPIT ?? 'http://127.0.0.1:8025';
const SESSION_COOKIE = '__Host-eora_session';

const run = randomBytes(4).toString('hex');
const ADMIN = `admin@e2e-${run}.test`;
const FIRST_PASSWORD = `stack e2e first ${run} passphrase`;
const NEW_PASSWORD = `stack e2e renewed ${run} passphrase`;
const ALPHA = { code: `e2e-alpha-${run}`, name: `E2E Alpha ${run}` };
const BETA = { code: `e2e-beta-${run}`, name: `E2E Beta ${run}` };

/** `php artisan …` in the one-shot runner (the long-running api container has no provisioner credentials). */
function artisan(...args: string[]): string {
  const [bin, ...prefix] = COMPOSE;
  return execFileSync(bin!, [...prefix, 'run', '--rm', '-T', ...RUN_ARGS, '--entrypoint', 'php', 'db-migrate', 'artisan', ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 120_000,
  });
}

function provision(tenant: { code: string; name: string }): string {
  const out = artisan(
    'eora:tenant:provision',
    tenant.code,
    tenant.name,
    ADMIN,
    '--company-code=E2E',
    `--company-name=${tenant.name} Co`,
    '--plant-code=P1',
    '--plant-name=Plant 1',
    '--day-starts=07:00',
    '--deliver=email',
  );
  const id = /Tenant created: ([0-9a-f-]{36})/.exec(out)?.[1];
  expect(id, out).toBeTruthy();
  return id!;
}

type MailSummary = { ID: string; Subject: string; Created: string };

/** Waits for a new mail to the administrator whose link matches `path`, and returns that one-time link. */
async function mailedLink(page: Page, path: 'invitations/accept' | 'password/reset', seen: Set<string>): Promise<string> {
  const pattern = new RegExp(`https?://[^\\s"'<>]+/${path}#token=[0-9a-f]{64}`);
  let link: string | undefined;
  await expect
    .poll(
      async () => {
        const res = await page.request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:"${ADMIN}"` } });
        const { messages } = (await res.json()) as { messages: MailSummary[] };
        for (const m of messages.filter((x) => !seen.has(x.ID))) {
          const body = (await (await page.request.get(`${MAILPIT}/api/v1/message/${m.ID}`)).json()) as { Text: string; HTML: string };
          const found = pattern.exec(`${body.Text}\n${body.HTML}`)?.[0];
          if (found) {
            seen.add(m.ID);
            link = found;
            return true;
          }
        }
        return false;
      },
      { timeout: 90_000, intervals: [1_000, 2_000] },
    )
    .toBe(true);
  // the mail carries the configured frontend URL; the test always drives the same-origin web server
  return link!.replace(/^https?:\/\/[^/]+/, '');
}

function cookie(cookies: Cookie[], name: string): Cookie | undefined {
  return cookies.find((c) => c.name === name);
}

/** GET /auth/me the way the SPA sends it (Sanctum treats only first-party Referer/Origin requests as stateful). */
async function meResponse(context: BrowserContext) {
  return context.request.get('/api/v1/auth/me', { headers: { Accept: 'application/json', Referer: 'http://localhost:3100/' } });
}

async function me(context: BrowserContext): Promise<number> {
  return (await meResponse(context)).status();
}

async function signIn(page: Page, password: string) {
  await page.goto('/login');
  await page.getByLabel('E-mail').fill(ADMIN);
  await page.getByLabel(/^Password/).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
}

async function acceptInvitation(page: Page, link: string, password: string) {
  await page.goto('about:blank'); // a second link differs only in its #token: force a fresh document
  await page.goto(link);
  await page.getByLabel(/^New password/).fill(password);
  await page.getByLabel(/^Repeat password/).fill(password);
  await page.getByRole('button', { name: 'Activate account' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'Your account is ready' })).toBeVisible();
}

test('M1 access end to end on the real stack', async ({ page, browser }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const mails = new Set<string>();

  await test.step('provision two tenants for one administrator, a second plant in the first', async () => {
    const alpha = provision(ALPHA);
    provision(BETA);
    expect(artisan('eora:demo:plant', alpha, 'P2', 'Plant 2')).toContain('Plant P2 added');
  });

  await test.step('both invitations are delivered and accepted; the first sets the password', async () => {
    await acceptInvitation(page, await mailedLink(page, 'invitations/accept', mails), FIRST_PASSWORD);
    // an existing identity keeps its password (doc 25 §2.4): the second acceptance only activates the membership
    await acceptInvitation(page, await mailedLink(page, 'invitations/accept', mails), FIRST_PASSWORD);
    const reused = await page.request.post('/api/v1/auth/invitations/accept', {
      data: { token: 'f'.repeat(64), password: FIRST_PASSWORD, password_confirmation: FIRST_PASSWORD },
    });
    expect(reused.status()).toBe(422);
  });

  let preLogin: Cookie | undefined;
  await test.step('sign-in rotates the session; cookies are __Host-, Secure, HttpOnly, SameSite=Lax', async () => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { level: 1, name: 'Sign in' })).toBeVisible();
    preLogin = cookie(await page.context().cookies(), SESSION_COOKIE);
    await signIn(page, FIRST_PASSWORD);
    await expect(page).toHaveURL(/\/select-tenant/);

    const cookies = await page.context().cookies();
    const session = cookie(cookies, SESSION_COOKIE);
    expect(session).toMatchObject({ domain: 'localhost', path: '/', secure: true, httpOnly: true, sameSite: 'Lax' });
    expect(session!.value).not.toBe(preLogin?.value); // no session fixation
    expect(cookie(cookies, 'XSRF-TOKEN')).toMatchObject({ path: '/', secure: true, httpOnly: false, sameSite: 'Lax' });
    expect(cookies.every((c) => c.domain === 'localhost')).toBe(true); // nothing scoped to another host
  });

  await test.step('the workspace picker lists exactly the two memberships and opens the chosen one', async () => {
    const list = page.getByRole('list', { name: 'Workspaces' });
    await expect(list.getByRole('button')).toHaveCount(2);
    await list.getByRole('button', { name: new RegExp(ALPHA.name) }).click();
    await expect(page).not.toHaveURL(/\/select-tenant/);
    await expect(page.getByRole('button', { name: `Workspace: ${ALPHA.name}, No plant selected. Change` })).toBeVisible();
  });

  await test.step('plant switch is stored server-side and survives a reload', async () => {
    await page.getByRole('button', { name: `Workspace: ${ALPHA.name}, No plant selected. Change` }).click();
    await choose(page, page.getByRole('dialog', { name: 'Workspace' }).getByRole('combobox', { name: 'Plant' }), 'P2 · Plant 2');
    await expect(page.getByRole('button', { name: new RegExp(`^Workspace: ${ALPHA.name}, P2 · Plant 2`) })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page.getByRole('button', { name: new RegExp(`^Workspace: ${ALPHA.name}, P2 · Plant 2`) })).toBeVisible();
    const res = await meResponse(page.context());
    expect(res.status()).toBe(200);
    const session = (await res.json()) as { activePlantId: string; plants: { id: string; code: string }[] };
    expect(session.plants.find((p) => p.id === session.activePlantId)?.code).toBe('P2');
  });

  let stale: Cookie | undefined;
  await test.step('sign-out ends the session on the server; the old cookie is worthless', async () => {
    stale = cookie(await page.context().cookies(), SESSION_COOKIE);
    expect(await me(page.context())).toBe(200); // the probe itself works while signed in
    await page.getByRole('button', { name: /Account menu/ }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login/);
    expect(await me(page.context())).toBe(401);

    const replay = await browser.newContext({ baseURL: 'http://localhost:3100' });
    await replay.addCookies([stale!]);
    expect(await me(replay)).toBe(401);
    await replay.close();
  });

  await test.step('forgot/reset: the mailed link sets a new password and signs out other sessions', async () => {
    const other = await browser.newContext({ baseURL: 'http://localhost:3100' });
    const otherPage = await other.newPage();
    await signIn(otherPage, FIRST_PASSWORD);
    await expect(otherPage).toHaveURL(/\/select-tenant/);
    expect(await me(other)).toBe(200);

    await page.goto('/password/forgot');
    await page.getByLabel('E-mail').fill(ADMIN);
    await page.getByRole('button', { name: 'Send reset link' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Check your e-mail' })).toBeVisible();

    await page.goto(await mailedLink(page, 'password/reset', mails));
    await page.getByLabel(/^New password/).fill(NEW_PASSWORD);
    await page.getByLabel(/^Repeat password/).fill(NEW_PASSWORD);
    await page.getByRole('button', { name: 'Change password' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Password changed' })).toBeVisible();

    expect(await me(other)).toBe(401);
    await other.close();
  });

  await test.step('only the new password signs in; sign out again', async () => {
    await signIn(page, FIRST_PASSWORD);
    await expect(page.getByRole('main').getByRole('alert')).toContainText('The e-mail or password is not correct');

    await signIn(page, NEW_PASSWORD);
    await expect(page).toHaveURL(/\/select-tenant/);
    await page
      .getByRole('list', { name: 'Workspaces' })
      .getByRole('button', { name: new RegExp(BETA.name) })
      .click();
    // a tenant switch starts without a plant, also when only one plant is in scope (current behaviour, UAT step 4)
    await page.getByRole('button', { name: `Workspace: ${BETA.name}, No plant selected. Change` }).click();
    const plants = page.getByRole('dialog', { name: 'Workspace' }).getByRole('combobox', { name: 'Plant' });
    await plants.click();
    await expect(page.getByRole('option')).toHaveText(['P1 · Plant 1']); // only the beta tenant's plant
    await page.getByRole('option', { name: 'P1 · Plant 1' }).click();
    await expect(page.getByRole('button', { name: new RegExp(`^Workspace: ${BETA.name}, P1 · Plant 1`) })).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: /Account menu/ }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login/);
    expect(await me(page.context())).toBe(401);
  });
});

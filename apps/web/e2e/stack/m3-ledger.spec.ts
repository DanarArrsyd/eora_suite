import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { choose } from '../fixtures';

/*
 * M3 exit (doc 32 §4 item 8): the ledger end to end on the real stack, nothing mocked. A demo tenant is seeded with its
 * sample master data and opening stock (`eora:demo:stock`, the real receipt and release endpoints); then, in the browser:
 * the materials PIC transfers coil to a second rack, the production leader splits a child lot off it, inventory control
 * requests an adjustment that the administrator approves (two people), the materials PIC requests its reversal that
 * inventory control approves (two people) — and the balances equal the ledger (BR-INV-11).
 *
 * Same environment variables as m1-access.spec.ts (EORA_STACK_COMPOSE, EORA_STACK_RUN_ARGS).
 */

const COMPOSE = (process.env.EORA_STACK_COMPOSE ?? 'docker compose').split(' ').filter(Boolean);
const RUN_ARGS = (process.env.EORA_STACK_RUN_ARGS ?? '').split(' ').filter(Boolean);

const run = randomBytes(4).toString('hex');
const TENANT = `e2e-m3-${run}`;
const PASSWORD = `stack e2e m3 ${run} passphrase`;

type Role = 'csa' | 'mat' | 'prl' | 'inv';

function artisan(...args: string[]): string {
  const [bin, ...prefix] = COMPOSE;
  return execFileSync(bin!, [...prefix, 'run', '--rm', '-T', ...RUN_ARGS, '--entrypoint', 'php', 'db-migrate', 'artisan', ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 300_000,
  });
}

/** A signed-in page of its own (own cookies) for one demo account. */
async function signedIn(browser: Browser, role: Role): Promise<Page> {
  const page = await (await browser.newContext({ baseURL: 'http://localhost:3100', viewport: { width: 1440, height: 900 } })).newPage();
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await page.getByLabel('E-mail').fill(`${role}@${TENANT}.test`);
  await page.getByLabel(/^Password/).fill(PASSWORD);
  await expect(page.getByLabel('E-mail')).toHaveValue(`${role}@${TENANT}.test`);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('button', { name: /^Account menu/ })).toBeVisible();
  return page;
}

/** The API as this page's user (same session cookie and CSRF token as the screens). */
async function api<T>(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; json: T }> {
  return page.evaluate(
    async ({ method, path, body }) => {
      const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').split('=')[1] ?? '');
      const r = await fetch(`/api/v1${path}`, {
        method,
        credentials: 'include',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      return { status: r.status, json: (await r.json().catch(() => null)) as never };
    },
    { method, path, body },
  );
}

type Line = { lotNo: string | null; locationCode: string; stockStatus: string; qty: string };

async function coilLines(page: Page): Promise<string[]> {
  const items = await api<{ data: { id: string; code: string }[] }>(page, 'GET', '/query/core.items?q=COIL-SPCC-12');
  const coil = items.json.data.find((i) => i.code === 'COIL-SPCC-12')!;
  const lines = await api<{ data: Line[] }>(page, 'GET', `/query/inventory.stock?filter[itemId]=${coil.id}&page[size]=50`);
  return lines.json.data.map((l) => `${l.lotNo} ${l.locationCode} ${l.stockStatus} ${l.qty}`).sort();
}

test('M3 ledger end to end on the real stack', async ({ browser }) => {
  test.setTimeout(480_000);
  await test.step('seed a demo tenant with its master data and opening stock', async () => {
    expect(artisan('eora:demo:seed', `--code=${TENANT}`, `--password=${PASSWORD}`)).toContain('active users');
    expect(artisan('eora:demo:master', `--code=${TENANT}`, '--wait=120')).toContain('Demo master data loaded');
    expect(artisan('eora:demo:stock', `--code=${TENANT}`)).toContain('Demo stock loaded');
  });

  const admin = await signedIn(browser, 'csa');
  const materials = await signedIn(browser, 'mat');
  const leader = await signedIn(browser, 'prl');
  const inventory = await signedIn(browser, 'inv');
  let released = '';

  await test.step('the administrator adds a second raw-material rack', async () => {
    const warehouses = await api<{ data: { id: string; code: string }[] }>(admin, 'GET', '/query/core.warehouses?q=RM-WH');
    const rm = warehouses.json.data.find((w) => w.code === 'RM-WH')!;
    const created = await api(admin, 'POST', '/core/locations', { warehouseId: rm.id, code: 'RM-A02', name: 'Coil rack A02', locationType: 'RM_WAREHOUSE' });
    expect(created.status).toBe(201);
    const lines = await coilLines(admin);
    released = lines.find((l) => l.includes('UNRESTRICTED'))!.split(' ')[0]!;
    expect(lines).toHaveLength(2);
    expect(lines).toContain(`${released} RM-A01 UNRESTRICTED 1800`);
    expect(lines.some((l) => / RM-A01 BLOCKED 2500$/.test(l))).toBe(true);
  });

  await test.step('the materials PIC transfers 300 KG of the released coil to the second rack', async () => {
    await materials.goto('/inventory/stock-overview');
    await materials.getByRole('button', { name: `Transfer COIL-SPCC-12 ${released} at RM-A01 Unrestricted` }).click();
    const drawer = materials.getByRole('dialog', { name: `Transfer COIL-SPCC-12 lot ${released}` });
    await choose(materials, drawer.getByRole('combobox', { name: 'To location' }), /^P1 \/ RM-A02 · /);
    await drawer.getByRole('textbox', { name: 'Quantity' }).fill('300');
    await drawer.getByRole('button', { name: 'Transfer' }).click();
    await materials.getByRole('alertdialog', { name: 'Transfer this stock' }).getByRole('button', { name: 'Transfer' }).click();
    await expect(materials.getByText(/^TRF-P1-\d{4}-0001 transferred$/)).toBeVisible();
  });

  await test.step('the production leader splits 100 KG off the coil on the second rack', async () => {
    await leader.goto('/materials/material-lots');
    await leader.getByRole('button', { name: `Split COIL-SPCC-12 ${released} at RM-A02 Unrestricted` }).click();
    const drawer = leader.getByRole('dialog', { name: `Split lot ${released}` });
    await drawer.getByRole('textbox', { name: 'Child lot quantity' }).fill('100');
    await drawer.getByRole('textbox', { name: 'Width (mm)' }).fill('120');
    await drawer.getByRole('button', { name: 'Split' }).click();
    await leader.getByRole('alertdialog', { name: 'Split this lot' }).getByRole('button', { name: 'Split' }).click();
    await expect(leader.getByText(new RegExp(`^SPL-P1-\\d{4}-0001 split off lot ${released}-01$`))).toBeVisible();
  });

  let adjustment = '';
  await test.step('inventory control requests an adjustment of the child lot; the administrator approves it', async () => {
    await inventory.goto('/inventory/adjustments');
    await inventory.getByRole('button', { name: 'New adjustment request' }).click();
    const drawer = inventory.getByRole('dialog', { name: 'New adjustment request' });
    await choose(inventory, drawer.getByRole('combobox', { name: 'Stock line' }), `COIL-SPCC-12 · ${released}-01 · P1 / RM-A02 · Unrestricted · 100 KG`);
    await drawer.getByRole('textbox', { name: 'Quantity' }).fill('20');
    await choose(inventory, drawer.getByRole('combobox', { name: 'Reason' }), 'Damage');
    await drawer.getByRole('textbox', { name: 'Note' }).fill('Edge damaged when the coil was cut');
    await drawer.getByRole('button', { name: 'Request adjustment' }).click();
    await inventory.getByRole('alertdialog', { name: 'Request this adjustment' }).getByRole('button', { name: 'Request adjustment' }).click();
    await expect(inventory.getByText('Adjustment requested; it waits for a second person to approve it')).toBeVisible();

    await admin.goto('/inventory/adjustments');
    await admin.getByRole('button', { name: /^Open adjustment request COIL-SPCC-12/ }).click();
    await admin.getByRole('button', { name: 'Approve' }).click();
    await expect(admin.getByText('Adjustment approved and posted')).toBeVisible();
    // the page reloads the request after the notice: its title becomes the official number
    const title = admin.getByRole('heading', { level: 1 });
    await expect(title).toHaveText(/^ADJ-P1-\d{4}-0001$/);
    adjustment = (await title.textContent())!.trim();
  });

  await test.step('the materials PIC requests its reversal; inventory control approves it', async () => {
    await materials.goto('/inventory/reversals');
    await materials.getByRole('button', { name: 'New reversal request' }).click();
    const drawer = materials.getByRole('dialog', { name: 'New reversal request' });
    await drawer.getByRole('textbox', { name: 'Document number' }).fill(adjustment.toLowerCase());
    await drawer.getByRole('textbox', { name: 'Reason' }).fill('The damage was on another coil');
    await drawer.getByRole('button', { name: 'Request reversal' }).click();
    await materials.getByRole('alertdialog', { name: 'Request this reversal' }).getByRole('button', { name: 'Request reversal' }).click();
    await expect(materials.getByText('Reversal requested; it waits for a second person to approve it')).toBeVisible();

    await inventory.goto('/inventory/reversals');
    await inventory.getByRole('button', { name: new RegExp(`of ${adjustment}$`) }).click();
    await expect(inventory.getByRole('table', { name: `Legs of ${adjustment}` })).toBeVisible();
    await inventory.getByRole('button', { name: 'Approve' }).click();
    await expect(inventory.getByText('Reversal approved and posted')).toBeVisible();
  });

  await test.step('the stock and the ledger agree', async () => {
    const lines = await coilLines(inventory);
    // transfer 300, split 100 off it, adjustment −20 undone by its reversal
    expect(lines).toHaveLength(4);
    expect(lines).toEqual(
      expect.arrayContaining([`${released} RM-A01 UNRESTRICTED 1500`, `${released} RM-A02 UNRESTRICTED 200`, `${released}-01 RM-A02 UNRESTRICTED 100`]),
    );
    expect(lines.some((l) => / RM-A01 BLOCKED 2500$/.test(l))).toBe(true);
    await inventory.goto('/inventory/movements');
    await expect(
      inventory
        .getByRole('table', { name: 'Movement Ledger' })
        .getByRole('row', { name: /REV-P1-\d{4}-0001/ })
        .first(),
    ).toContainText('(reversal)');
    expect(artisan('eora:inventory:verify-balances')).toMatch(/tenant\(s\) checked; 0 with differences\./);
  });
});

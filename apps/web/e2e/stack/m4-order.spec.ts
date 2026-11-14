import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { expect, test, type Browser, type Page } from '@playwright/test';

/*
 * M4 exit (doc 33 §4 item 7, D-118): one order end to end on the real stack, nothing mocked. A demo tenant is seeded with
 * its sample master data and opening stock, and `eora:demo:m4` runs the order through the real endpoints as the demo
 * roles (SO → WO → issue → production → QC → FG → pick → partial shipment). Then, in the browser: the planner sees the
 * order partly delivered, and inventory control traces the shipment back to the coil it was made of and the coil forward
 * to the customer; the balances equal the ledger (BR-INV-11).
 *
 * Same environment variables as m1-access.spec.ts (EORA_STACK_COMPOSE, EORA_STACK_RUN_ARGS).
 */

const COMPOSE = (process.env.EORA_STACK_COMPOSE ?? 'docker compose').split(' ').filter(Boolean);
const RUN_ARGS = (process.env.EORA_STACK_RUN_ARGS ?? '').split(' ').filter(Boolean);

const run = randomBytes(4).toString('hex');
const TENANT = `e2e-m4-${run}`;
const PASSWORD = `stack e2e m4 ${run} passphrase`;

function artisan(...args: string[]): string {
  const [bin, ...prefix] = COMPOSE;
  return execFileSync(bin!, [...prefix, 'run', '--rm', '-T', ...RUN_ARGS, '--entrypoint', 'php', 'db-migrate', 'artisan', ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 300_000,
  });
}

/** A signed-in page of its own (own cookies) for one demo account. */
async function signedIn(browser: Browser, role: 'ppc' | 'inv'): Promise<Page> {
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

test('M4 order end to end on the real stack', async ({ browser }) => {
  test.setTimeout(600_000);
  let so = '';
  let wo = '';
  let shipment = '';
  let lot = '';
  await test.step('seed a demo tenant and run one order through the real endpoints', async () => {
    expect(artisan('eora:demo:seed', `--code=${TENANT}`, `--password=${PASSWORD}`)).toContain('active users');
    expect(artisan('eora:demo:master', `--code=${TENANT}`, '--wait=120')).toContain('Demo master data loaded');
    expect(artisan('eora:demo:stock', `--code=${TENANT}`)).toContain('Demo stock loaded');
    // csa@ committed the imported BOM and routing: the all-roles UAT account is the second approver (D-060, D-112)
    expect(artisan('eora:demo:uat-account', `--code=${TENANT}`, `--password=${PASSWORD}`)).toContain(`uat@${TENANT}.test`);
    const out = artisan('eora:demo:m4', `--code=${TENANT}`);
    const done = /Demo order done in \S+: (SO-P1-\d{4}-\d{4}) → (WO-P1-\d{4}-\d{4}) → lot (L\d{4}-\d{4}) → (SHP-P1-\d{4}-\d{4})/.exec(out);
    expect(done, out).not.toBeNull();
    so = done![1]!;
    wo = done![2]!;
    lot = done![3]!;
    shipment = done![4]!;
  });

  const planner = await signedIn(browser, 'ppc');
  const inventory = await signedIn(browser, 'inv');

  await test.step('the planner sees the order partly delivered', async () => {
    await planner.goto(`/demand/sales-orders?q=${so}`);
    await planner.getByRole('button', { name: `Open ${so} OEM-A` }).click();
    await expect(planner.getByRole('heading', { level: 1, name: so })).toBeVisible();
    await expect(planner.getByText('Partially fulfilled').first()).toBeVisible();
  });

  let coil = '';
  await test.step('inventory control traces the shipment back to the coil, through the run and the work order', async () => {
    await inventory.goto('/traceability/backward-trace');
    await inventory.getByRole('textbox', { name: 'Lot or shipment number' }).fill(shipment);
    await inventory.getByRole('button', { name: 'Trace', exact: true }).click();
    await expect(inventory.getByText(`What ${shipment} was made of`)).toBeVisible();
    const tree = inventory.getByRole('list', { name: 'Backward trace' });
    await expect(tree).toContainText(`${lot}`);
    await expect(tree).toContainText(`Shipped ${shipment} to OEM-A`);
    await expect(tree).toContainText(`${so} 1/1`);
    await expect(tree).toContainText(wo);
    await expect(tree).toContainText('COIL-SPCC-12');
    await expect(tree).toContainText('supplier lot HEAT-A102');
    const text = (await tree.textContent()) ?? '';
    coil = /(L\d{4}-\d{4})COIL-SPCC-12/.exec(text.replace(/\s+/g, ''))?.[1] ?? '';
    expect(coil).not.toBe('');
  });

  await test.step('and the coil forward to the customer that received it (recall)', async () => {
    await inventory.goto(`/traceability/forward-trace?q=${coil}`);
    // internal lot numbers count per item: when the coil shares its number, the coil is chosen
    const choose = inventory.getByRole('button', { name: /COIL-SPCC-12/ });
    const where = inventory.getByText(`Where ${coil} COIL-SPCC-12 went`);
    await expect(choose.or(where)).toBeVisible();
    if (await choose.isVisible()) await choose.click();
    await expect(where).toBeVisible();
    await expect(inventory.getByRole('list', { name: `Shipments of ${lot}` })).toContainText(`${shipment} to OEM-A`);
  });

  await test.step('the stock and the ledger agree', async () => {
    expect(artisan('eora:inventory:verify-balances')).toMatch(/tenant\(s\) checked; 0 with differences\./);
  });
});

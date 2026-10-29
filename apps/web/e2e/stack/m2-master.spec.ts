import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { choose } from '../fixtures';

/*
 * M2 exit (doc 29 §4): master data end to end on the real stack, nothing mocked. A demo tenant is seeded and its sample
 * master data loaded through the import pipeline (`eora:demo:master`, the import worker); then, in the browser, the
 * master-data administrator creates an item, the planner prepares and submits its routing, the administrator approves
 * it (two people, D-B2), and imports the sample file with deliberate errors — valid rows commit, error rows say why.
 *
 * Same environment variables as m1-access.spec.ts (EORA_STACK_COMPOSE, EORA_STACK_RUN_ARGS).
 */

const COMPOSE = (process.env.EORA_STACK_COMPOSE ?? 'docker compose').split(' ').filter(Boolean);
const RUN_ARGS = (process.env.EORA_STACK_RUN_ARGS ?? '').split(' ').filter(Boolean);

const run = randomBytes(4).toString('hex');
const TENANT = `e2e-m2-${run}`;
const PASSWORD = `stack e2e m2 ${run} passphrase`;
const ITEM = `E2E-${run.toUpperCase()}`;
const ERROR_FILE = fileURLToPath(new URL('../../../../docs/uat/samples/99-items-with-errors.xlsx', import.meta.url));

function artisan(...args: string[]): string {
  const [bin, ...prefix] = COMPOSE;
  return execFileSync(bin!, [...prefix, 'run', '--rm', '-T', ...RUN_ARGS, '--entrypoint', 'php', 'db-migrate', 'artisan', ...args], {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    timeout: 300_000,
  });
}

/** A signed-in page of its own (own cookies) for one demo account. */
async function signedIn(browser: Browser, role: 'csa' | 'ppc'): Promise<Page> {
  const page = await (await browser.newContext({ baseURL: 'http://localhost:3100', viewport: { width: 1440, height: 900 } })).newPage();
  await page.goto('/login');
  // the form is filled once it is interactive (a fill before hydration is reset)
  await page.waitForLoadState('networkidle');
  await page.getByLabel('E-mail').fill(`${role}@${TENANT}.test`);
  await page.getByLabel(/^Password/).fill(PASSWORD);
  await expect(page.getByLabel('E-mail')).toHaveValue(`${role}@${TENANT}.test`);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('button', { name: /^Account menu/ })).toBeVisible();
  return page;
}

test('M2 master data end to end on the real stack', async ({ browser }) => {
  // seeding and 16 imports through the worker take about a minute locally; CI runners are slower
  test.setTimeout(420_000);
  await test.step('seed a demo tenant and load the sample master data through the import worker', async () => {
    expect(artisan('eora:demo:seed', `--code=${TENANT}`, `--password=${PASSWORD}`)).toContain('active users');
    // a stuck import step is reported by the command itself, well before the runner's own time limit
    expect(artisan('eora:demo:master', `--code=${TENANT}`, '--wait=120')).toContain('Demo master data loaded');
  });

  const admin = await signedIn(browser, 'csa');
  const planner = await signedIn(browser, 'ppc');

  await test.step('the administrator creates an item', async () => {
    await admin.goto('/master/items');
    await expect(admin.getByRole('table', { name: 'Items' }).getByRole('row', { name: /BRK-A100/ })).toBeVisible();
    await admin.getByRole('button', { name: 'New item' }).click();
    const drawer = admin.getByRole('dialog', { name: 'New item' });
    await drawer.getByRole('textbox', { name: 'Code', exact: true }).fill(ITEM);
    await drawer.getByRole('textbox', { name: 'Name', exact: true }).fill(`Stack e2e bracket ${run}`);
    await choose(admin, drawer.getByRole('combobox', { name: 'Type' }), 'Finished good');
    await choose(admin, drawer.getByRole('combobox', { name: 'Base unit' }), /^PCS · /);
    await drawer.getByRole('button', { name: 'Create item' }).click();
    await expect(drawer).toBeHidden();
    await admin.getByRole('searchbox').first().fill(ITEM);
    await expect(admin.getByRole('table', { name: 'Items' }).getByRole('row', { name: new RegExp(ITEM) })).toContainText('Active');
  });

  await test.step('the planner prepares its routing and submits it', async () => {
    await planner.goto('/master/routings');
    await planner.getByRole('button', { name: 'New routing' }).click();
    const drawer = planner.getByRole('dialog', { name: 'New routing' });
    await choose(planner, drawer.getByRole('combobox', { name: 'Plant' }), /^P1 · /);
    await choose(planner, drawer.getByRole('combobox', { name: 'Item' }), new RegExp(`^${ITEM} · `));
    await drawer.getByRole('textbox', { name: 'Routing code' }).fill('R1');
    await drawer.getByRole('button', { name: /^Version 1 takes effect/ }).click();
    await planner.getByRole('button', { name: 'Today', exact: true }).click();
    await drawer.getByRole('button', { name: 'Create routing' }).click();
    await expect(planner.getByRole('heading', { level: 1, name: `${ITEM} routing R1` })).toBeVisible();

    await planner.getByRole('button', { name: 'Add operation' }).click();
    const op = planner.getByRole('dialog', { name: 'Add operation' });
    await choose(planner, op.getByRole('combobox', { name: 'Process' }), 'BLANK · Blanking');
    await choose(planner, op.getByRole('combobox', { name: 'Work center' }), /^PRS-200 · /);
    await op.getByRole('textbox', { name: 'Cycle time (s)' }).fill('4');
    await op.getByRole('button', { name: 'Add operation' }).click();
    await expect(planner.getByRole('table', { name: 'Operations of version 1' }).getByRole('row', { name: /Blanking/ })).toBeVisible();

    // a version is submitted with its standard yield
    await planner.getByRole('button', { name: 'Edit details' }).click();
    const details = planner.getByRole('dialog', { name: 'Version 1 details' });
    await details.getByRole('textbox', { name: 'Standard yield (%)' }).fill('98.5');
    await details.getByRole('button', { name: 'Save changes' }).click();
    await expect(details).toBeHidden();

    await planner.getByRole('button', { name: 'Submit for approval' }).click();
    await expect(planner.getByText('Waiting for a second person')).toBeVisible();
    // the planner may prepare, never approve
    await expect(planner.getByRole('button', { name: 'Approve' })).toHaveCount(0);
  });

  await test.step('the administrator approves it: a second person', async () => {
    await admin.goto('/master/routings');
    await admin.getByRole('searchbox').first().fill(ITEM);
    // the search is debounced into the URL: open the routing only after it settled, or the late search update replaces
    // the routing page with the list again
    await expect(admin).toHaveURL(new RegExp(`q=${ITEM}`));
    await admin.getByRole('button', { name: new RegExp(`Open R1 ${ITEM}`) }).click();
    await expect(admin.getByRole('heading', { level: 1, name: `${ITEM} routing R1` })).toBeVisible();
    await admin.getByRole('button', { name: 'Approve' }).click();
    await expect(admin.getByText('Approved versions never change. Use New version to change the routing.')).toBeVisible();
  });

  await test.step('the administrator imports the sample file with errors: valid rows commit, the others say why', async () => {
    await admin.goto('/master/import?new');
    await choose(admin, admin.getByRole('combobox', { name: 'Template' }), 'Items');
    await admin.locator('input[type=file]').setInputFiles(ERROR_FILE);
    await admin.getByRole('button', { name: 'Upload and check' }).click();
    await expect(admin.getByRole('tab', { name: 'With errors, 5' })).toBeVisible({ timeout: 60_000 });
    const rows = admin.getByRole('table', { name: 'Rows of this file' });
    await expect(rows).toContainText('Unit BOX does not exist.');
    await expect(rows).toContainText('Item BRK-A100 already exists.');
    await admin.getByRole('button', { name: 'Submit for commit' }).click();
    await admin.getByRole('button', { name: 'Commit', exact: true }).click();
    // the job summary always lists a Committed count: wait for the worker to have committed the valid row
    await expect(admin.getByRole('tab', { name: 'Committed, 1' })).toBeVisible({ timeout: 60_000 });

    await admin.goto('/master/items');
    await admin.getByRole('searchbox').first().fill('NUT-M10');
    await expect(admin.getByRole('table', { name: 'Items' }).getByRole('row', { name: /NUT-M10/ })).toBeVisible();
  });
});

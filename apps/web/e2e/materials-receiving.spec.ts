import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 3 (doc 32 §1.3, D-062, D-097): Materials → RM Receipts and Incoming Release against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-30T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const PLANT = { id: ID(1), code: 'P1', name: 'Plant 1', status: 'ACTIVE', version: 1 };
const SUPPLIER = { id: ID(2), code: 'SUP-STEEL', name: 'Steel service centre', status: 'ACTIVE', version: 1 };
const COIL = {
  id: ID(3),
  code: 'COIL-SPCC-12',
  name: 'SPCC coil 1.2 mm',
  itemType: 'RAW',
  baseUomCode: 'KG',
  baseUomDecimals: 3,
  status: 'ACTIVE',
  version: 1,
};
const RACK = { id: ID(4), code: 'RM-A01', name: 'Coil rack A01', plantCode: 'P1', locationType: 'RM_WAREHOUSE', status: 'ACTIVE', version: 1 };
const BLOCKED = {
  id: ID(5),
  plantId: PLANT.id,
  plantCode: 'P1',
  warehouseCode: 'RM-WH',
  locationId: RACK.id,
  locationCode: 'RM-A01',
  itemId: COIL.id,
  itemCode: 'COIL-SPCC-12',
  itemName: 'SPCC coil 1.2 mm',
  lotId: ID(6),
  lotNo: 'L2610-0001',
  supplierLotNo: 'HEAT-88121',
  stockStatus: 'BLOCKED',
  qty: '2500',
  availableQty: '0',
  uomCode: 'KG',
  uomDecimals: 3,
  version: 1,
};

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

type Call = { body: unknown; key: string | undefined };

async function capture(page: Page, path: string, answer: Record<string, unknown>): Promise<Call[]> {
  const calls: Call[] = [];
  await page.route(`**/api/v1${path}`, (route) => {
    calls.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: answer }) });
  });
  return calls;
}

test('RM receipts: a receipt is confirmed before it posts, with one idempotency key across a declined attempt', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['materials.receipt.view', 'materials.receipt.post'] } });
  await mockApi(page, '/query/inventory.receipts?*', page1([]));
  await mockApi(page, '/query/core.plants?*', page1([PLANT]));
  await mockApi(page, '/query/core.suppliers?*', page1([SUPPLIER]));
  await mockApi(page, '/query/core.items?*', page1([COIL]));
  await mockApi(page, '/query/core.locations?*', page1([RACK]));
  const calls = await capture(page, '/inventory/receipts', { documentId: ID(7), docNo: 'RCV-P1-2026-0001', lotNo: 'L2610-0001', replayed: false });
  const itemsUrl = page.waitForRequest((r) => r.url().includes('/query/core.items?'));

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/rm-receipts');
  await expect(page.getByRole('heading', { level: 1, name: 'RM Receipts' })).toBeVisible();
  // live screen: no Mock data label on the page (the global pre-release banner mentions the words, outside #main)
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await page.getByRole('button', { name: 'New receipt' }).click();
  const drawer = page.getByRole('dialog', { name: 'New receipt' });
  await choose(page, drawer.getByRole('combobox', { name: 'Plant' }), 'P1 · Plant 1');
  await choose(page, drawer.getByRole('combobox', { name: 'Supplier' }), 'SUP-STEEL · Steel service centre');
  await choose(page, drawer.getByRole('combobox', { name: 'Item' }), 'COIL-SPCC-12 · SPCC coil 1.2 mm (KG)');
  // the item picker offers purchased material only (D-097)
  expect(decodeURIComponent((await itemsUrl).url())).toContain('filter[itemType][]=RAW');
  await choose(page, drawer.getByRole('combobox', { name: 'Put away at' }), 'P1 / RM-A01 · Coil rack A01');
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('2500');
  await drawer.getByRole('textbox', { name: 'Supplier lot or heat number' }).fill('HEAT-88121');

  // declined: nothing is sent
  await drawer.getByRole('button', { name: 'Post receipt' }).click();
  const ask = page.getByRole('alertdialog', { name: 'Post this receipt' });
  await expect(ask).toContainText('A posted receipt is never edited');
  await axe(page);
  await ask.getByRole('button', { name: 'Cancel' }).click();
  await expect(drawer).toContainText('Nothing was posted');
  expect(calls).toHaveLength(0);

  await drawer.getByRole('button', { name: 'Post receipt' }).click();
  await page.getByRole('alertdialog', { name: 'Post this receipt' }).getByRole('button', { name: 'Post receipt' }).click();
  await expect(page.getByText('RCV-P1-2026-0001 posted, lot L2610-0001')).toBeVisible();
  expect(calls).toHaveLength(1);
  expect(calls[0]!.key).toMatch(/^[A-Za-z0-9._:-]{8,}$/);
  expect(calls[0]!.body).toEqual({
    plantId: PLANT.id,
    supplierId: SUPPLIER.id,
    itemId: COIL.id,
    locationId: RACK.id,
    qty: '2500',
    supplierLotNo: 'HEAT-88121',
    note: null,
  });
});

test('RM receipts: a viewer sees the list, not the action', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['materials.receipt.view'] } });
  await mockApi(page, '/query/inventory.receipts?*', page1([]));
  await page.goto('/materials/rm-receipts');
  await expect(page.getByRole('button', { name: /^New receipt\. Your roles do not allow/ })).toHaveAttribute('aria-disabled', 'true');
});

test('incoming release: blocked lines only; Release posts the quantity in place', async ({ page }) => {
  await mockApi(page, '/auth/me', {
    status: 200,
    json: { ...SESSION, permissions: ['materials.incoming_release.view', 'materials.incoming_release.post'] },
  });
  const listUrl = page.waitForRequest((r) => r.url().includes('/query/inventory.stock?'));
  await mockApi(page, '/query/inventory.stock?*', page1([BLOCKED]));
  const calls = await capture(page, '/inventory/incoming-releases', { documentId: ID(8), docNo: 'REL-P1-2026-0001', lotNo: 'L2610-0001', replayed: false });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/incoming-release');
  const url = decodeURIComponent((await listUrl).url());
  expect(url).toContain('filter[stockStatus]=BLOCKED');
  expect(url).toContain('filter[locationType]=RM_WAREHOUSE');
  await expect(page.getByRole('table', { name: 'Incoming Release' }).getByRole('row', { name: /COIL-SPCC-12/ })).toContainText('2500 KG');
  await axe(page);

  await page.getByRole('button', { name: 'Release COIL-SPCC-12 L2610-0001 at RM-A01' }).click();
  const drawer = page.getByRole('dialog', { name: 'Release COIL-SPCC-12 lot L2610-0001' });
  await expect(drawer.getByRole('textbox', { name: 'Quantity to release' })).toHaveValue('2500');
  // above zero and within the unit's decimals, before anything is asked or sent
  await drawer.getByRole('textbox', { name: 'Quantity to release' }).fill('0');
  await drawer.getByRole('button', { name: 'Release' }).click();
  await expect(drawer).toContainText('Enter a quantity above zero.');
  await drawer.getByRole('textbox', { name: 'Quantity to release' }).fill('1.2345');
  await drawer.getByRole('button', { name: 'Release' }).click();
  await expect(drawer).toContainText('Enter KG with at most 3 decimals.');
  await expect(page.getByRole('alertdialog')).toHaveCount(0);
  expect(calls).toHaveLength(0);
  await drawer.getByRole('textbox', { name: 'Quantity to release' }).fill('2000');
  await drawer.getByRole('button', { name: 'Release' }).click();
  await page.getByRole('alertdialog', { name: 'Release this material' }).getByRole('button', { name: 'Release' }).click();
  await expect(page.getByText('REL-P1-2026-0001 released, lot L2610-0001')).toBeVisible();
  expect(calls[0]!.body).toEqual({
    plantId: PLANT.id,
    itemId: COIL.id,
    lotId: BLOCKED.lotId,
    lines: [{ locationId: RACK.id, qty: '2000' }],
    note: null,
  });
});

test('navigation: a quality inspector reaches Incoming Release, and only the live Materials items', async ({ page }) => {
  // the prototype role "Quality Inspector" does not open Materials; the live permission does
  await page.addInitScript(() => window.localStorage.setItem('eora.prototype.role', 'quality-inspector'));
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['materials.incoming_release.view', 'materials.incoming_release.post'] } });
  await mockApi(page, '/query/inventory.stock?*', page1([]));
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/incoming-release');
  await expect(page.getByRole('navigation', { name: 'Modules' }).getByRole('link', { name: 'Materials' })).toBeVisible();
  const menu = page.getByRole('navigation', { name: 'Materials menu' });
  await expect(menu.getByRole('link', { name: 'Incoming Release' })).toBeVisible();
  await expect(menu.getByRole('link', { name: 'RM Receipts' })).toHaveCount(0);
  await expect(menu.getByRole('link', { name: 'Material Issue' })).toHaveCount(0);
});

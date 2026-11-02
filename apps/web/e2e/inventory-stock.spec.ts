import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 4 (doc 32 §1.4, D-048, D-098): Inventory → Stock Overview and Transfer against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-02T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const LINE = {
  id: ID(1),
  plantId: ID(2),
  plantCode: 'P1',
  warehouseCode: 'RM-WH',
  workCenterCode: null,
  locationId: ID(3),
  locationCode: 'RM-A01',
  locationType: 'RM_WAREHOUSE',
  itemId: ID(4),
  itemCode: 'COIL-SPCC-12',
  itemName: 'SPCC coil 1.2 mm',
  lotId: ID(5),
  lotNo: 'L2610-0001',
  supplierLotNo: 'HEAT-88121',
  stockStatus: 'UNRESTRICTED',
  qty: '2000',
  availableQty: '2000',
  uomCode: 'KG',
  uomDecimals: 3,
  version: 1,
};
const RACKS = [
  { id: LINE.locationId, code: 'RM-A01', name: 'Coil rack A01', plantCode: 'P1', locationType: 'RM_WAREHOUSE', status: 'ACTIVE', version: 1 },
  { id: ID(6), code: 'RM-A02', name: 'Coil rack A02', plantCode: 'P1', locationType: 'RM_WAREHOUSE', status: 'ACTIVE', version: 1 },
];

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('stock overview: ledger stock with available quantity; Transfer moves it to a location of the same kind', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['inventory.stock.view', 'materials.transfer.post'] } });
  await mockApi(page, '/query/inventory.stock?*', page1([LINE]));
  const racksUrl = page.waitForRequest((r) => r.url().includes('/query/core.locations?'));
  await mockApi(page, '/query/core.locations?*', page1(RACKS));
  const calls: { body: unknown; key: string | undefined }[] = [];
  await page.route('**/api/v1/inventory/transfers', (route) => {
    calls.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(7), docNo: 'TRF-P1-2026-0001', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/inventory/stock-overview');
  await expect(page.getByRole('heading', { level: 1, name: 'Stock Overview' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  const row = page.getByRole('table', { name: 'Stock Overview' }).getByRole('row', { name: /COIL-SPCC-12/ });
  await expect(row).toContainText('Unrestricted');
  await expect(row).toContainText('2000 KG');
  await axe(page);

  await page.getByRole('button', { name: 'Transfer COIL-SPCC-12 L2610-0001 at RM-A01 Unrestricted' }).click();
  const drawer = page.getByRole('dialog', { name: 'Transfer COIL-SPCC-12 lot L2610-0001' });
  // only locations of the line's plant and kind are offered (D-098)
  await drawer.getByRole('combobox', { name: 'To location' }).click();
  const url = decodeURIComponent((await racksUrl).url());
  expect(url).toContain(`filter[plantId]=${LINE.plantId}`);
  expect(url).toContain('filter[locationType][]=RM_WAREHOUSE');
  await page.getByRole('option', { name: 'P1 / RM-A01 · Coil rack A01' }).click();
  await drawer.getByRole('button', { name: 'Transfer' }).click();
  await expect(drawer).toContainText('Choose another location than the one the stock is in.');
  expect(calls).toHaveLength(0);

  await choose(page, drawer.getByRole('combobox', { name: 'To location' }), 'P1 / RM-A02 · Coil rack A02');
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('800');
  await drawer.getByRole('button', { name: 'Transfer' }).click();
  await page.getByRole('alertdialog', { name: 'Transfer this stock' }).getByRole('button', { name: 'Transfer' }).click();
  await expect(page.getByText('TRF-P1-2026-0001 transferred')).toBeVisible();
  expect(calls[0]!.key).toMatch(/^[A-Za-z0-9._:-]{8,}$/);
  expect(calls[0]!.body).toEqual({
    plantId: LINE.plantId,
    itemId: LINE.itemId,
    lotId: LINE.lotId,
    stockStatus: 'UNRESTRICTED',
    fromLocationId: LINE.locationId,
    toLocationId: ID(6),
    qty: '800',
    note: null,
  });
});

test('stock overview: a viewer sees stock; Transfer explains the missing permission and sends nothing', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['inventory.stock.view'] } });
  await mockApi(page, '/query/inventory.stock?*', page1([LINE]));
  await page.goto('/inventory/stock-overview');
  // plant and item options come from master data, which a stock-only role does not read: those filters are left out
  await expect(page.getByRole('table', { name: 'Stock Overview' }).getByRole('row', { name: /COIL-SPCC-12/ })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Plant', exact: true })).toHaveCount(0);
  await expect(page.getByRole('combobox', { name: 'Item', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: /^Transfer COIL-SPCC-12/ }).click();
  await expect(page.getByRole('dialog', { name: /Transfer COIL-SPCC-12/ })).toContainText('Your roles do not allow transfers.');
  await expect(page.getByRole('combobox', { name: 'To location' })).toHaveCount(0);
});

test('stock overview: allocated stock stays put — Transfer offers and allows only the available quantity (BR-INV-08)', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['inventory.stock.view', 'materials.transfer.post'] } });
  await mockApi(page, '/query/inventory.stock?*', page1([{ ...LINE, availableQty: '1500' }]));
  await mockApi(page, '/query/core.locations?*', page1(RACKS));
  await page.goto('/inventory/stock-overview');
  await page.getByRole('button', { name: /^Transfer COIL-SPCC-12/ }).click();
  const drawer = page.getByRole('dialog', { name: /Transfer COIL-SPCC-12/ });
  await expect(drawer.getByRole('textbox', { name: 'Quantity' })).toHaveValue('1500');
  await choose(page, drawer.getByRole('combobox', { name: 'To location' }), 'P1 / RM-A02 · Coil rack A02');
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('1800');
  await drawer.getByRole('button', { name: 'Transfer' }).click();
  await expect(drawer).toContainText('Only 1500 KG is available here; the rest is allocated.');
  await expect(page.getByRole('alertdialog')).toHaveCount(0);
});

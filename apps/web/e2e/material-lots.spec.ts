import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 5 (doc 32 §1.5, doc 04 row 7, D-099): Materials → Material Lots and Split against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-06T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const LOT = {
  id: ID(1),
  plantId: ID(2),
  plantCode: 'P1',
  warehouseCode: 'RM-WH',
  workCenterCode: null,
  locationId: ID(3),
  locationCode: 'RM-A01',
  locationType: 'RM_WAREHOUSE',
  itemId: ID(4),
  itemCode: 'SHEET-SPCC-12',
  itemName: 'SPCC sheet 1.2 mm',
  lotId: ID(5),
  lotNo: 'L2611-0001',
  supplierLotNo: 'HEAT-88121',
  lotOrigin: 'RECEIPT',
  parentLotNo: null,
  stockStatus: 'UNRESTRICTED',
  qty: '500',
  availableQty: '500',
  uomCode: 'KG',
  uomDecimals: 3,
  version: 1,
};
const CHILD = { ...LOT, id: ID(8), lotId: ID(9), lotNo: 'L2611-0001-01', lotOrigin: 'SPLIT', parentLotNo: 'L2611-0001', qty: '120', availableQty: '120' };
const PRL = ['inventory.stock.view', 'production.lot_split.view', 'production.lot_split.post'];

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('material lots: lot-tracked stock with its parent; Split posts one child lot where the lot lies', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: PRL } });
  const listUrl = page.waitForRequest((r) => r.url().includes('/query/inventory.stock?'));
  await mockApi(page, '/query/inventory.stock?*', page1([LOT, CHILD]));
  const calls: { body: unknown; key: string | undefined }[] = [];
  await page.route('**/api/v1/inventory/lot-splits', (route) => {
    calls.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          documentId: ID(7),
          docNo: 'SPL-P1-2026-0001',
          replayed: false,
          lotId: LOT.lotId,
          lotNo: LOT.lotNo,
          children: [{ lotId: ID(10), lotNo: 'L2611-0001-02', qty: '80' }],
        },
      }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/material-lots');
  await expect(page.getByRole('heading', { level: 1, name: 'Material Lots' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  expect(decodeURIComponent((await listUrl).url())).toContain('filter[lotTracked]=true');
  const table = page.getByRole('table', { name: 'Material Lots' });
  await expect(table.getByRole('row', { name: /L2611-0001-01/ })).toContainText('Split from L2611-0001');
  await axe(page);

  await page.getByRole('button', { name: 'Split SHEET-SPCC-12 L2611-0001 at RM-A01 Unrestricted' }).click();
  const drawer = page.getByRole('dialog', { name: 'Split lot L2611-0001' });
  await drawer.getByRole('textbox', { name: 'Child lot quantity' }).fill('600');
  await drawer.getByRole('button', { name: 'Split' }).click();
  await expect(drawer).toContainText('Only 500 KG is available here; the rest is allocated.');
  expect(calls).toHaveLength(0);

  await drawer.getByRole('textbox', { name: 'Child lot quantity' }).fill('80');
  await drawer.getByRole('textbox', { name: 'Width (mm)' }).fill('1219');
  await drawer.getByRole('button', { name: 'Split' }).click();
  await page.getByRole('alertdialog', { name: 'Split this lot' }).getByRole('button', { name: 'Split' }).click();
  await expect(page.getByText('SPL-P1-2026-0001 split off lot L2611-0001-02')).toBeVisible();
  expect(calls[0]!.key).toMatch(/^[A-Za-z0-9._:-]{8,}$/);
  expect(calls[0]!.body).toEqual({
    plantId: LOT.plantId,
    itemId: LOT.itemId,
    lotId: LOT.lotId,
    stockStatus: 'UNRESTRICTED',
    locationId: LOT.locationId,
    children: [{ qty: '80', widthMm: '1219', lengthMm: null, thicknessMm: null }],
    note: null,
  });
});

test('material lots: a viewer sees the lots; Split explains the missing permission and sends nothing', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['inventory.stock.view', 'production.lot_split.view'] } });
  await mockApi(page, '/query/inventory.stock?*', page1([LOT]));
  await page.goto('/materials/material-lots');
  await expect(page.getByRole('table', { name: 'Material Lots' }).getByRole('row', { name: /SHEET-SPCC-12/ })).toBeVisible();
  await page.getByRole('button', { name: /^Split SHEET-SPCC-12/ }).click();
  await expect(page.getByRole('dialog', { name: 'Split lot L2611-0001' })).toContainText('Your roles do not allow lot splits.');
  await expect(page.getByRole('textbox', { name: 'Child lot quantity' })).toHaveCount(0);
});

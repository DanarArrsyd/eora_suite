import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M5 step 1 (doc 34 §1.1, D-121, D-087): Planning Parameters and Inbound Schedule against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-15T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

const param = (over: Record<string, unknown> = {}) => ({
  id: ID(1),
  code: 'BRK-A100',
  version: 1,
  itemId: ID(11),
  itemName: 'Bracket front A100',
  itemType: 'FINISHED',
  plantId: ID(21),
  plantCode: 'P1',
  uomCode: 'PCS',
  uomDecimals: 0,
  productionLeadTimeDays: 3,
  purchaseLeadTimeDays: null,
  safetyStock: '100',
  safetyStockConfigured: true,
  lotPolicy: 'LOT_FOR_LOT',
  updatedAt: '2026-11-15T03:00:00Z',
  updatedByName: 'Planner',
  ...over,
});

test('planning parameters: lead time by kind of item, safety stock or Not configured', async ({ page }) => {
  await mockApi(page, '/auth/me', {
    status: 200,
    json: { ...SESSION, permissions: ['planning.plan_parameter.view', 'planning.plan_parameter.create', 'master.org.view'] },
  });
  await mockApi(
    page,
    '/query/planning.plan_parameters?*',
    page1([
      param(),
      param({
        id: ID(2),
        code: 'COIL-SPCC-12',
        itemType: 'RAW',
        itemName: 'SPCC coil',
        uomCode: 'KG',
        productionLeadTimeDays: null,
        purchaseLeadTimeDays: 7,
        safetyStock: null,
        safetyStockConfigured: false,
      }),
    ]),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/planning-parameters');
  await expect(page.getByRole('heading', { level: 1, name: 'Planning Parameters' })).toBeVisible();
  const table = page.getByRole('table');
  await expect(table).toContainText('3 days');
  await expect(table).toContainText('Production');
  await expect(table).toContainText('7 days');
  await expect(table).toContainText('Purchase');
  await expect(table).toContainText('100 PCS');
  await expect(table).toContainText('Not configured');
  await expect(page.getByRole('button', { name: 'New parameter set' })).toBeVisible();
  await axe(page);
});

test('inbound schedule: scheduled, outstanding and status per line', async ({ page }) => {
  await mockApi(page, '/auth/me', {
    status: 200,
    json: { ...SESSION, permissions: ['materials.inbound_schedule.view', 'materials.inbound_schedule.create', 'master.org.view'] },
  });
  await mockApi(
    page,
    '/query/materials.inbound_schedule?*',
    page1([
      {
        id: ID(3),
        code: 'INB-P1-2026-0001',
        version: 2,
        status: 'PARTIALLY_RECEIVED',
        plantId: ID(21),
        plantCode: 'P1',
        supplierCode: 'SUP-STEEL',
        supplierName: 'Sample steel service centre',
        itemId: ID(12),
        itemCode: 'COIL-SPCC-12',
        itemName: 'SPCC coil',
        qty: '1500',
        receivedQty: '600',
        outstandingQty: '900',
        receipts: 1,
        uomCode: 'KG',
        uomDecimals: 3,
        expectedDate: '2026-11-20',
        note: null,
        cancelReason: null,
        cancelledByName: null,
        createdByName: 'Materials',
      },
    ]),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/inbound-schedule');
  await expect(page.getByRole('heading', { level: 1, name: 'Inbound Schedule' })).toBeVisible();
  const table = page.getByRole('table');
  await expect(table).toContainText('INB-P1-2026-0001');
  await expect(table).toContainText('1,500.000 KG');
  await expect(table).toContainText('900.000 KG');
  await expect(table).toContainText('600 received');
  await expect(table).toContainText('Partially received');
  await axe(page);
});

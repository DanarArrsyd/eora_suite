import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 7a (doc 33 §1.7, D-115): Delivery → Allocation & Pick against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-14T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[]) => ({ status: 200, json: { ...SESSION, permissions } });
const DLV = ['delivery.shipment.view', 'delivery.shipment.create', 'delivery.shipment.submit', 'delivery.shipment.post', 'master.org.view'];
const FGW = ['delivery.shipment.view', 'delivery.shipment.post', 'master.org.view'];

const line = (over: Record<string, unknown> = {}) => ({
  id: ID(1),
  status: 'FIRM',
  plantId: ID(2),
  plantCode: 'P1',
  salesOrderId: ID(3),
  soNo: 'SO-P1-2026-0002',
  lineNo: 1,
  scheduleNo: 1,
  customerPoNo: 'PO-7',
  customerCode: 'OEM-A',
  customerName: 'OEM A',
  itemId: ID(4),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  requirementDate: '2026-11-19',
  requiredQty: '300',
  allocatedQty: '0',
  stagedQty: '0',
  deliveredQty: '0',
  toAllocateQty: '300',
  uomCode: 'PCS',
  uomDecimals: 0,
  allocations: [],
  staged: [],
  ...over,
});

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('allocation: delivery confirms the FIFO proposal, never more than the line needs', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  let current = line();
  await mockApi(page, '/auth/me', ME(DLV));
  await mockApi(page, '/query/core.locations?*', page1([]));
  await page.route('**/api/v1/delivery/schedule-lines/*/proposal', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          scheduleLineId: ID(1),
          toAllocate: '300',
          uomCode: 'PCS',
          lines: [
            { stockLineId: ID(10), locationId: ID(20), locationCode: 'FG-A01', lotId: ID(30), lotNo: 'L2611-0001', availableQty: '250', proposedQty: '250' },
            { stockLineId: ID(11), locationId: ID(21), locationCode: 'FG-A02', lotId: ID(31), lotNo: 'L2611-0002', availableQty: '90', proposedQty: '50' },
          ],
        },
      }),
    }),
  );
  await page.route(/\/api\/v1\/delivery\/schedule-lines\/[^/]+$/, (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: current }) }),
  );
  await page.route('**/api/v1/delivery/allocations', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    current = line({
      allocatedQty: '300',
      toAllocateQty: '0',
      allocations: [{ id: ID(40), stockLineId: ID(10), locationCode: 'FG-A01', lotNo: 'L2611-0001', qty: '250', createdAt: '2026-11-14T01:00:00Z' }],
    });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { scheduleLineId: ID(1), allocationIds: [ID(40)], replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/delivery/allocation-pick?line=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'SO-P1-2026-0002 · 1/1' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  const a1 = page.getByRole('textbox', { name: 'Allocate from FG-A01 L2611-0001 (PCS)' });
  await expect(a1).toHaveValue('250');
  await expect(page.getByRole('textbox', { name: 'Allocate from FG-A02 L2611-0002 (PCS)' })).toHaveValue('50');

  await a1.fill('251');
  await page.getByRole('button', { name: 'Allocate', exact: true }).click();
  await expect(page.getByText('FG-A01 L2611-0001: only 250 PCS is available there.')).toBeVisible();
  await a1.fill('250');
  await page.getByRole('textbox', { name: 'Allocate from FG-A02 L2611-0002 (PCS)' }).fill('60');
  await page.getByRole('button', { name: 'Allocate', exact: true }).click();
  await expect(page.getByText('The line needs only 300 PCS more.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Allocate from FG-A02 L2611-0002 (PCS)' }).fill('50');
  await axe(page);
  await page.getByRole('button', { name: 'Allocate', exact: true }).click();
  await page.getByRole('alertdialog', { name: 'Allocate these goods' }).getByRole('button', { name: 'Allocate' }).click();
  await expect(page.getByText('Allocated', { exact: true }).first()).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    scheduleLineId: ID(1),
    lines: [
      { stockLineId: ID(10), qty: '250' },
      { stockLineId: ID(11), qty: '50' },
    ],
  });
  await expect(page.getByRole('button', { name: 'Release FG-A01 L2611-0001' })).toBeVisible();
});

test('pick: the FG warehouse picks part of an allocation to staging and cannot release it', async ({ page }) => {
  const writes: unknown[] = [];
  await mockApi(page, '/auth/me', ME(FGW));
  await mockApi(
    page,
    '/query/core.locations?*',
    page1([
      { id: ID(50), code: 'STG-01', name: 'Shipping staging', version: 1, plantId: ID(2), locationType: 'STAGING', status: 'ACTIVE' },
      { id: ID(51), code: 'FG-A01', name: 'FG rack A01', version: 1, plantId: ID(2), locationType: 'FG_WAREHOUSE', status: 'ACTIVE' },
    ]),
  );
  await page.route(/\/api\/v1\/delivery\/schedule-lines\/[^/]+$/, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: line({
          allocatedQty: '300',
          toAllocateQty: '0',
          allocations: [{ id: ID(40), stockLineId: ID(10), locationCode: 'FG-A01', lotNo: 'L2611-0001', qty: '300', createdAt: '2026-11-14T01:00:00Z' }],
        }),
      }),
    }),
  );
  await page.route('**/api/v1/delivery/picks', (route) => {
    writes.push(route.request().postDataJSON());
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(60), docNo: 'PCK-P1-2026-0001', replayed: false, lotId: ID(30), lotNo: 'L2611-0001' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/delivery/allocation-pick?line=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'SO-P1-2026-0002 · 1/1' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Release FG-A01 L2611-0001' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Allocate', exact: true })).toHaveCount(0);
  await page.getByRole('textbox', { name: 'Pick from FG-A01 L2611-0001 (PCS)' }).fill('301');
  await page.getByRole('button', { name: 'Pick to staging' }).click();
  await expect(page.getByText('FG-A01 L2611-0001: only 300 PCS is allocated.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Pick from FG-A01 L2611-0001 (PCS)' }).fill('200');
  await axe(page);
  await page.getByRole('button', { name: 'Pick to staging' }).click();
  await page.getByRole('alertdialog', { name: 'Pick to staging' }).getByRole('button', { name: 'Pick to staging' }).click();
  await expect(page.getByText('PCK-P1-2026-0001 picked to STG-01')).toBeVisible();
  expect(writes).toEqual([{ plantId: ID(2), stagingLocationId: ID(50), note: null, lines: [{ allocationId: ID(40), qty: '200' }] }]);
});

import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 7b (doc 33 §1.7, D-116): Delivery → Shipments against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-14T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const FGW = ['delivery.shipment.view', 'delivery.shipment.post', 'master.org.view', 'master.part.view'];

const staged = (n: number, so: string, lot: string, qty: string, outstanding: string) => ({
  scheduleLineId: ID(n),
  soNo: so,
  lineNo: 1,
  scheduleNo: 1,
  requirementDate: '2026-11-19',
  customerPoNo: 'PO-7',
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  stockLineId: ID(n + 100),
  locationCode: 'STG-01',
  lotNo: lot,
  stagedQty: qty,
  outstandingQty: outstanding,
  uomCode: 'PCS',
  uomDecimals: 0,
});

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('ship: what is staged for one customer, partly, with the vehicle and driver', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: FGW } });
  await mockApi(
    page,
    '/query/core.customers?*',
    page1([
      { id: ID(70), code: 'OEM-A', name: 'Sample OEM customer A', status: 'ACTIVE', version: 1 },
      { id: ID(71), code: 'OEM-B', name: 'Sample OEM customer B', status: 'ACTIVE', version: 1 },
    ]),
  );
  await page.route('**/api/v1/delivery/staged?*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [staged(1, 'SO-P1-2026-0002', 'L2611-0001', '200', '300'), staged(2, 'SO-P1-2026-0003', 'L2611-0002', '30', '30')] }),
    }),
  );
  await page.route('**/api/v1/delivery/shipments', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(80), docNo: 'SHP-P1-2026-0001', replayed: false, lotId: ID(30), lotNo: 'L2611-0001' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/delivery/shipments?new=1');
  await expect(page.getByRole('heading', { level: 1, name: 'Ship' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await choose(page, page.getByRole('combobox', { name: 'Customer' }), 'OEM-A · Sample OEM customer A');
  const first = page.getByRole('textbox', { name: 'Ship SO-P1-2026-0002 1/1 L2611-0001 (PCS)' });
  await expect(first).toHaveValue('200');
  await expect(page.getByRole('textbox', { name: 'Ship SO-P1-2026-0003 1/1 L2611-0002 (PCS)' })).toHaveValue('30');

  await first.fill('201');
  await page.getByRole('button', { name: 'Ship', exact: true }).click();
  await expect(page.getByText('SO-P1-2026-0002 1/1 L2611-0001: only 200 PCS is staged for it.')).toBeVisible();
  await first.fill('150');
  await page.getByRole('textbox', { name: 'Ship SO-P1-2026-0003 1/1 L2611-0002 (PCS)' }).fill('');
  await page.getByRole('textbox', { name: 'Vehicle number' }).fill('B 1234 XY');
  await page.getByRole('textbox', { name: 'Driver' }).fill('Budi');
  await axe(page);
  await page.getByRole('button', { name: 'Ship', exact: true }).click();
  await page.getByRole('alertdialog', { name: 'Ship these goods' }).getByRole('button', { name: 'Ship' }).click();
  await expect(page.getByText('SHP-P1-2026-0001 shipped')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    plantId: SESSION.activePlantId ?? SESSION.plants[0]!.id,
    customerId: ID(70),
    vehicleNo: 'B 1234 XY',
    driverName: 'Budi',
    note: null,
    lines: [{ scheduleLineId: ID(1), stockLineId: ID(101), qty: '150' }],
  });
});

test('shipments list: one row per shipment with its customer and vehicle', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: FGW } });
  await mockApi(
    page,
    '/query/delivery.shipments?*',
    page1([
      {
        id: ID(80),
        code: 'SHP-P1-2026-0001',
        version: 1,
        status: 'POSTED',
        plantCode: 'P1',
        customerCode: 'OEM-A',
        customerName: 'Sample OEM customer A',
        vehicleNo: 'B 1234 XY',
        driverName: 'Budi',
        lines: 1,
        orders: 'SO-P1-2026-0002',
        shipped: 'BRK-A100 L2611-0001 150 PCS (SO-P1-2026-0002 1/1)',
        note: null,
        postedByName: 'FG warehouse',
        postedAt: '2026-11-14T03:00:00Z',
      },
    ]),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/delivery/shipments');
  await expect(page.getByRole('heading', { level: 1, name: 'Shipments' })).toBeVisible();
  await expect(page.getByRole('table')).toContainText('B 1234 XY');
  await expect(page.getByRole('table')).toContainText('OEM-A');
  await axe(page);
});

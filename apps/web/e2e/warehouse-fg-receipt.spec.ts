import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 6 (doc 33 §1.6, D-114): Warehouse → FG Receipt against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-14T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const FGW = ['warehouse.fg_receipt.view', 'warehouse.fg_receipt.create', 'warehouse.fg_receipt.submit', 'warehouse.fg_receipt.post', 'master.org.view'];

const released = (n: number, lotNo: string, qty: string) => ({
  id: ID(n),
  code: 'BRK-A100',
  version: 1,
  plantId: ID(2),
  plantCode: 'P1',
  locationId: ID(3),
  locationCode: 'QC-IN',
  itemId: ID(4),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  lotId: ID(n + 100),
  lotNo,
  stockStatus: 'QC_RELEASED',
  qty,
  uomCode: 'PCS',
  uomDecimals: 0,
  updatedAt: '2026-11-14T01:00:00Z',
});

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('FG receipt: the FG warehouse receives counted quantities of several lots into the rack it chooses', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: FGW } });
  await mockApi(
    page,
    '/query/core.locations?*',
    page1([
      { id: ID(10), code: 'FG-A01', name: 'FG rack A01', version: 1, plantId: ID(2), plantCode: 'P1', locationType: 'FG_WAREHOUSE', status: 'ACTIVE' },
      { id: ID(11), code: 'FG-A02', name: 'FG rack A02', version: 1, plantId: ID(2), plantCode: 'P1', locationType: 'FG_WAREHOUSE', status: 'ACTIVE' },
    ]),
  );
  await mockApi(page, '/query/inventory.stock?*', page1([released(1, 'L2611-0001', '920'), released(2, 'L2611-0002', '90')]));
  await page.route('**/api/v1/warehouse/fg-receipts', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(50), docNo: 'FGR-P1-2026-0001', replayed: false, lotId: ID(101), lotNo: 'L2611-0001' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/warehouse/fg-receipt?new=1');
  await expect(page.getByRole('heading', { level: 1, name: 'Receive FG' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await expect(page.getByRole('combobox', { name: 'FG location' })).toContainText('P1 / FG-A01 · FG rack A01');

  // nothing entered, more than released: refused here, nothing sent
  await page.getByRole('button', { name: 'Receive FG', exact: true }).click();
  await expect(page.getByText('Enter the counted quantity of at least one lot.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Receive BRK-A100 L2611-0002 (PCS)' }).fill('91');
  await page.getByRole('button', { name: 'Receive FG', exact: true }).click();
  await expect(page.getByText('BRK-A100 L2611-0002: only 90 PCS is QC released.')).toBeVisible();

  await page.getByRole('textbox', { name: 'Receive BRK-A100 L2611-0001 (PCS)' }).fill('900');
  await page.getByRole('textbox', { name: 'Receive BRK-A100 L2611-0002 (PCS)' }).fill('90');
  await page.getByRole('textbox', { name: 'Note' }).fill('Shift 1');
  await axe(page);
  await page.getByRole('button', { name: 'Receive FG', exact: true }).click();
  await page.getByRole('alertdialog', { name: 'Receive these goods' }).getByRole('button', { name: 'Receive FG' }).click();
  await expect(page.getByText('FGR-P1-2026-0001 received into FG-A01')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    plantId: ID(2),
    locationId: ID(10),
    note: 'Shift 1',
    lines: [
      { stockLineId: ID(1), qty: '900' },
      { stockLineId: ID(2), qty: '90' },
    ],
  });
});

test('FG receipt list: one row per receipt with its rack and lots', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: FGW } });
  await mockApi(
    page,
    '/query/warehouse.fg_receipts?*',
    page1([
      {
        id: ID(50),
        code: 'FGR-P1-2026-0001',
        version: 1,
        status: 'POSTED',
        plantCode: 'P1',
        locationCode: 'FG-A01',
        lines: 2,
        parts: 'BRK-A100',
        received: 'BRK-A100 L2611-0001 900 PCS; BRK-A100 L2611-0002 90 PCS',
        note: 'Shift 1',
        postedByName: 'FG warehouse',
        postedAt: '2026-11-14T01:03:00Z',
      },
    ]),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/warehouse/fg-receipt');
  await expect(page.getByRole('heading', { level: 1, name: 'FG Receipt' })).toBeVisible();
  await expect(page.getByRole('table')).toContainText('FG-A01');
  await expect(page.getByRole('table')).toContainText('BRK-A100 L2611-0001 900 PCS');
  await axe(page);
});

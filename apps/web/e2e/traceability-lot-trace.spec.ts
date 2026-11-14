import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 9 (doc 33 §1.9, D-118, doc 14 #18): Traceability → Backward / Forward Trace against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const VIEW = ['traceability.lot_trace.view'];

const fg = {
  key: `{${ID(1)}}`,
  parentKey: null,
  depth: 0,
  lotId: ID(1),
  restricted: false,
  via: null,
  lotNo: 'L2611-0002',
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  uomCode: 'PCS',
  origin: 'OUTPUT',
  supplierLotNo: null,
  receipt: null,
  shipments: [
    {
      docNo: 'SHP-P1-2026-0002',
      reversed: false,
      postedByName: 'Delivery',
      postedAt: '2026-11-14T16:37:00Z',
      customerCode: 'OEM-A',
      customerName: 'Sample OEM customer A',
      customerPoNo: 'PO-DEMO-M4',
      soNo: 'SO-P1-2026-0003',
      lineNo: 1,
      scheduleNo: 1,
      qty: '150',
      uomCode: 'PCS',
    },
  ],
};
const coil = {
  ...fg,
  key: `{${ID(1)},${ID(9)}}`,
  parentKey: `{${ID(1)}}`,
  depth: 1,
  lotId: ID(2),
  lotNo: 'L2611-0001',
  itemCode: 'COIL-SPCC-12',
  itemName: 'SPCC coil 1.2 mm x 120 mm',
  uomCode: 'KG',
  origin: 'RECEIPT',
  supplierLotNo: 'HEAT-A102',
  receipt: {
    docNo: 'RCV-P1-2026-0002',
    supplierCode: 'SUP-STEEL',
    supplierName: 'Sample steel service centre',
    postedByName: 'Materials',
    postedAt: '2026-11-13T03:38:00Z',
  },
  shipments: [],
  via: {
    docNo: 'PRD-P1-2026-0002',
    docType: 'PRODUCTION_POSTING',
    reversed: false,
    runNo: 'RUN-P1-2026-0002',
    workOrderNo: 'WO-P1-2026-0003',
    postedByName: 'Production leader',
    postedAt: '2026-11-14T16:37:00Z',
    qtyUsed: '100',
    uomUsed: 'KG',
    qtyMade: '300',
    uomMade: 'PCS',
  },
};
const outside = {
  ...coil,
  key: `{${ID(1)},${ID(8)}}`,
  lotId: ID(3),
  lotNo: 'L2611-0009',
  itemCode: 'NUT-M8',
  itemName: 'Weld nut M8',
  restricted: true,
  receipt: null,
  via: { outsideYourPlants: true },
};

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('backward: a shipment back to the coil, with run, WO, receipt and a lot outside the plants', async ({ page }) => {
  const asked: string[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: VIEW } });
  await page.route('**/api/v1/traceability/lot-trace?*', (route) => {
    asked.push(new URL(route.request().url()).search);
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { direction: 'backward', start: { kind: 'shipment', docNo: 'SHP-P1-2026-0002' }, nodes: [fg, coil, outside], truncated: false },
      }),
    });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/traceability/backward-trace');
  await expect(page.getByRole('heading', { level: 1, name: 'Backward Trace' })).toBeVisible();
  await expect(page.getByText('Nothing traced yet')).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);

  await page.getByRole('textbox', { name: 'Lot or shipment number' }).fill('shp-p1-2026-0002');
  await page.getByRole('button', { name: 'Trace', exact: true }).click();
  await expect(page).toHaveURL(/q=SHP-P1-2026-0002/);
  await expect(page.getByText('What SHP-P1-2026-0002 was made of')).toBeVisible();
  expect(asked[0]).toContain('direction=backward');
  const tree = page.getByRole('list', { name: 'Backward trace' });
  await expect(tree).toContainText('Shipped SHP-P1-2026-0002 to OEM-A');
  await expect(tree).toContainText('100 KG used for 300 PCS · Production posting PRD-P1-2026-0002 · run RUN-P1-2026-0002 · WO-P1-2026-0003');
  await expect(tree).toContainText('Received on RCV-P1-2026-0002 from SUP-STEEL');
  await expect(tree).toContainText('supplier lot HEAT-A102');
  await expect(tree).toContainText('Outside your plants');
  await axe(page);
});

test('forward: a lot number shared by items asks which one; the URL keeps the choice', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: VIEW } });
  await page.route('**/api/v1/traceability/lot-trace?*', (route) => {
    const lot = new URL(route.request().url()).searchParams.get('lotId');
    const data = lot
      ? {
          direction: 'forward',
          start: { kind: 'lot', lotNo: 'L2611-0001', itemCode: 'COIL-SPCC-12' },
          nodes: [
            { ...coil, depth: 0, parentKey: null, via: null },
            { ...fg, depth: 1 },
          ],
          truncated: false,
        }
      : {
          direction: 'forward',
          matches: [
            { lotId: ID(1), lotNo: 'L2611-0001', itemCode: 'BRK-A100', itemName: 'Bracket front A100' },
            { lotId: ID(2), lotNo: 'L2611-0001', itemCode: 'COIL-SPCC-12', itemName: 'SPCC coil 1.2 mm x 120 mm' },
          ],
        };
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/traceability/forward-trace?q=L2611-0001');
  await expect(page.getByText('L2611-0001 is the number of several lots')).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: /COIL-SPCC-12/ }).click();
  await expect(page).toHaveURL(new RegExp(`lotId=${ID(2)}`));
  await expect(page.getByText('Where L2611-0001 COIL-SPCC-12 went')).toBeVisible();
  await expect(page.getByRole('list', { name: 'Shipments of L2611-0002' })).toContainText('SO-P1-2026-0003 1/1 · customer PO PO-DEMO-M4 · 150 PCS');
  await axe(page);
});

test('without the permission: no trace, and a 404 explains what to check', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: [] } });
  await page.goto('/traceability/backward-trace');
  await expect(page.getByText('Lot trace is not available for your roles')).toBeVisible();

  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: VIEW } });
  await page.route('**/api/v1/traceability/lot-trace?*', (route) =>
    route.fulfill({
      status: 404,
      contentType: 'application/problem+json',
      body: JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404, code: 'NOT_FOUND' }),
    }),
  );
  await page.goto('/traceability/backward-trace?q=L9999-9999');
  await expect(page.getByText('Nothing with this number in your plants. Check the lot or shipment number.')).toBeVisible();
});

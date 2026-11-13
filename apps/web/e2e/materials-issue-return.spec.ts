import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 3 (doc 33 §1.3, D-110): Materials → Material Issue and Material Return against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-13T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[], userId = ID(90)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });

const WO = {
  id: ID(1),
  code: 'WO-P1-2026-0001',
  version: 4,
  status: 'RELEASED',
  plantId: ID(2),
  plantCode: 'P1',
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  qty: '1200',
  uomCode: 'PCS',
  dueDate: '2026-11-25',
  materials: [
    {
      lineNo: 10,
      itemId: ID(20),
      itemCode: 'COIL-SPCC-12',
      itemName: 'SPCC coil',
      uomCode: 'KG',
      requiredQty: '384',
      issuedQty: '0',
      atProductionQty: '0',
      baseUomCode: 'KG',
    },
    {
      lineNo: 20,
      itemId: ID(21),
      itemCode: 'NUT-M8',
      itemName: 'Weld nut M8',
      uomCode: 'PCS',
      requiredQty: '2400',
      issuedQty: '0',
      atProductionQty: '0',
      baseUomCode: 'PCS',
    },
  ],
  workCenters: [
    { id: ID(30), code: 'PRS-01', name: 'Press line', opSeq: 10 },
    { id: ID(31), code: 'WLD-01', name: 'Weld line', opSeq: 20 },
  ],
};
const STOCK = [
  {
    id: ID(40),
    itemId: ID(20),
    locationId: ID(50),
    locationCode: 'RM-A01',
    lotId: ID(60),
    lotNo: 'L2611-0001',
    availableQty: '500',
    uomCode: 'KG',
    uomDecimals: 3,
  },
  { id: ID(41), itemId: ID(21), locationId: ID(51), locationCode: 'RM-B02', lotId: null, lotNo: null, availableQty: '3000', uomCode: 'PCS', uomDecimals: 0 },
];

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('material issue: materials issues lots of a released work order to a work center of its routing', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(['materials.issue.view', 'materials.issue.create', 'materials.issue.post', 'planning.wo.view', 'inventory.stock.view']));
  await mockApi(page, '/query/materials.issues?*', page1([]));
  await mockApi(
    page,
    '/query/planning.work_orders?*',
    page1([{ id: WO.id, code: WO.code, itemCode: WO.itemCode, qty: WO.qty, uomCode: 'PCS', dueDate: WO.dueDate }]),
  );
  await mockApi(page, '/query/inventory.stock?*', page1(STOCK));
  await page.route('**/api/v1/planning/work-orders/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: WO }) }),
  );
  await page.route('**/api/v1/inventory/material-issues', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(70), docNo: 'ISS-P1-2026-0001', productionDate: '2026-11-13', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/materials/material-issue');
  await expect(page.getByRole('heading', { level: 1, name: 'Material Issue' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await page.getByRole('button', { name: 'Issue material' }).first().click();
  await expect(page.getByRole('heading', { level: 1, name: 'Issue material' })).toBeVisible();

  await choose(page, page.getByRole('combobox', { name: 'Released work order' }), /^WO-P1-2026-0001 · /);
  // the first operation's work center is proposed
  await expect(page.getByRole('combobox', { name: 'To work center' })).toContainText('PRS-01');
  await expect(page.getByText('384 KG')).toBeVisible();
  await page.getByRole('button', { name: 'Add COIL-SPCC-12 lot' }).click();
  await choose(page, page.getByRole('combobox', { name: 'COIL-SPCC-12 rack and lot 1' }), 'RM-A01 · L2611-0001 · 500 KG available');
  await page.getByRole('textbox', { name: 'COIL-SPCC-12 quantity 1 (KG)' }).fill('600');
  await page.getByRole('button', { name: 'Issue material' }).last().click();
  await expect(page.getByText('COIL-SPCC-12: only 500 KG is available at RM-A01, lot L2611-0001.')).toBeVisible();
  await page.getByRole('textbox', { name: 'COIL-SPCC-12 quantity 1 (KG)' }).fill('400');
  await axe(page);
  await page.getByRole('button', { name: 'Issue material' }).last().click();
  await page.getByRole('alertdialog', { name: 'Issue this material' }).getByRole('button', { name: 'Issue material' }).click();
  await expect(page.getByText('ISS-P1-2026-0001 issued to PRS-01')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    workOrderId: WO.id,
    workCenterId: ID(30),
    note: null,
    lines: [{ itemId: ID(20), lotId: ID(60), fromLocationId: ID(50), qty: '400' }],
  });
});

test('material return: production asks, materials receives what it weighed into a rack', async ({ page }) => {
  const writes: { url: string; body: unknown }[] = [];
  let row: Record<string, unknown> = {
    id: ID(80),
    code: 'RTN request',
    docNo: null,
    version: 1,
    status: 'SUBMITTED',
    plantId: ID(2),
    plantCode: 'P1',
    workOrderId: WO.id,
    workOrderCode: WO.code,
    workCenterCode: 'PRS-01',
    fromLocationCode: 'MAP-PRS-01',
    itemCode: 'COIL-SPCC-12',
    itemName: 'SPCC coil',
    lotNo: 'L2611-0001',
    askedQty: '15',
    receivedQty: null,
    differenceQty: null,
    uomCode: 'KG',
    uomDecimals: 3,
    toLocationCode: null,
    note: 'Coil end',
    effectiveDate: '2026-11-13',
    requestedById: ID(91),
    requestedByName: 'Production lead',
    submittedAt: '2026-11-13T03:00:00Z',
    decidedByName: null,
    decidedAt: null,
    rejectReason: null,
  };
  await mockApi(page, '/auth/me', ME(['materials.return.view', 'materials.return.post', 'master.org.view']));
  await page.route('**/api/v1/query/materials.returns?*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [row], meta: { ...META, total: 1 } }) }),
  );
  await mockApi(page, '/query/core.locations?*', page1([{ id: ID(52), code: 'RM-A02', name: 'Coil rack A02', plantCode: 'P1', status: 'ACTIVE', version: 1 }]));
  await page.route('**/api/v1/inventory/material-returns/**', (route) => {
    writes.push({ url: route.request().url(), body: route.request().postDataJSON() });
    row = {
      ...row,
      status: 'POSTED',
      docNo: 'RTN-P1-2026-0001',
      code: 'RTN-P1-2026-0001',
      receivedQty: '14.5',
      differenceQty: '-0.5',
      toLocationCode: 'RM-A02',
    };
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(80), docNo: 'RTN-P1-2026-0001', productionDate: '2026-11-13', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/materials/material-return?request=${ID(80)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'COIL-SPCC-12 return request' })).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Receive', exact: true }).click();
  const drawer = page.getByRole('dialog', { name: 'Receive COIL-SPCC-12 return request' });
  await expect(drawer.getByRole('textbox', { name: 'Received quantity (KG)' })).toHaveValue('15');
  await choose(page, drawer.getByRole('combobox', { name: 'Into rack' }), 'P1 / RM-A02 · Coil rack A02');
  await drawer.getByRole('textbox', { name: 'Received quantity (KG)' }).fill('14.5');
  await drawer.getByRole('button', { name: 'Receive' }).click();
  await page.getByRole('alertdialog', { name: 'Receive this return' }).getByRole('button', { name: 'Receive' }).click();
  await expect(page.getByText('RTN-P1-2026-0001 received')).toBeVisible();
  expect(writes[0]!.url).toMatch(/\/material-returns\/.+\/receive$/);
  expect(writes[0]!.body).toEqual({ toLocationId: ID(52), qty: '14.5' });
  await expect(page.getByText('-0.5 KG')).toBeVisible();
});

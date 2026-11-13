import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 2 (doc 33 §1.2, D-109, G-MPS-6): PPIC → Work Orders against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-12T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[], userId = ID(90)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });
const PLANNER = [
  'planning.wo.view',
  'planning.wo.create',
  'planning.wo.submit',
  'planning.wo.post',
  'master.org.view',
  'master.part.view',
  'demand.sales_order.view',
];

const WO = {
  id: ID(1),
  code: 'WO-P1-2026-0001',
  version: 2,
  status: 'DRAFT',
  plantId: ID(2),
  plantCode: 'P1',
  itemId: ID(3),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  qty: '1200',
  uomCode: 'PCS',
  dueDate: '2026-11-25',
  reasonCode: 'URGENT_ORDER',
  note: null,
  scheduleLineId: null,
  salesOrderId: null,
  salesOrderCode: null,
  customerCode: null,
  scheduleDate: null,
  scheduleQty: null,
  bomVersionNo: null,
  routingCode: null,
  routingVersionNo: null,
  standardYield: null,
  createdAt: '2026-11-12T07:00:00Z',
  createdByName: 'Planner',
  submittedById: null,
  submittedByName: null,
  submittedAt: null,
  releasedByName: null,
  releasedAt: null,
  returnReason: null,
  cancelledByName: null,
  cancelReason: null,
  materials: [] as unknown[],
  plantToday: '2026-11-12',
};
const MATERIALS = [
  {
    lineNo: 10,
    itemId: ID(20),
    itemCode: 'COIL-SPCC-12',
    itemName: 'SPCC coil',
    uomCode: 'KG',
    grossQtyPer: '0.32',
    requiredQty: '384',
    conversionProfileVersion: 1,
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
    grossQtyPer: '2',
    requiredQty: '2400',
    conversionProfileVersion: null,
    issuedQty: '0',
    atProductionQty: '0',
    baseUomCode: 'PCS',
  },
];

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('work orders: a planner drafts a manual work order with its reason and asks for release', async ({ page }) => {
  let wo: Record<string, unknown> = { ...WO };
  const writes: { url: string; body: unknown; ifMatch: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(PLANNER));
  await mockApi(page, '/query/planning.work_orders?*', page1([]));
  await mockApi(page, '/query/core.plants?*', page1([{ id: ID(2), code: 'P1', name: 'Plant 1', status: 'ACTIVE', version: 1 }]));
  await mockApi(
    page,
    '/query/core.items?*',
    page1([{ id: ID(3), code: 'BRK-A100', name: 'Bracket front A100', baseUomCode: 'PCS', status: 'ACTIVE', version: 1 }]),
  );
  await mockApi(page, '/query/demand.schedule_lines?*', page1([]));
  await page.route('**/api/v1/planning/work-orders**', (route) => {
    const req = route.request();
    if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: wo }) });
    writes.push({ url: req.url(), body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    if (req.url().endsWith('/actions/submit')) {
      wo = { ...wo, version: 3, status: 'SUBMITTED', submittedById: ID(90), submittedByName: 'Planner', submittedAt: '2026-11-12T07:05:00Z' };
    }
    return route.fulfill({ status: req.url().endsWith('/work-orders') ? 201 : 200, contentType: 'application/json', body: JSON.stringify({ data: wo }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/work-orders');
  await expect(page.getByRole('heading', { level: 1, name: 'Work Orders' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await axe(page);

  await page.getByRole('button', { name: 'New work order' }).first().click();
  const drawer = page.getByRole('dialog', { name: 'New work order' });
  await choose(page, drawer.getByRole('combobox', { name: 'Plant' }), /^P1 · /);
  await choose(page, drawer.getByRole('combobox', { name: 'Item' }), /^BRK-A100 · /);
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('1200');
  await drawer.getByRole('button', { name: /^Due date/ }).click();
  await page.getByRole('button', { name: 'Today', exact: true }).click();
  await choose(page, drawer.getByRole('combobox', { name: 'Reason (outside MPS)' }), 'Urgent customer order');
  await drawer.getByRole('button', { name: 'Create work order' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'WO-P1-2026-0001' })).toBeVisible();
  expect(writes[0]!.body).toMatchObject({ plantId: ID(2), itemId: ID(3), qty: '1200', reasonCode: 'URGENT_ORDER', note: null, scheduleLineId: null });
  await expect(page.getByText('Materials are copied at release')).toBeVisible();
  await axe(page);

  await page.getByRole('button', { name: 'Ask for release' }).click();
  await page.getByRole('alertdialog', { name: 'Ask for release' }).getByRole('button', { name: 'Ask for release' }).click();
  await expect(page.getByText('Release of WO-P1-2026-0001 asked; it waits for a second person')).toBeVisible();
  expect(writes[1]).toMatchObject({ ifMatch: '"2"' });
  expect(writes[1]!.url).toMatch(/\/actions\/submit$/);
  await expect(page.getByText('You asked for this release')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Release', exact: true })).toHaveCount(0);
});

test('work orders: a second person releases it (materials fixed) or returns it with a reason', async ({ page }) => {
  let wo: Record<string, unknown> = {
    ...WO,
    version: 3,
    status: 'SUBMITTED',
    submittedById: ID(90),
    submittedByName: 'Planner',
    submittedAt: '2026-11-12T07:05:00Z',
  };
  const writes: { url: string; body: unknown; ifMatch: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(['planning.wo.view', 'planning.wo.approve'], ID(91)));
  await page.route('**/api/v1/planning/work-orders/**', (route) => {
    const req = route.request();
    if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: wo }) });
    writes.push({ url: req.url(), body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    wo = req.url().endsWith('/actions/release')
      ? {
          ...wo,
          version: 4,
          status: 'RELEASED',
          releasedByName: 'Approver',
          releasedAt: '2026-11-12T08:00:00Z',
          bomVersionNo: 1,
          routingCode: 'R1',
          routingVersionNo: 1,
          standardYield: '0.98',
          materials: MATERIALS,
        }
      : { ...wo, version: 4, status: 'DRAFT', submittedById: null, submittedAt: null, returnReason: 'Use next week MPS run' };
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: wo }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/work-orders?order=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'WO-P1-2026-0001' })).toBeVisible();
  await page.getByRole('button', { name: 'Release', exact: true }).click();
  await page.getByRole('alertdialog', { name: 'Release this work order' }).getByRole('button', { name: 'Release' }).click();
  await expect(page.getByText('WO-P1-2026-0001 released')).toBeVisible();
  expect(writes[0]).toMatchObject({ ifMatch: '"3"' });
  await expect(page.getByRole('table', { name: 'Materials of WO-P1-2026-0001' })).toContainText('384 KG');
  await expect(page.getByText('BOM v1 · routing R1 v1 · standard yield 0.98')).toBeVisible();
  await axe(page);

  // another request returned with a reason
  wo = { ...WO, version: 3, status: 'SUBMITTED', submittedById: ID(90), submittedByName: 'Planner', submittedAt: '2026-11-12T07:05:00Z' };
  await page.reload();
  await page.getByRole('button', { name: 'Return', exact: true }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Return to the planner' });
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Use next week MPS run');
  await dialog.getByRole('button', { name: 'Return' }).click();
  await expect(page.getByText('WO-P1-2026-0001 returned to the planner')).toBeVisible();
  expect(writes[1]).toMatchObject({ body: { reason: 'Use next week MPS run' } });
});

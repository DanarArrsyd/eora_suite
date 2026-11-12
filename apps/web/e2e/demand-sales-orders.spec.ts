import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 1 (doc 33 §1.1, D-107, D-034): Demand / PPIC → Sales Orders against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-12T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[]) => ({ status: 200, json: { ...SESSION, permissions } });
const PLANNER = [
  'demand.sales_order.view',
  'demand.sales_order.create',
  'demand.sales_order.post',
  'demand.sales_order.submit',
  'master.org.view',
  'master.part.view',
];

const ORDER = {
  id: ID(1),
  code: 'SO-P1-2026-0001',
  version: 3,
  status: 'DRAFT',
  plantId: ID(2),
  plantCode: 'P1',
  customerId: ID(3),
  customerCode: 'OEM-A',
  customerName: 'Sample OEM customer A',
  customerPoNos: null,
  lineCount: 0,
  nextDueDate: null,
  note: null,
  createdAt: '2026-11-12T07:00:00Z',
  createdByName: 'Planner',
  confirmedAt: null,
  confirmedByName: null,
  cancelledAt: null,
  cancelledByName: null,
  cancelReason: null,
  lines: [] as unknown[],
};
const LINE = {
  id: ID(10),
  lineNo: 1,
  demandId: ID(11),
  customerItemId: ID(12),
  customerPartNo: 'A-77101-BK',
  customerDrawingRev: null,
  itemId: ID(13),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  uomCode: 'PCS',
  customerPoNo: 'PO-5501',
  customerPoLine: '10',
  status: 'ACTIVE',
  qty: '1000',
  schedules: [
    {
      id: ID(14),
      scheduleNo: 1,
      requirementDate: '2026-11-20',
      qty: '1000',
      deliveredQty: '0',
      outstandingQty: '1000',
      status: 'FIRM',
      cancelReason: null,
      cancelledAt: null,
      cancelledByName: null,
    },
  ],
};

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('sales orders: a planner drafts an order, adds a line with its delivery and confirms it', async ({ page }) => {
  let order: Record<string, unknown> = { ...ORDER };
  const writes: { method: string; url: string; body: unknown; ifMatch: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(PLANNER));
  await mockApi(page, '/query/demand.sales_orders?*', page1([]));
  await mockApi(page, '/query/core.plants?*', page1([{ id: ID(2), code: 'P1', name: 'Plant 1', status: 'ACTIVE', version: 1 }]));
  await mockApi(page, '/query/core.customers?*', page1([{ id: ID(3), code: 'OEM-A', name: 'Sample OEM customer A', status: 'ACTIVE', version: 1 }]));
  await mockApi(
    page,
    '/query/core.customer_items?*',
    page1([{ id: ID(12), code: 'A-77101-BK', itemCode: 'BRK-A100', itemName: 'Bracket front A100', status: 'ACTIVE', version: 1 }]),
  );
  await page.route('**/api/v1/demand/sales-orders**', (route) => {
    const req = route.request();
    if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: order }) });
    writes.push({ method: req.method(), url: req.url(), body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    if (req.url().endsWith('/sales-orders')) {
      return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: order }) });
    }
    if (req.url().endsWith('/lines')) {
      order = {
        ...order,
        version: 4,
        lineCount: 1,
        customerPoNos: 'PO-5501',
        lines: [{ ...LINE, schedules: [{ ...LINE.schedules[0], status: 'DRAFT', outstandingQty: '0' }] }],
      };
    } else if (req.url().endsWith('/actions/confirm')) {
      order = {
        ...order,
        version: 5,
        status: 'CONFIRMED',
        confirmedAt: '2026-11-12T07:10:00Z',
        confirmedByName: 'Planner',
        nextDueDate: '2026-11-20',
        lines: [LINE],
      };
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: order }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/demand/sales-orders');
  await expect(page.getByRole('heading', { level: 1, name: 'Sales Orders' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await axe(page);

  await page.getByRole('button', { name: 'New sales order' }).first().click();
  const create = page.getByRole('dialog', { name: 'New sales order' });
  await choose(page, create.getByRole('combobox', { name: 'Plant' }), /^P1 · /);
  await choose(page, create.getByRole('combobox', { name: 'Customer' }), /^OEM-A · /);
  await create.getByRole('button', { name: 'Create sales order' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'SO-P1-2026-0001' })).toBeVisible();
  expect(writes[0]).toMatchObject({ method: 'POST', body: { plantId: ID(2), customerId: ID(3), note: null } });
  await expect(page.getByText('No lines yet')).toBeVisible();
  await axe(page);

  await page.getByRole('button', { name: 'Add line' }).first().click();
  const drawer = page.getByRole('dialog', { name: 'Add line' });
  await choose(page, drawer.getByRole('combobox', { name: 'Customer part' }), /^A-77101-BK · /);
  await drawer.getByRole('textbox', { name: 'Customer PO number' }).fill('PO-5501');
  await drawer.getByRole('textbox', { name: 'Customer PO line' }).fill('10');
  await drawer.getByRole('button', { name: /^First delivery date/ }).click();
  await page.getByRole('button', { name: 'Today', exact: true }).click();
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('1000');
  await drawer.getByRole('button', { name: 'Add line' }).click();
  await expect(page.getByText('Line added to the draft')).toBeVisible();
  expect(writes[1]).toMatchObject({
    method: 'PUT',
    ifMatch: '"3"',
    body: { lines: [{ customerItemId: ID(12), customerPoNo: 'PO-5501', customerPoLine: '10', schedules: [{ qty: '1000' }] }] },
  });
  await expect(page.getByRole('table', { name: 'Deliveries of line 1' })).toBeVisible();

  await page.getByRole('button', { name: 'Confirm order' }).click();
  await page.getByRole('alertdialog', { name: 'Confirm this order' }).getByRole('button', { name: 'Confirm order' }).click();
  await expect(page.getByText('SO-P1-2026-0001 confirmed; its deliveries are firm demand')).toBeVisible();
  expect(writes[2]).toMatchObject({ method: 'POST', ifMatch: '"4"' });
  expect(writes[2]!.url).toMatch(/\/actions\/confirm$/);
  await expect(page.getByRole('button', { name: 'Cancel delivery' })).toBeVisible();
  await axe(page);
});

test('sales orders: a firm delivery is cancelled only with a reason; a viewer only reads (also in PPIC)', async ({ page }) => {
  let order: Record<string, unknown> = {
    ...ORDER,
    status: 'CONFIRMED',
    version: 7,
    lineCount: 1,
    customerPoNos: 'PO-5501',
    confirmedAt: '2026-11-12T07:10:00Z',
    lines: [LINE],
  };
  const writes: { url: string; body: unknown; ifMatch: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(PLANNER));
  await mockApi(page, '/query/core.customer_items?*', page1([]));
  await page.route('**/api/v1/demand/sales-orders/**', (route) => {
    const req = route.request();
    if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: order }) });
    writes.push({ url: req.url(), body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    const cancelled = {
      ...LINE.schedules[0],
      status: 'CANCELLED',
      outstandingQty: '0',
      cancelReason: 'Customer moved the call-off',
      cancelledByName: 'Planner',
    };
    order = { ...order, version: 8, lineCount: 0, lines: [{ ...LINE, status: 'CANCELLED', qty: '0', schedules: [cancelled] }] };
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: order }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/sales-orders?order=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'SO-P1-2026-0001' })).toBeVisible();
  await page.getByRole('button', { name: 'Cancel delivery' }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Cancel this delivery' });
  await dialog.getByRole('button', { name: 'Cancel delivery' }).click();
  await expect(dialog.getByText('Enter at least 3 characters')).toBeVisible();
  expect(writes).toHaveLength(0);
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Customer moved the call-off');
  await dialog.getByRole('button', { name: 'Cancel delivery' }).click();
  await expect(page.getByText('Delivery cancelled')).toBeVisible();
  expect(writes[0]).toMatchObject({ ifMatch: '"7"', body: { reason: 'Customer moved the call-off' } });
  expect(writes[0]!.url).toMatch(new RegExp(`/schedule-lines/${ID(14)}/actions/cancel$`));
  await expect(page.getByText('Customer moved the call-off · Planner')).toBeVisible();
  await axe(page);

  // a viewer (e.g. finished-goods warehouse) reads the order, nothing more
  await mockApi(page, '/auth/me', ME(['demand.sales_order.view']));
  await page.goto(`/demand/sales-orders?order=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'SO-P1-2026-0001' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add line' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Add delivery' })).toHaveCount(0);
});

import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 8 (doc 33 §1.8, D-117, BR-PLN-03): work order close and its exception against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[], userId = ID(90)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });
const PLANNER = [
  'planning.wo.view',
  'planning.wo.create',
  'planning.wo.submit',
  'planning.wo.post',
  'planning.wo_close_exception.view',
  'planning.wo_close_exception.request_correction',
];
const APPROVER = ['planning.wo.view', 'planning.wo_close_exception.view', 'planning.wo_close_exception.approve'];

const wo = (over: Record<string, unknown> = {}) => ({
  id: ID(1),
  code: 'WO-P1-2026-0002',
  version: 5,
  status: 'IN_PROGRESS',
  plantId: ID(2),
  plantCode: 'P1',
  itemId: ID(3),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  qty: '1000',
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
  bomVersionNo: 1,
  routingCode: 'RA100',
  routingVersionNo: 1,
  standardYield: '0.98',
  createdAt: '2026-11-12T07:00:00Z',
  createdByName: 'Planner',
  submittedById: ID(91),
  submittedByName: 'Planner',
  submittedAt: '2026-11-12T07:05:00Z',
  releasedByName: 'Approver',
  releasedAt: '2026-11-12T08:00:00Z',
  returnReason: null,
  cancelledByName: null,
  cancelReason: null,
  materials: [],
  plantToday: '2026-11-15',
  closeBlockers: ['material is still at production; return it, or ask for a close exception.'],
  atProduction: [{ locationCode: 'MAP-PRS-200', itemCode: 'COIL-SPCC-12', lotNo: 'L2611-0001', qty: '15', uomCode: 'KG' }],
  closedAt: null,
  closedByName: null,
  closeException: null,
  ...over,
});

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('the planner sees what keeps a work order open and asks a close exception for the material left', async ({ page }) => {
  const asked: { body: unknown; key: string | undefined }[] = [];
  let current: Record<string, unknown> = wo();
  await mockApi(page, '/auth/me', ME(PLANNER));
  await page.route(/\/api\/v1\/planning\/work-orders\/[^/]+$/, (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', headers: { ETag: '"5"' }, body: JSON.stringify({ data: current }) }),
  );
  await page.route('**/api/v1/planning/work-orders/*/close-exceptions', (route) => {
    asked.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    current = wo({
      closeBlockers: [
        'material is still at production; return it, or ask for a close exception.',
        'a return or close exception is still waiting for its decision.',
      ],
      closeException: {
        id: ID(40),
        status: 'SUBMITTED',
        docNo: null,
        reason: 'Coil end too short to use',
        rejectReason: null,
        requestedById: ID(90),
        requestedByName: 'Planner',
        decidedByName: null,
        at: '2026-11-15T03:00:00Z',
      },
    });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(40), replayed: false, status: 'SUBMITTED' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/work-orders?order=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'WO-P1-2026-0002' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Close work order' })).toBeDisabled();
  await expect(page.getByRole('list', { name: 'What keeps it open' })).toContainText('material is still at production');
  await expect(page.getByText('COIL-SPCC-12')).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Ask close exception' }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Ask close exception' });
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Coil end too short to use');
  await dialog.getByRole('button', { name: 'Ask close exception' }).click();
  await expect(page.getByText('Close exception asked; it waits for a second person')).toBeVisible();
  expect(asked).toHaveLength(1);
  expect(asked[0]!.key).toBeTruthy();
  expect(asked[0]!.body).toEqual({ reason: 'Coil end too short to use', scrapLocationId: null });
  await expect(page.getByRole('button', { name: 'Ask close exception' })).toHaveCount(0);
});

test('the approver decides the exception; the planner then closes the work order', async ({ page }) => {
  const approvals: string[] = [];
  const closes: (string | undefined)[] = [];
  let current: Record<string, unknown> = wo({
    closeBlockers: ['a return or close exception is still waiting for its decision.'],
    closeException: {
      id: ID(40),
      status: 'SUBMITTED',
      docNo: null,
      reason: 'Coil end too short to use',
      rejectReason: null,
      requestedById: ID(91),
      requestedByName: 'Planner',
      decidedByName: null,
      at: '2026-11-15T03:00:00Z',
    },
  });
  await mockApi(page, '/auth/me', ME(APPROVER, ID(92)));
  await page.route(/\/api\/v1\/planning\/work-orders\/[^/]+$/, (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', headers: { ETag: '"5"' }, body: JSON.stringify({ data: current }) }),
  );
  await page.route('**/api/v1/planning/wo-close-exceptions/*/approve', (route) => {
    approvals.push(route.request().url());
    current = wo({
      closeBlockers: [],
      atProduction: [],
      closeException: { ...(current.closeException as object), status: 'POSTED', docNo: 'WCX-P1-2026-0001' },
    });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(40), docNo: 'WCX-P1-2026-0001', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/work-orders?order=${ID(1)}`);
  await expect(page.getByText('A close exception waits for its decision')).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect.poll(() => approvals.length).toBe(1);
  await expect(page.getByText('Nothing keeps it open: no open run and no material left at production.')).toBeVisible();

  // the planner closes it
  await mockApi(page, '/auth/me', ME(PLANNER));
  await page.route('**/api/v1/planning/work-orders/*/actions/close', (route) => {
    closes.push(route.request().headers()['if-match']);
    current = wo({ status: 'CLOSED', version: 6, closeBlockers: [], atProduction: [], closedAt: '2026-11-15T04:00:00Z', closedByName: 'Planner' });
    return route.fulfill({ status: 200, contentType: 'application/json', headers: { ETag: '"6"' }, body: JSON.stringify({ data: current }) });
  });
  await page.reload();
  await page.getByRole('button', { name: 'Close work order' }).click();
  await page.getByRole('alertdialog', { name: 'Close this work order' }).getByRole('button', { name: 'Close work order' }).click();
  await expect(page.getByText('WO-P1-2026-0002 closed')).toBeVisible();
  expect(closes).toEqual(['"5"']);
});

import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M5 step 3 (doc 34 §1.3, D-123, D-046): PPIC → Master Production Schedule against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

const weeks = Array.from({ length: 12 }, (_, w) => new Date(Date.UTC(2026, 10, 16 + 7 * w)).toISOString().slice(0, 10));

const mps = (status: string, inputsChanged: boolean | null) => ({
  id: ID(1),
  code: 'MPS-P1-2026-0001',
  version: 4,
  status,
  plantId: ID(2),
  plantCode: 'P1',
  horizonStart: '2026-11-16',
  horizonEnd: '2027-02-07',
  horizonWeeks: 12,
  planDate: '2026-11-16',
  itemCount: 1,
  plannedOrderCount: 1,
  plannedQty: '270',
  adjustedCount: 0,
  overloadCount: 0,
  note: null,
  returnReason: null,
  calculatedAt: '2026-11-16T02:00:00Z',
  calculatedById: ID(9),
  calculatedByName: 'Planner',
  submittedAt: status === 'DRAFT' ? null : '2026-11-16T02:10:00Z',
  submittedByName: status === 'DRAFT' ? null : 'Planner',
  approvedAt: null,
  approvedByName: null,
  createdAt: '2026-11-16T02:00:00Z',
  inputsChanged,
  items: [
    {
      id: ID(11),
      itemId: ID(21),
      itemCode: 'BRK-A100',
      itemName: 'Bracket',
      uomCode: 'PCS',
      uomDecimals: 0,
      onHand: '180',
      outOfScopeAllocated: '0',
      safetyStock: null,
      pendingQc: '80',
      leadTimeDays: null,
      buckets: weeks.map((w, i) => ({
        weekStart: w,
        opening: i === 0 ? '180' : i <= 2 ? '30' : '0',
        firm: i === 0 ? '100' : '0',
        forecast: i === 0 ? '50' : i === 2 ? '300' : '0',
        receipts: '0',
        netRequirement: i === 2 ? '270' : '0',
        plannedQty: i === 2 ? '270' : '0',
        closing: i <= 1 ? '30' : '0',
      })),
      demands: [
        { id: ID(31), kind: 'FIRM', reference: 'SO-P1-2026-0001 / 1.1', customerCode: 'OEM-A', dueDate: '2026-11-19', originalDate: '2026-11-19', qty: '100' },
        { id: ID(32), kind: 'FORECAST', reference: 'FC-P1-2026-0001', customerCode: 'OEM-A', dueDate: '2026-11-30', originalDate: '2026-11-30', qty: '300' },
      ],
      supplies: [],
      plannedOrders: [
        {
          id: ID(41),
          weekStart: '2026-11-30',
          calcQty: '270',
          calcDueDate: '2026-11-30',
          qty: '270',
          dueDate: '2026-11-30',
          startDate: '2026-11-30',
          warnings: ['LEAD_TIME_NOT_CONFIGURED'],
          adjusted: false,
          adjustReason: null,
          adjustedByName: null,
          pegs: [{ demandId: ID(32), qty: '270' }],
        },
      ],
    },
  ],
  capacity: [
    {
      workCenterId: ID(51),
      workCenterCode: 'PRS-01',
      workCenterName: 'Press line',
      weekStart: '2026-11-30',
      loadHours: '0.67',
      availableHours: null,
      loadPercent: null,
      status: 'NOT_CONFIGURED',
      warnings: [],
    },
  ],
});

test('an approver sees the time-phased plan, Before Check provisional, and approves it with If-Match', async ({ page }) => {
  const posts: unknown[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.mps.view', 'planning.mps.approve'] } });
  await page.route(`**/api/v1/planning/mps-versions/${ID(1)}/actions/approve`, (route) => {
    posts.push(route.request().headers()['if-match']);
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...mps('APPROVED', null), version: 5 } }) });
  });
  await mockApi(page, `/planning/mps-versions/${ID(1)}`, { status: 200, json: { data: mps('SUBMITTED', false) } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/mps?version=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'MPS-P1-2026-0001' })).toBeVisible();
  const grid = page.getByRole('table', { name: 'BRK-A100 projected balance per week' });
  await expect(grid.getByRole('columnheader', { name: /W49/ })).toBeVisible();
  await expect(grid.getByRole('row', { name: /^Planned/ })).toContainText('270');
  await expect(page.getByText('Before Check (provisional)')).toBeVisible();
  await expect(page.getByRole('table', { name: 'BRK-A100 planned orders' })).toContainText('Forecast FC-P1-2026-0001');
  await expect(page.getByText('Capacity not configured')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Adjust' })).toHaveCount(0);
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await page.getByRole('alertdialog').getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('MPS-P1-2026-0001 approved')).toBeVisible();
  expect(posts).toEqual(['"4"']);
});

test('approval waits while the data changed since the calculation', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.mps.view', 'planning.mps.approve'] } });
  await mockApi(page, `/planning/mps-versions/${ID(1)}`, { status: 200, json: { data: mps('SUBMITTED', true) } });
  await page.goto(`/ppic/mps?version=${ID(1)}`);
  await expect(page.getByText('Data changed since this calculation')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve' })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Return' })).toBeEnabled();
});

test('the planner adjusts a planned order of a draft with a reason (mobile)', async ({ page }) => {
  const patches: unknown[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.mps.view', 'planning.mps.create', 'planning.mps.submit'] } });
  await page.route(`**/api/v1/planning/mps-versions/${ID(1)}/planned-orders/${ID(41)}`, (route) => {
    patches.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: mps('DRAFT', false) }) });
  });
  await mockApi(page, `/planning/mps-versions/${ID(1)}`, { status: 200, json: { data: mps('DRAFT', false) } });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/ppic/mps?version=${ID(1)}`);
  await expect(page.getByRole('button', { name: 'Submit for approval' })).toBeVisible();
  await page.getByRole('button', { name: 'Adjust' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('textbox', { name: 'Quantity (PCS)' }).fill('300');
  await dialog.getByRole('button', { name: 'Adjust planned order' }).click();
  await expect(dialog.getByText('Give the reason in at least 3 characters.')).toBeVisible();
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Full die run of 300');
  await dialog.getByRole('button', { name: 'Adjust planned order' }).click();
  await expect(page.getByText('Planned order adjusted')).toBeVisible();
  expect(patches).toEqual([{ ifMatch: '"4"', body: { qty: '300', dueDate: '2026-11-30', reason: 'Full die run of 300' } }]);
  const width = await page.evaluate(() => document.documentElement.scrollWidth);
  expect(width).toBeLessThanOrEqual(390);
  await axe(page);
});

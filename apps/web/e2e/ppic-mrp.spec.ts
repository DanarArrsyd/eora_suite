import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M5 step 4 (doc 34 §1.4, D-124): PPIC → MRP Results and Run MRP from an approved MPS, against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

const run = {
  id: ID(1),
  code: 'MRP-P1-2026-0001',
  plantId: ID(2),
  plantCode: 'P1',
  mpsVersionId: ID(3),
  mpsNo: 'MPS-P1-2026-0001',
  mpsStatus: 'APPROVED',
  planDate: '2026-11-16',
  horizonStart: '2026-11-16',
  horizonEnd: '2027-02-07',
  plannedOrderCount: 2,
  shortageCount: 1,
  shortItemCount: 1,
  createdAt: '2026-11-16T03:00:00Z',
  createdByName: 'Planner',
  version: 1,
  items: [
    {
      id: ID(11),
      itemId: ID(21),
      itemCode: 'BRK-A100',
      itemName: 'Bracket',
      itemType: 'FINISHED',
      uomCode: 'PCS',
      kind: 'MAKE',
      level: 0,
      onHand: '0',
      committed: '0',
      safetyStock: null,
      leadTimeDays: null,
      requirements: [],
      supplies: [],
      orders: [
        {
          id: ID(31),
          kind: 'MAKE',
          source: 'MPS',
          weekStart: '2026-11-30',
          qty: '680',
          dueDate: '2026-11-30',
          startDate: '2026-11-26',
          bomVersionNo: 2,
          warnings: [],
          pegs: [],
        },
      ],
    },
    {
      id: ID(12),
      itemId: ID(22),
      itemCode: 'PLT-C300',
      itemName: 'Blank',
      itemType: 'SEMI_FINISHED',
      uomCode: 'PCS',
      kind: 'MAKE',
      level: 1,
      onHand: '80',
      committed: '0',
      safetyStock: null,
      leadTimeDays: 1,
      requirements: [{ id: ID(41), dueDate: '2026-11-26', qty: '680', parentOrderId: ID(31), parentItemCode: 'BRK-A100' }],
      supplies: [],
      orders: [
        {
          id: ID(32),
          kind: 'MAKE',
          source: 'MRP',
          weekStart: '2026-11-23',
          qty: '600',
          dueDate: '2026-11-26',
          startDate: '2026-11-25',
          bomVersionNo: 1,
          warnings: [],
          pegs: [{ requirementId: ID(41), qty: '600' }],
        },
      ],
    },
    {
      id: ID(13),
      itemId: ID(23),
      itemCode: 'COIL-SPCC-12',
      itemName: 'Coil SPCC 1.2',
      itemType: 'RAW',
      uomCode: 'KG',
      kind: 'BUY',
      level: 2,
      onHand: '120',
      committed: '40',
      safetyStock: '50',
      leadTimeDays: 3,
      requirements: [{ id: ID(42), dueDate: '2026-11-25', qty: '192', parentOrderId: ID(32), parentItemCode: 'PLT-C300' }],
      supplies: [{ kind: 'INBOUND', reference: 'INB-P1-2026-0001', date: '2026-11-20', qty: '30' }],
      orders: [
        {
          id: ID(33),
          kind: 'BUY',
          source: 'MRP',
          weekStart: '2026-11-23',
          qty: '132',
          dueDate: '2026-11-25',
          startDate: '2026-11-21',
          bomVersionNo: null,
          warnings: [],
          pegs: [{ requirementId: ID(42), qty: '132' }],
        },
      ],
    },
  ],
};

test('an MRP run lists the material shortages with need and order-by dates, and the planned work orders', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.mrp.view'] } });
  await mockApi(page, `/planning/mrp-runs/${ID(1)}`, { status: 200, json: { data: run } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/mrp?run=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'MRP-P1-2026-0001' })).toBeVisible();
  const short = page.getByRole('table', { name: 'Material shortages' });
  await expect(short.getByRole('row', { name: /COIL-SPCC-12/ })).toContainText('132 KG');
  await expect(short.getByRole('row', { name: /COIL-SPCC-12/ })).toContainText('2026-11-25');
  await expect(short.getByRole('row', { name: /COIL-SPCC-12/ })).toContainText('2026-11-21');
  await expect(short.getByRole('row', { name: /COIL-SPCC-12/ })).toContainText('PLT-C300 · 2026-11-25 · 132');
  const make = page.getByRole('table', { name: 'Planned work orders' });
  await expect(make.getByRole('row', { name: /^PLT-C300/ })).toContainText('600 PCS');
  await expect(make.getByRole('row', { name: /^BRK-A100/ })).toContainText('MPS');
  await axe(page);
});

test('the planner runs MRP from the approved MPS and lands on the run (mobile)', async ({ page }) => {
  const posts: unknown[] = [];
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.mps.view', 'planning.mrp.view', 'planning.mrp.create'] } });
  await mockApi(page, `/planning/mps-versions/${ID(3)}`, {
    status: 200,
    json: {
      data: {
        id: ID(3),
        code: 'MPS-P1-2026-0001',
        version: 6,
        status: 'APPROVED',
        plantId: ID(2),
        plantCode: 'P1',
        horizonStart: '2026-11-16',
        horizonEnd: '2027-02-07',
        horizonWeeks: 12,
        planDate: '2026-11-16',
        itemCount: 0,
        plannedOrderCount: 0,
        plannedQty: '0',
        adjustedCount: 0,
        overloadCount: 0,
        note: null,
        returnReason: null,
        calculatedAt: '2026-11-16T02:00:00Z',
        calculatedById: ID(9),
        calculatedByName: 'Planner',
        submittedAt: '2026-11-16T02:10:00Z',
        submittedByName: 'Planner',
        approvedAt: '2026-11-16T02:20:00Z',
        approvedByName: 'Approver',
        createdAt: '2026-11-16T02:00:00Z',
        inputsChanged: null,
        items: [],
        capacity: [],
      },
    },
  });
  await page.route('**/api/v1/planning/mrp-runs', (route) => {
    posts.push(route.request().postDataJSON());
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: run }) });
  });
  await mockApi(page, `/planning/mrp-runs/${ID(1)}`, { status: 200, json: { data: run } });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`/ppic/mps?version=${ID(3)}`);
  await page.getByRole('button', { name: 'Run MRP' }).click();
  await expect(page).toHaveURL(new RegExp(`/ppic/mrp\\?run=${ID(1)}`));
  await expect(page.getByRole('heading', { level: 1, name: 'MRP-P1-2026-0001' })).toBeVisible();
  expect(posts).toEqual([{ mpsVersionId: ID(3) }]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await axe(page);
});

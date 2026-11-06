import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 6 (doc 32 §1.6, doc 04 row 12, D-060, D-100): Inventory → Adjustments against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-06T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const LINE = {
  id: ID(1),
  plantId: ID(2),
  plantCode: 'P1',
  locationId: ID(3),
  locationCode: 'RM-A01',
  itemId: ID(4),
  itemCode: 'SHEET-SPCC-12',
  itemName: 'SPCC sheet 1.2 mm',
  lotId: ID(5),
  lotNo: 'L2611-0001',
  stockStatus: 'UNRESTRICTED',
  qty: '120',
  uomCode: 'KG',
  uomDecimals: 3,
  version: 1,
};
const REQUEST = {
  id: ID(7),
  code: null,
  version: 1,
  status: 'SUBMITTED',
  plantId: LINE.plantId,
  plantCode: 'P1',
  effectiveDate: '2026-11-06',
  reasonCode: 'DAMAGE',
  note: 'Sheet edge bent by forklift',
  direction: 'MINUS',
  itemId: LINE.itemId,
  itemCode: LINE.itemCode,
  itemName: LINE.itemName,
  lotNo: LINE.lotNo,
  locationCode: 'RM-A01',
  stockStatus: 'UNRESTRICTED',
  qty: '15',
  uomCode: 'KG',
  requestedById: ID(8),
  requestedByName: 'Inventory controller',
  submittedAt: '2026-11-06T09:00:00Z',
  decidedByName: null,
  decidedAt: null,
  rejectReason: null,
};
const ME = (permissions: string[], userId = ID(9)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('adjustments: inventory control requests one stock line with a reason; nothing is posted yet', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.adjustment.view', 'inventory.adjustment.create', 'inventory.stock.view']));
  await mockApi(page, '/query/inventory.adjustments?*', page1([]));
  await mockApi(page, '/query/inventory.stock?*', page1([LINE]));
  const calls: { body: unknown; key: string | undefined }[] = [];
  await page.route('**/api/v1/inventory/adjustments', (route) => {
    calls.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(7), status: 'SUBMITTED', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/inventory/adjustments');
  await expect(page.getByRole('heading', { level: 1, name: 'Adjustments' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await axe(page);

  await page.getByRole('button', { name: 'New adjustment request' }).click();
  const drawer = page.getByRole('dialog', { name: 'New adjustment request' });
  await choose(page, drawer.getByRole('combobox', { name: 'Stock line' }), 'SHEET-SPCC-12 · L2611-0001 · P1 / RM-A01 · Unrestricted · 120 KG');
  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('200');
  await choose(page, drawer.getByRole('combobox', { name: 'Reason' }), 'Other (explain in the note)');
  await drawer.getByRole('button', { name: 'Request adjustment' }).click();
  await expect(drawer).toContainText('Only 120 KG is on hand there.');
  await expect(drawer).toContainText('Explain the adjustment when the reason is Other.');
  expect(calls).toHaveLength(0);

  await drawer.getByRole('textbox', { name: 'Quantity' }).fill('15');
  await choose(page, drawer.getByRole('combobox', { name: 'Reason' }), 'Damage');
  await drawer.getByRole('textbox', { name: 'Note' }).fill('Sheet edge bent by forklift');
  await drawer.getByRole('button', { name: 'Request adjustment' }).click();
  await page.getByRole('alertdialog', { name: 'Request this adjustment' }).getByRole('button', { name: 'Request adjustment' }).click();
  await expect(page.getByText('Adjustment requested; it waits for a second person to approve it')).toBeVisible();
  expect(calls[0]!.key).toMatch(/^[A-Za-z0-9._:-]{8,}$/);
  expect(calls[0]!.body).toEqual({
    plantId: LINE.plantId,
    itemId: LINE.itemId,
    lotId: LINE.lotId,
    locationId: LINE.locationId,
    stockStatus: 'UNRESTRICTED',
    direction: 'MINUS',
    qty: '15',
    reasonCode: 'DAMAGE',
    note: 'Sheet edge bent by forklift',
  });
});

test('adjustments: an approver without the view permission reads the request and approves it on its page', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.adjustment.approve']));
  await mockApi(page, '/query/inventory.adjustments?*', page1([REQUEST]));
  const approvals: (string | undefined)[] = [];
  await page.route(`**/api/v1/inventory/adjustments/${REQUEST.id}/approve`, (route) => {
    approvals.push(route.request().headers()['idempotency-key']);
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { documentId: REQUEST.id, docNo: 'ADJ-P1-2026-0001', productionDate: '2026-11-06', replayed: false, lotId: null, lotNo: null },
      }),
    });
  });

  await page.goto('/inventory/adjustments');
  await page.getByRole('button', { name: /^Open adjustment request SHEET-SPCC-12/ }).click();
  await expect(page).toHaveURL(new RegExp(`request=${REQUEST.id}`));
  await expect(page.getByRole('heading', { level: 1, name: 'SHEET-SPCC-12 adjustment request' })).toBeVisible();
  await expect(page.getByText('−15 KG')).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Adjustment approved and posted')).toBeVisible();
  expect(approvals[0]).toMatch(/^adj-approve-/);
});

test('adjustments: the requester sees its request waiting and has no decision to make', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.adjustment.view', 'inventory.adjustment.approve'], REQUEST.requestedById));
  await mockApi(page, '/query/inventory.adjustments?*', page1([REQUEST]));
  await page.goto(`/inventory/adjustments?request=${REQUEST.id}`);
  await expect(page.getByText('You requested this adjustment')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Reject' })).toHaveCount(0);
});

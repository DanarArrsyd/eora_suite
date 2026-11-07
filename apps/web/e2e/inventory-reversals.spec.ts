import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 7 (doc 32 §1.7, doc 04 row 13, D-060, D-101): Inventory → Reversals against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-07T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const DOCUMENT = {
  id: ID(1),
  code: 'ADJ-P1-2026-0001',
  docType: 'STOCK_ADJUSTMENT',
  status: 'POSTED',
  plantCode: 'P1',
  effectiveDate: '2026-11-06',
  reversible: true,
  version: 1,
};
const REQUEST = {
  id: ID(2),
  code: null,
  version: 1,
  status: 'SUBMITTED',
  plantId: ID(3),
  plantCode: 'P1',
  effectiveDate: '2026-11-07',
  reason: 'Damage was on another sheet',
  originalId: DOCUMENT.id,
  originalCode: DOCUMENT.code,
  originalType: 'STOCK_ADJUSTMENT',
  originalDate: '2026-11-06',
  requestedById: ID(8),
  requestedByName: 'Materials PIC',
  submittedAt: '2026-11-07T01:00:00Z',
  decidedByName: null,
  decidedAt: null,
  rejectReason: null,
};
const LEGS = [
  {
    id: ID(5),
    code: DOCUMENT.code,
    version: 1,
    legNo: 1,
    movementCode: 'ADJ_MINUS',
    locationCode: 'RM-A01',
    locationType: 'RM_WAREHOUSE',
    itemCode: 'SHEET-SPCC-12',
    lotNo: 'L2611-0001-01',
    stockStatus: 'UNRESTRICTED',
    qty: '-15',
    uomCode: 'KG',
  },
  {
    id: ID(6),
    code: DOCUMENT.code,
    version: 1,
    legNo: 2,
    movementCode: 'ADJ_MINUS',
    locationCode: 'V_ADJUSTMENT',
    locationType: 'V_ADJUSTMENT',
    itemCode: 'SHEET-SPCC-12',
    lotNo: 'L2611-0001-01',
    stockStatus: 'UNRESTRICTED',
    qty: '15',
    uomCode: 'KG',
  },
];
const ME = (permissions: string[], userId = ID(9)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('reversals: a correction requester picks a posted document and gives the reason; nothing is posted yet', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.reversal.request_correction', 'inventory.stock.view']));
  await mockApi(page, '/query/inventory.reversals?*', page1([]));
  const docsUrl = page.waitForRequest((r) => r.url().includes('/query/inventory.documents?'));
  await mockApi(page, '/query/inventory.documents?*', page1([DOCUMENT]));
  const calls: { body: unknown; key: string | undefined }[] = [];
  await page.route('**/api/v1/inventory/reversals', (route) => {
    calls.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(2), status: 'SUBMITTED', replayed: false } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/inventory/reversals');
  await expect(page.getByRole('heading', { level: 1, name: 'Reversals' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await axe(page);

  await page.getByRole('button', { name: 'New reversal request' }).click();
  const drawer = page.getByRole('dialog', { name: 'New reversal request' });
  await drawer.getByRole('textbox', { name: 'Document number' }).fill('ADJ-P1-2026-0009');
  await drawer.getByRole('textbox', { name: 'Reason' }).fill('x');
  await drawer.getByRole('button', { name: 'Request reversal' }).click();
  await expect(drawer).toContainText('No posted document ADJ-P1-2026-0009 in the plants you can see.');
  await expect(drawer).toContainText('Say why the document is wrong.');
  await drawer.getByRole('textbox', { name: 'Document number' }).fill('adj-p1-2026-0001');
  expect(decodeURIComponent((await docsUrl).url())).toContain('filter[code]=ADJ-P1-2026-0009');
  await drawer.getByRole('textbox', { name: 'Reason' }).fill('Damage was on another sheet');
  await drawer.getByRole('button', { name: 'Request reversal' }).click();
  await page.getByRole('alertdialog', { name: 'Request this reversal' }).getByRole('button', { name: 'Request reversal' }).click();
  await expect(page.getByText('Reversal requested; it waits for a second person to approve it')).toBeVisible();
  expect(calls[0]!.key).toMatch(/^[A-Za-z0-9._:-]{8,}$/);
  expect(calls[0]!.body).toEqual({ documentId: DOCUMENT.id, reason: 'Damage was on another sheet' });
});

test('reversals: the approver sees the legs that will be undone and approves; a blocked reversal says why', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.reversal.approve', 'inventory.stock.view']));
  await mockApi(page, '/query/inventory.reversals?*', page1([REQUEST]));
  await mockApi(page, '/query/inventory.movements?*', page1(LEGS));
  let attempt = 0;
  await page.route(`**/api/v1/inventory/reversals/${REQUEST.id}/approve`, (route) => {
    attempt++;
    return attempt === 1
      ? route.fulfill({
          status: 409,
          contentType: 'application/problem+json',
          body: JSON.stringify({
            type: 'about:blank',
            title: 'Stock has moved on',
            status: 409,
            code: 'REVERSAL_BLOCKED_BY_DOWNSTREAM',
            detail: 'Reverse what came after it first.',
          }),
        })
      : route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            data: { documentId: REQUEST.id, docNo: 'REV-P1-2026-0001', productionDate: '2026-11-07', replayed: false, lotId: null, lotNo: null },
          }),
        });
  });

  await page.goto(`/inventory/reversals?request=${REQUEST.id}`);
  await expect(page.getByRole('heading', { level: 1, name: 'Reversal of ADJ-P1-2026-0001' })).toBeVisible();
  const legs = page.getByRole('table', { name: 'Legs of ADJ-P1-2026-0001' });
  await expect(legs.getByRole('row', { name: /RM-A01/ })).toContainText('15 KG');
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Reverse what came after it first.')).toBeVisible();
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Reversal approved and posted')).toBeVisible();
});

test('reversals: the requester sees its request waiting and has no decision to make', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.reversal.request_correction', 'inventory.reversal.approve'], REQUEST.requestedById));
  await mockApi(page, '/query/inventory.reversals?*', page1([REQUEST]));
  await page.goto(`/inventory/reversals?request=${REQUEST.id}`);
  await expect(page.getByText('You requested this reversal')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
});

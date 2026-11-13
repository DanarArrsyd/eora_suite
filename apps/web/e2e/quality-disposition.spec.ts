import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 5 (doc 33 §1.5, D-113): Quality → Pending QC Queue and Dispositions against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-13T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[], id = SESSION.user.id) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id }, permissions } });
const QCI = ['quality.inspection.view', 'quality.inspection.post', 'quality.inspection.submit'];
const QCS = ['quality.inspection.view', 'quality.disposition_release.approve'];

const line = (status: string, qty: string, location = 'QC-IN', type = 'QC_AREA') => ({
  id: ID(1),
  code: 'BRK-A100',
  version: 1,
  plantId: ID(2),
  plantCode: 'P1',
  locationId: ID(3),
  locationCode: location,
  locationType: type,
  itemId: ID(4),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  lotId: ID(5),
  lotNo: 'L2611-0001',
  stockStatus: status,
  qty,
  uomCode: 'PCS',
  uomDecimals: 0,
  updatedAt: '2026-11-13T07:37:00Z',
});

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('pending QC: the inspector releases, holds and rejects parts of a lot; the rest keeps waiting', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(QCI));
  await mockApi(page, '/query/inventory.stock?*', page1([line('PENDING_QC', '950')]));
  await page.route('**/api/v1/quality/dispositions', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { documentId: ID(50), docNo: 'QCD-P1-2026-0001', replayed: false, lotId: ID(5), lotNo: 'L2611-0001' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/quality/pending-qc');
  await expect(page.getByRole('heading', { level: 1, name: 'Pending QC Queue' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await page.getByRole('button', { name: 'Open BRK-A100 L2611-0001 Pending QC at QC-IN' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-A100 · L2611-0001' })).toBeVisible();

  // nothing entered, a hold without its reason, more than waits: refused here, nothing sent
  await page.getByRole('button', { name: 'Post decision' }).click();
  await expect(page.getByText('Enter the quantity of at least one decision.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Release (PCS)' }).fill('900');
  await page.getByRole('textbox', { name: 'Hold (PCS)' }).fill('20');
  await page.getByRole('button', { name: 'Post decision' }).click();
  await expect(page.getByText('Hold: choose the reason.')).toBeVisible();
  await choose(page, page.getByRole('combobox', { name: 'Hold reason' }), 'Dimension out of tolerance');
  await page.getByRole('textbox', { name: 'Hold note' }).fill('Hole 0.2 mm off');
  await page.getByRole('textbox', { name: 'Reject (PCS)' }).fill('40');
  await choose(page, page.getByRole('combobox', { name: 'Reject reason' }), 'Crack');
  await page.getByRole('button', { name: 'Post decision' }).click();
  await expect(page.getByText('Only 950 PCS waits here.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Reject (PCS)' }).fill('10');
  await axe(page);
  await page.getByRole('button', { name: 'Post decision' }).click();
  await page.getByRole('alertdialog', { name: 'Post this decision' }).getByRole('button', { name: 'Post decision' }).click();
  await expect(page.getByText('QCD-P1-2026-0001 posted')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    stockLineId: ID(1),
    note: null,
    ngLocationId: null,
    lines: [
      { outcome: 'RELEASE', qty: '900', reasonCode: null, note: null },
      { outcome: 'HOLD', qty: '20', reasonCode: 'DIMENSION', note: 'Hole 0.2 mm off' },
      { outcome: 'REJECT', qty: '10', reasonCode: 'CRACK', note: null },
    ],
  });
});

test('a held quantity: only reject in policy; its release is asked of a second person', async ({ page }) => {
  const asked: unknown[] = [];
  await mockApi(page, '/auth/me', ME(QCI));
  await mockApi(page, '/query/inventory.stock?*', page1([line('HOLD', '20')]));
  await page.route('**/api/v1/quality/release-requests', (route) => {
    asked.push(route.request().postDataJSON());
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { documentId: ID(60), status: 'SUBMITTED' } }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/quality/pending-qc?line=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-A100 · L2611-0001' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Release (PCS)' })).toHaveCount(1);
  await expect(page.getByRole('textbox', { name: 'Reject (PCS)' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Hold (PCS)' })).toHaveCount(0);
  await page.getByRole('textbox', { name: 'Release (PCS)' }).fill('20');
  await page.getByRole('button', { name: 'Ask release' }).click();
  await expect(page.getByText('Give the reason.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Reason' }).fill('Customer accepted the deviation');
  await axe(page);
  await page.getByRole('button', { name: 'Ask release' }).click();
  await page.getByRole('alertdialog', { name: 'Ask this release' }).getByRole('button', { name: 'Ask release' }).click();
  await expect(page.getByText('Release asked; it waits for a second person (Dispositions)')).toBeVisible();
  expect(asked).toEqual([{ stockLineId: ID(1), qty: '20', reason: 'Customer accepted the deviation', qcLocationId: null }]);
});

test('dispositions: the supervisor approves a release asked by the inspector', async ({ page }) => {
  const approvals: (string | undefined)[] = [];
  const request = {
    id: ID(60),
    code: 'Release request',
    docNo: null,
    version: 1,
    status: 'SUBMITTED',
    outOfPolicy: true,
    plantCode: 'P1',
    itemCode: 'BRK-A100',
    itemName: 'Bracket front A100',
    lotNo: 'L2611-0001',
    fromStatus: 'HOLD',
    releasedQty: '20',
    holdQty: '0',
    reworkQty: '0',
    rejectQty: '0',
    uomCode: 'PCS',
    note: 'Customer accepted the deviation',
    requestedById: ID(70),
    requestedByName: 'Inspector',
    decidedByName: null,
    rejectReason: null,
    at: '2026-11-13T08:00:00Z',
  };
  let current: Record<string, unknown> = request;
  await mockApi(page, '/auth/me', ME(QCS, ID(71)));
  await page.route('**/api/v1/query/quality.dispositions?*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [current], meta: { ...META, total: 1 } }) }),
  );
  await page.route('**/api/v1/quality/release-requests/*/approve', (route) => {
    approvals.push(route.request().headers()['idempotency-key']);
    current = { ...request, status: 'POSTED', docNo: 'QCD-P1-2026-0002', decidedByName: 'Supervisor' };
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { documentId: ID(60), docNo: 'QCD-P1-2026-0002' } }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/quality/inspection-lots');
  await expect(page.getByRole('heading', { level: 1, name: 'Dispositions' })).toBeVisible();
  await expect(page.getByRole('table')).toContainText('Release 20');
  await page.getByRole('button', { name: 'Open Release request BRK-A100 L2611-0001' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-A100 release request' })).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'QCD-P1-2026-0002' })).toBeVisible();
  expect(approvals).toHaveLength(1);
  expect(approvals[0]).toBeTruthy();
});

test('dispositions: the supervisor decides the reversal of a posted disposition (D-113)', async ({ page }) => {
  const approvals: string[] = [];
  const posted = {
    id: ID(80),
    code: 'QCD-P1-2026-0001',
    docNo: 'QCD-P1-2026-0001',
    version: 1,
    status: 'POSTED',
    outOfPolicy: false,
    plantCode: 'P1',
    itemCode: 'BRK-A100',
    itemName: 'Bracket front A100',
    lotNo: 'L2611-0001',
    fromStatus: 'PENDING_QC',
    releasedQty: '0',
    holdQty: '10',
    reworkQty: '0',
    rejectQty: '0',
    uomCode: 'PCS',
    note: null,
    requestedById: null,
    requestedByName: null,
    decidedByName: 'Inspector',
    rejectReason: null,
    at: '2026-11-13T08:00:00Z',
    reasons: 'Hold: Visual (scratch, dent) — Scratch on flange',
    reversalId: ID(81),
    reversalReason: 'Held the wrong quantity',
    reversalRequestedById: ID(72),
    reversalRequestedByName: 'Inventory controller',
  };
  let current: Record<string, unknown> = posted;
  await mockApi(page, '/auth/me', ME(QCS, ID(71)));
  await page.route('**/api/v1/query/quality.dispositions?*', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [current], meta: { ...META, total: 1 } }) }),
  );
  await page.route('**/api/v1/inventory/reversals/*/approve', (route) => {
    approvals.push(route.request().url());
    current = { ...posted, status: 'REVERSED', reversalId: null };
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { documentId: ID(81), docNo: 'REV-P1-2026-0001' } }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/quality/inspection-lots?request=${ID(80)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'QCD-P1-2026-0001' })).toBeVisible();
  await expect(page.getByText('Held the wrong quantity (Inventory controller)')).toBeVisible();
  await expect(page.getByText('Hold: Visual (scratch, dent) — Scratch on flange')).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Approve' }).click();
  await expect(page.getByText('Reversed', { exact: true })).toBeVisible();
  expect(approvals).toEqual([expect.stringContaining(`/inventory/reversals/${ID(81)}/approve`)]);
});

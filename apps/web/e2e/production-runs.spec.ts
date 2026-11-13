import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M4 step 4 (doc 33 §1.4, D-111): Production → Production Runs against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-13T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const ME = (permissions: string[]) => ({ status: 200, json: { ...SESSION, permissions } });
const PRL = ['production.run.view', 'production.run.post', 'production.posting.view', 'production.posting.post', 'planning.wo.view'];

const RUN = {
  id: ID(1),
  code: 'RUN-P1-2026-0001',
  version: 1,
  status: 'OPEN',
  plantId: ID(2),
  plantCode: 'P1',
  workOrderId: ID(3),
  workOrderCode: 'WO-P1-2026-0002',
  workOrderStatus: 'IN_PROGRESS',
  itemId: ID(4),
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  uomCode: 'PCS',
  uomDecimals: 0,
  inspectionRequired: true,
  workCenterCode: 'PRS-200',
  machineCode: null,
  goodQty: '0',
  ngReworkQty: '0',
  ngRejectQty: '0',
  postings: 0,
  note: null,
  openedByName: 'Production lead',
  openedAt: '2026-11-13T07:37:00Z',
  closedAt: null,
  closedByName: null,
  cancelReason: null,
  plantToday: '2026-11-13',
  atWorkCenter: [
    { itemId: ID(20), itemCode: 'COIL-SPCC-12', itemName: 'SPCC coil', lotId: ID(30), lotNo: 'L2611-0001', qty: '335.5', uomCode: 'KG', uomDecimals: 3 },
    { itemId: ID(21), itemCode: 'NUT-M8', itemName: 'Weld nut M8', lotId: null, lotNo: null, qty: '2000', uomCode: 'PCS', uomDecimals: 0 },
  ],
};

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('production runs: the leader posts measured consumption, output and scrap on the run', async ({ page }) => {
  const writes: { body: unknown; key: string | undefined }[] = [];
  await mockApi(page, '/auth/me', ME(PRL));
  await mockApi(page, '/query/production.postings?*', page1([]));
  await mockApi(
    page,
    '/query/core.items?*',
    page1([{ id: ID(40), code: 'SCRAP-SPCC', name: 'SPCC scrap', baseUomCode: 'KG', baseUomDecimals: 3, status: 'ACTIVE', version: 1 }]),
  );
  await page.route('**/api/v1/production/runs/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: RUN }) }),
  );
  await page.route('**/api/v1/production/postings', (route) => {
    writes.push({ body: route.request().postDataJSON(), key: route.request().headers()['idempotency-key'] });
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        data: { documentId: ID(50), docNo: 'PRD-P1-2026-0001', productionDate: '2026-11-13', replayed: false, lotId: ID(51), lotNo: 'L2611-0001' },
      }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/production/runs?run=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'RUN-P1-2026-0001' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await expect(page.getByRole('table', { name: 'Material of WO-P1-2026-0002 at PRS-200' })).toContainText('335.5 KG');

  // nothing entered yet: the form says what is missing, and sends nothing
  await page.getByRole('button', { name: 'Post production', exact: true }).click();
  await expect(page.getByText('Enter what was consumed: at least one material line, measured.')).toBeVisible();

  await page.getByRole('button', { name: 'Add consumed material' }).click();
  await choose(page, page.getByRole('combobox', { name: 'Material and lot 1' }), 'COIL-SPCC-12 · L2611-0001 · 335.5 KG here');
  await choose(page, page.getByRole('combobox', { name: 'How measured 1' }), 'Weighed what is left');
  await page.getByRole('textbox', { name: 'Left 1 (KG)' }).fill('400');
  await page.getByRole('textbox', { name: 'Evidence 1' }).fill('Scale S-01');
  await page.getByRole('textbox', { name: 'Good (PCS)' }).fill('950');
  await page.getByRole('button', { name: 'Post production', exact: true }).click();
  await expect(page.getByText('COIL-SPCC-12 L2611-0001: what is left must be less than the 335.5 KG here.')).toBeVisible();
  await page.getByRole('textbox', { name: 'Left 1 (KG)' }).fill('15');
  await page.getByRole('textbox', { name: 'NG rework (PCS)' }).fill('20');
  await page.getByRole('button', { name: 'Add scrap' }).click();
  await choose(page, page.getByRole('combobox', { name: 'Scrap item 1' }), 'SCRAP-SPCC · SPCC scrap');
  await page.getByRole('textbox', { name: 'Weight 1 (KG)' }).fill('12');
  await choose(page, page.getByRole('combobox', { name: 'Reason 1' }), 'Skeleton / web');
  await axe(page);
  await page.getByRole('button', { name: 'Post production', exact: true }).click();
  await page.getByRole('alertdialog', { name: 'Post this production' }).getByRole('button', { name: 'Post production' }).click();
  await expect(page.getByText('PRD-P1-2026-0001 posted, output lot L2611-0001')).toBeVisible();
  expect(writes).toHaveLength(1);
  expect(writes[0]!.key).toBeTruthy();
  expect(writes[0]!.body).toEqual({
    runId: ID(1),
    consumptions: [{ itemId: ID(20), lotId: ID(30), method: 'WEIGHED_REMAINING', qty: '15', evidence: 'Scale S-01' }],
    goodQty: '950',
    ngReworkQty: '20',
    ngRejectQty: null,
    scrap: [{ itemId: ID(40), qty: '12', reasonCode: 'SKELETON', note: null }],
    correctsPostingId: null,
    note: null,
  });
});

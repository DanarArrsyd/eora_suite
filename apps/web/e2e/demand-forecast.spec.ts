import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M5 step 2 (doc 34 §1.2, D-122, D-040): Demand → Forecast against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

const version = (status: string) => ({
  id: ID(1),
  code: 'FC-P1-2026-0001',
  version: 3,
  status,
  plantId: ID(2),
  plantCode: 'P1',
  customerId: ID(3),
  customerCode: 'OEM-A',
  customerName: 'Sample OEM customer A',
  lineCount: 2,
  totalQty: '800',
  firstWeek: '2026-11-23',
  lastWeek: '2026-11-30',
  note: null,
  activatedAt: null,
  activatedByName: null,
  createdAt: '2026-11-15T03:00:00Z',
  createdByName: 'Planner',
  lines: [
    {
      id: ID(11),
      customerItemId: ID(21),
      customerPartNo: 'A-77101-BK',
      itemCode: 'BRK-A100',
      itemName: 'Bracket',
      uomCode: 'PCS',
      uomDecimals: 0,
      weekStart: '2026-11-23',
      qty: '500',
      firmQty: '120',
      netQty: '380',
    },
    {
      id: ID(12),
      customerItemId: ID(21),
      customerPartNo: 'A-77101-BK',
      itemCode: 'BRK-A100',
      itemName: 'Bracket',
      uomCode: 'PCS',
      uomDecimals: 0,
      weekStart: '2026-11-30',
      qty: '300',
      firmQty: '80',
      netQty: '220',
    },
  ],
});

test('an active forecast shows forecast, firm orders and net per week, read-only', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['demand.forecast.view'] } });
  await mockApi(page, `/demand/forecast-versions/${ID(1)}`, { status: 200, json: { data: version('ACTIVE') } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/demand/forecast?version=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'FC-P1-2026-0001' })).toBeVisible();
  const table = page.getByRole('table', { name: 'Forecast and firm demand per week' });
  await expect(table.getByRole('row', { name: /2026-11-23/ })).toContainText('500 PCS');
  await expect(table.getByRole('row', { name: /2026-11-23/ })).toContainText('120');
  await expect(table.getByRole('row', { name: /2026-11-23/ })).toContainText('380');
  await expect(page.getByRole('button', { name: 'Activate' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Save lines' })).toHaveCount(0);
  await axe(page);
});

test('a draft forecast is edited by the planner: lines saved with If-Match, then activated', async ({ page }) => {
  const puts: unknown[] = [];
  await mockApi(page, '/auth/me', {
    status: 200,
    json: { ...SESSION, permissions: ['demand.forecast.view', 'demand.forecast.create', 'demand.forecast.post', 'master.part.view'] },
  });
  await mockApi(page, '/query/core.customer_items?*', {
    status: 200,
    json: {
      data: [{ id: ID(21), code: 'A-77101-BK', itemCode: 'BRK-A100', itemName: 'Bracket', version: 1 }],
      meta: { total: 1, page: { number: 1, size: 100 }, asOf: '2026-11-15T02:00:00Z', timezone: 'Asia/Jakarta' },
    },
  });
  await page.route(`**/api/v1/demand/forecast-versions/${ID(1)}/lines`, (route) => {
    puts.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: version('DRAFT') }) });
  });
  await mockApi(page, `/demand/forecast-versions/${ID(1)}`, { status: 200, json: { data: version('DRAFT') } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/demand/forecast?version=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'FC-P1-2026-0001' })).toBeVisible();
  await page.getByRole('textbox', { name: 'Line 2 quantity' }).fill('350');
  await expect(page.getByRole('button', { name: 'Activate' })).toBeDisabled();
  await page.getByRole('button', { name: 'Save lines' }).click();
  await expect(page.getByText('Forecast lines saved')).toBeVisible();
  expect(puts).toHaveLength(1);
  expect(puts[0]).toEqual({
    ifMatch: '"3"',
    body: {
      lines: [
        { customerItemId: ID(21), weekStart: '2026-11-23', qty: '500' },
        { customerItemId: ID(21), weekStart: '2026-11-30', qty: '350' },
      ],
    },
  });
  await axe(page);
});

import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* SPK per Line (doc 38, D-138), slice 1: the planner plans and releases; production reads the released SPK. Mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const META = { total: 1, page: { number: 1, size: 20 }, asOf: '2026-11-19T02:00:00Z', timezone: 'Asia/Jakarta' };

const LINE = {
  id: ID(20),
  lineNo: 1,
  workOrderId: ID(30),
  workOrderCode: 'WO-P1-2026-0003',
  workOrderStatus: 'IN_PROGRESS',
  itemCode: 'BRK-A100',
  itemName: 'Bracket front A100',
  workOrderQty: '300',
  uomCode: 'PCS',
  dueDate: '2026-11-21',
  routingOperationId: ID(40),
  opSeq: 10,
  opCount: 2,
  processName: 'Blanking',
  machineId: ID(50),
  machineCode: 'M-PRS200',
  machineName: 'Mechanical press 200 t',
  shiftId: null,
  shiftCode: null,
  plannedQty: '300',
  priority: 1,
  setupMinutes: '0',
  qcSampleMinutes: '5',
  note: null,
  sph: '900',
  hours: '0.417',
};
const SPK = {
  id: ID(10),
  code: null as string | null,
  version: 1,
  status: 'DRAFT',
  plantId: SESSION.plants[0]!.id,
  plantCode: 'P1',
  workCenterId: ID(60),
  workCenterCode: 'PRS-200',
  workCenterName: 'Press line 200 t',
  productionDate: '2026-11-19',
  revision: 1,
  previousId: null,
  reference: null,
  note: null,
  revisionReason: null,
  lineCount: 0,
  releasedAt: null as string | null,
  releasedByName: null as string | null,
  lines: [] as (typeof LINE)[],
  machineLoad: [] as unknown[],
  hoursPerShift: '7.5',
  revisions: [{ id: ID(10), revision: 1, status: 'DRAFT', reason: null }],
};
const LOAD = [
  {
    machineId: ID(50),
    machineCode: 'M-PRS200',
    machineName: 'Mechanical press 200 t',
    hours: '0.417',
    notCalculableLines: 0,
    capacityHours: '7.5',
    balanceHours: '7.083',
    overloaded: false,
    shifts: [{ shiftId: null, shiftCode: null, hours: '0.417', capacityHours: '7.5', balanceHours: '7.083', overloaded: false }],
  },
];
const OPTIONS = {
  operations: [
    {
      workOrderId: ID(30),
      workOrderCode: 'WO-P1-2026-0003',
      workOrderStatus: 'IN_PROGRESS',
      itemCode: 'BRK-A100',
      itemName: 'Bracket front A100',
      qty: '300',
      uomCode: 'PCS',
      dueDate: '2026-11-21',
      operationId: ID(40),
      opSeq: 10,
      processName: 'Blanking',
      setupMinutes: '0',
      sph: '900',
    },
  ],
  machines: [{ id: ID(50), code: 'M-PRS200', name: 'Mechanical press 200 t' }],
  shifts: [{ id: ID(70), code: 'S1', name: 'Shift 1' }],
};

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('spk line: the planner plans a released WO operation on a machine, sees the load and releases it', async ({ page }) => {
  await mockApi(page, '/auth/me', {
    status: 200,
    json: { ...SESSION, permissions: [...SESSION.permissions, 'production.spk.create', 'production.spk.release', 'production.spk.close'] },
  });
  let current = { ...SPK };
  const writes: { method: string; url: string; body: unknown; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/production/spk/${SPK.id}**`, (route) => {
    const req = route.request();
    const url = req.url();
    if (url.includes('/options')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: OPTIONS }) });
    if (req.method() === 'GET') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: current }) });
    writes.push({ method: req.method(), url, body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    current =
      req.method() === 'PUT'
        ? { ...current, version: 2, lineCount: 1, lines: [LINE], machineLoad: LOAD }
        : { ...current, version: 3, status: 'RELEASED', code: 'SPK-STP-191126-0001', releasedAt: '2026-11-19T12:22:00Z', releasedByName: 'Review User' };
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: current }) });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/ppic/spk-line?spk=${SPK.id}`);
  await expect(page.getByRole('heading', { level: 1, name: 'Draft SPK' })).toBeVisible();
  await choose(page, page.getByRole('combobox', { name: 'Work order operation' }), /^WO-P1-2026-0003 · BRK-A100 · Blanking \(op 10\)/);
  await choose(page, page.getByRole('combobox', { name: 'Machine' }), 'M-PRS200 · Mechanical press 200 t');
  await page.getByRole('textbox', { name: 'Planned qty (PCS)' }).fill('300');
  await page.getByRole('button', { name: 'Save SPK' }).click();
  await expect(page.getByText('SPK saved')).toBeVisible();
  expect(writes[0]).toMatchObject({
    method: 'PUT',
    ifMatch: '"1"',
    body: {
      lines: [{ workOrderId: ID(30), routingOperationId: ID(40), machineId: ID(50), plannedQty: '300', priority: 1, shiftId: null, qcSampleMinutes: 5 }],
    },
  });
  await expect(page.getByRole('list', { name: 'Machine load' })).toContainText('0.417 h of 7.5 h · 7.083 h left');
  await axe(page);

  await page.getByRole('button', { name: 'Release to production' }).click();
  await page.getByRole('alertdialog', { name: 'Release to production' }).getByRole('button', { name: 'Release' }).click();
  await expect(page.getByText('SPK-STP-191126-0001 released to production')).toBeVisible();
  expect(writes[1]).toMatchObject({ method: 'POST', ifMatch: '"2"' });
  expect(writes[1]!.url).toContain('/actions/release');
  await expect(page.getByRole('heading', { level: 1, name: 'SPK-STP-191126-0001' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Revise' })).toBeVisible();
});

test('spk line: production reads the released SPKs of its plant, also on a phone, without plan actions', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['production.spk.view', 'production.run.view', 'production.run.create'] } });
  const released = {
    ...SPK,
    status: 'RELEASED',
    code: 'SPK-STP-191126-0001',
    version: 3,
    lineCount: 1,
    lines: [LINE],
    machineLoad: LOAD,
    releasedAt: '2026-11-19T12:22:00Z',
    releasedByName: 'Planner',
  };
  const listUrls: string[] = [];
  await page.route('**/api/v1/query/production.spk?*', (route) => {
    listUrls.push(route.request().url());
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [released], meta: META }) });
  });
  await mockApi(page, `/production/spk/${SPK.id}`, { status: 200, json: { data: released } });

  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/production/spk-line');
    await expect(page.getByRole('heading', { level: 1, name: 'SPK Line' })).toBeVisible();
    await expect(page.getByText('SPK-STP-191126-0001').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'New SPK' })).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await axe(page);
  }
  // production sees released and closed SPKs only
  expect(decodeURIComponent(listUrls.at(-1) ?? '')).toContain('RELEASED');

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/production/spk-line?spk=${SPK.id}`);
  await expect(page.getByRole('heading', { level: 1, name: 'SPK-STP-191126-0001' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Lines of SPK-STP-191126-0001' })).toContainText('WO-P1-2026-0003');
  await expect(page.getByRole('button', { name: 'Revise' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Close SPK' })).toHaveCount(0);
  await axe(page);
});

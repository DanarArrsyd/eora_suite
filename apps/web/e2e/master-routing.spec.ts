import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M2 step 3b (doc 29 §1.3, D-076): Master Data → Engineering → process types and routings against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PLANNER = { ...SESSION, permissions: ['master.routing.view', 'master.routing.create', 'master.routing.submit'] };
const APPROVER = { ...SESSION, permissions: ['master.routing.view', 'master.routing.create', 'master.routing.submit', 'master.routing.approve'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-15T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });

const ROUTING = {
  id: '00000000-0000-7000-8000-0000000001a1',
  code: 'R1',
  routingKind: 'PRIMARY',
  status: 'ACTIVE',
  plantId: '00000000-0000-7000-8000-0000000000b1',
  plantCode: 'P1',
  itemId: '00000000-0000-7000-8000-0000000001b1',
  itemCode: 'BRK-100',
  itemName: 'Bracket front',
  currentVersionId: '00000000-0000-7000-8000-0000000001c1',
  currentVersionNo: 1,
  currentEffectiveFrom: '2026-10-15',
  currentStandardYield: '0.98500',
  currentOperationCount: 1,
  pendingVersionId: '00000000-0000-7000-8000-0000000001c2',
  pendingVersionNo: 2,
  pendingStatus: 'DRAFT',
  version: 1,
};
const OP = {
  id: '00000000-0000-7000-8000-0000000001d1',
  opSeq: 10,
  stepKey: null,
  processTypeId: '00000000-0000-7000-8000-0000000001e1',
  processTypeCode: 'BLANK',
  processTypeName: 'Blanking',
  workCenterId: '00000000-0000-7000-8000-0000000001f1',
  workCenterCode: 'PRS-01',
  description: null,
  stdSetupMinutes: '30.00',
  stdCycleSeconds: '4.500',
  cycleBasis: 'PER_STROKE',
  partsPerCycle: 2,
  inspectionRequired: false,
  isCountPoint: true,
  requiresTooling: true,
  isFinalOperation: true,
  machines: [{ machineId: '00000000-0000-7000-8000-000000000201', machineCode: 'M-200', isPreferred: true, cycleSecondsOverride: null }],
  tools: [{ dieId: '00000000-0000-7000-8000-000000000211', dieCode: 'D-100', quantity: 1, cavitiesUsed: 2, availableInPlant: true }],
  inspections: [],
};
const V1 = {
  id: ROUTING.currentVersionId,
  routingId: ROUTING.id,
  versionNo: 1,
  status: 'APPROVED',
  effectiveFrom: '2026-10-15',
  effectiveTo: null,
  changeReason: null,
  standardYield: '0.98500',
  rejectReason: null,
  preparedByName: 'ppc',
  approvedByName: 'csa',
  approvedAt: '2026-10-15T08:00:00Z',
  operationCount: 1,
  version: 4,
};
const V2 = {
  ...V1,
  id: ROUTING.pendingVersionId,
  versionNo: 2,
  status: 'DRAFT',
  effectiveFrom: '2026-10-20',
  changeReason: 'Faster press cycle',
  approvedByName: null,
  approvedAt: null,
  version: 2,
};

async function setup(page: Page, me = PLANNER, v2 = V2) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/planning.routings?*', page1([ROUTING]));
  await mockApi(page, '/query/planning.routing_versions?*', page1([v2, V1]));
  await mockApi(page, `/planning/routing-versions/${V1.id}`, { status: 200, json: { data: { ...V1, operations: [OP] } } });
  await mockApi(page, `/planning/routing-versions/${V2.id}`, { status: 200, json: { data: { ...v2, operations: [OP] } } });
  await mockApi(page, '/query/planning.process_types?*', page1([{ id: OP.processTypeId, code: 'BLANK', name: 'Blanking', status: 'ACTIVE', version: 1 }]));
  await mockApi(page, '/query/core.work_centers?*', page1([{ id: OP.workCenterId, code: 'PRS-01', name: 'Press line 1', status: 'ACTIVE', version: 1 }]));
  await mockApi(page, '/query/core.machines?*', page1([{ id: OP.machines[0]!.machineId, code: 'M-200', name: 'Press 200 t', status: 'ACTIVE', version: 1 }]));
  await mockApi(
    page,
    '/query/core.die_items?*',
    page1([
      { id: 'x', dieId: OP.tools[0]!.dieId, code: 'D-100', dieName: 'Bracket die', plantCode: 'P1', itemId: ROUTING.itemId, status: 'ACTIVE', version: 1 },
    ]),
  );
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('master data menu: Organization, Parts, Partners and Engineering; old Administration links redirect (D-076)', async ({ page }) => {
  await setup(page, { ...APPROVER, permissions: [...APPROVER.permissions, 'master.org.view'] });
  await mockApi(page, '/query/core.plants?*', page1([]));
  await mockApi(page, '/query/core.warehouses?*', page1([]));
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/admin/plants?show=warehouses');
  await expect(page).toHaveURL(/\/master\/plants\?show=warehouses$/);
  const menu = page.getByRole('navigation', { name: 'Master Data menu' });
  for (const name of ['Plants & Warehouses', 'Work Centers', 'Process Types', 'Routings']) await expect(menu.getByRole('link', { name })).toBeVisible();
  // parts and partners need master.part.view, which this user lacks
  await expect(menu.getByRole('link', { name: 'Items' })).toHaveCount(0);
});

test('routings list: version in use and open version; a row opens the routing page', async ({ page }) => {
  await setup(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/routings');
  const row = page.getByRole('table', { name: 'Routings' }).getByRole('row', { name: /BRK-100/ });
  await expect(row).toContainText('Version 1');
  await expect(row).toContainText('yield 98.5 %');
  await expect(row).toContainText('Version 2');
  await expect(row).toContainText('Draft');
  await axe(page);
  await row.getByRole('button', { name: /Open/ }).click();
  await expect(page).toHaveURL(/\/master\/routings\?routing=/);
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-100 routing R1' })).toBeVisible();
});

test('routing page: the open Draft shows first; operations are replaced as one list at the version ETag', async ({ page }) => {
  await setup(page);
  const puts: { body: unknown; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/planning/routing-versions/${V2.id}/operations`, (route) => {
    puts.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...V2, operations: [OP] } }) });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/routings?routing=${ROUTING.id}`);
  const versions = page.getByRole('navigation', { name: 'Versions' });
  await expect(versions.getByRole('link', { name: /Version 2/ })).toHaveAttribute('aria-current', 'page');
  await expect(versions.getByRole('link', { name: /Version 1/ })).toContainText('In use');
  const ops = page.getByRole('table', { name: 'Operations of version 2' });
  await expect(ops.getByRole('row', { name: /Blanking/ })).toContainText('M-200 (preferred)');
  await expect(ops.getByRole('row', { name: /Blanking/ })).toContainText('D-100');
  await expect(page.getByRole('button', { name: /New version\. Version 2 is still open/ })).toHaveAttribute('aria-disabled', 'true');
  await axe(page);

  await page.getByRole('button', { name: 'Add operation' }).click();
  const drawer = page.getByRole('dialog', { name: 'Add operation' });
  await expect(drawer.getByRole('textbox', { name: 'Sequence' })).toHaveValue('20');
  await choose(page, drawer.getByRole('combobox', { name: 'Process' }), 'BLANK · Blanking');
  await choose(page, drawer.getByRole('combobox', { name: 'Work center' }), 'PRS-01 · Press line 1');
  await drawer.getByRole('textbox', { name: 'Cycle time (s)' }).fill('6');
  await drawer.getByRole('checkbox', { name: 'M-200 · Press 200 t' }).check();
  await drawer.getByRole('textbox', { name: 'What to inspect' }).fill('Hole position: 1 of 50');
  await drawer.getByRole('button', { name: 'Add operation' }).click();
  await expect(drawer).toBeHidden();

  expect(puts).toHaveLength(1);
  expect(puts[0]!.ifMatch).toBe('"2"');
  const sent = (puts[0]!.body as { operations: { opSeq: number; machines: unknown[]; inspections: unknown[] }[] }).operations;
  expect(sent.map((o) => o.opSeq)).toEqual([10, 20]);
  expect(sent[1]!.machines).toEqual([{ machineId: OP.machines[0]!.machineId, isPreferred: false, cycleSecondsOverride: null }]);
  expect(sent[1]!.inspections).toEqual([{ characteristic: 'Hole position', samplingNote: '1 of 50' }]);
});

test('approval: the approver sees Approve and Reject; the server refusing the preparer is said in words', async ({ page }) => {
  await setup(page, APPROVER, { ...V2, status: 'SUBMITTED' });
  await mockApi(
    page,
    `/planning/routing-versions/${V2.id}/actions/approve`,
    problem(403, 'SOD_VIOLATION', 'Not your own version', { detail: 'A routing version is approved by someone who did not prepare or edit it.' }),
  );
  await page.goto(`/master/routings?routing=${ROUTING.id}`);
  const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Approval' }) });
  await expect(panel.getByText('Waiting for a second person')).toBeVisible();
  await expect(panel.getByRole('button', { name: 'Add operation' })).toHaveCount(0);
  await panel.getByRole('button', { name: 'Approve' }).click();
  await expect(panel.getByText('A routing version is approved by someone who did not prepare or edit it.')).toBeVisible();
  await expect(panel.getByRole('button', { name: 'Reject' })).toBeVisible();
});

test('a planner without approval rights is never offered Approve', async ({ page }) => {
  await setup(page, PLANNER, { ...V2, status: 'SUBMITTED' });
  await page.goto(`/master/routings?routing=${ROUTING.id}`);
  await expect(page.getByRole('button', { name: 'Withdraw to edit' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Approve' })).toHaveCount(0);
});

test('a version id of another routing in the URL is ignored; the routing shows its own open version', async ({ page }) => {
  await setup(page);
  let foreignAsked = false;
  await page.route('**/api/v1/planning/routing-versions/00000000-0000-7000-8000-0000000009f9', (route) => {
    foreignAsked = true;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...V2, id: 'x', routingId: 'other', operations: [] } }),
    });
  });
  await page.goto(`/master/routings?routing=${ROUTING.id}&version=00000000-0000-7000-8000-0000000009f9`);
  await expect(page.getByRole('navigation', { name: 'Versions' }).getByRole('link', { name: /Version 2/ })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('table', { name: 'Operations of version 2' })).toBeVisible();
  expect(foreignAsked).toBe(false);
});

test('editing an operation keeps hidden attributes; a new work center clears the chosen machines', async ({ page }) => {
  await setup(page);
  await mockApi(
    page,
    '/query/core.work_centers?*',
    page1([
      { id: OP.workCenterId, code: 'PRS-01', name: 'Press line 1', status: 'ACTIVE', version: 1 },
      { id: '00000000-0000-7000-8000-0000000001f2', code: 'PRS-02', name: 'Press line 2', status: 'ACTIVE', version: 1 },
    ]),
  );
  const bodies: { operations: Record<string, unknown>[] }[] = [];
  await page.route(`**/api/v1/planning/routing-versions/${V2.id}/operations`, (route) => {
    bodies.push(route.request().postDataJSON());
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...V2, operations: [OP] } }) });
  });
  await page.goto(`/master/routings?routing=${ROUTING.id}`);
  await page.getByRole('button', { name: /Edit .*Blanking/ }).click();
  const drawer = page.getByRole('dialog', { name: 'Operation 10' });
  await drawer.getByRole('textbox', { name: 'Cycle time (s)' }).fill('4');
  await drawer.getByRole('button', { name: 'Save operation' }).click();
  await expect(drawer).toBeHidden();
  expect(bodies[0]!.operations[0]).toMatchObject({
    stdCycleSeconds: 4,
    machines: [{ machineId: OP.machines[0]!.machineId, isPreferred: true, cycleSecondsOverride: null }],
    tools: [{ dieId: OP.tools[0]!.dieId, quantity: 1, cavitiesUsed: 2 }],
  });

  await page.getByRole('button', { name: /Edit .*Blanking/ }).click();
  const again = page.getByRole('dialog', { name: 'Operation 10' });
  await expect(again.getByRole('checkbox', { name: 'M-200 · Press 200 t' })).toBeChecked();
  await choose(page, again.getByRole('combobox', { name: 'Work center' }), 'PRS-02 · Press line 2');
  await again.getByRole('button', { name: 'Save operation' }).click();
  await expect(again).toBeHidden();
  expect(bodies[1]!.operations[0]).toMatchObject({ workCenterId: '00000000-0000-7000-8000-0000000001f2', machines: [] });
});

test('a routing is deactivated at its version; a refused activation says why', async ({ page }) => {
  await setup(page);
  const patches: { body: unknown; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/planning/routings/${ROUTING.id}`, (route) => {
    patches.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({
      status: 422,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'x',
        title: 'Validation failed',
        status: 422,
        code: 'VALIDATION_FAILED',
        errors: { status: ['This item already has an active primary routing in this plant.'] },
      }),
    });
  });
  await page.goto(`/master/routings?routing=${ROUTING.id}`);
  const sent = page.waitForRequest((r) => r.method() === 'PATCH' && r.url().endsWith(`/planning/routings/${ROUTING.id}`));
  await page.getByRole('button', { name: 'Deactivate routing R1' }).click();
  await sent;
  await expect(page.getByText('This item already has an active primary routing in this plant.')).toBeVisible();
  expect(patches[0]).toEqual({ body: { status: 'INACTIVE' }, ifMatch: '"1"' });
  await expect(page.getByText('This item already has an active primary routing in this plant.')).toBeVisible();
});

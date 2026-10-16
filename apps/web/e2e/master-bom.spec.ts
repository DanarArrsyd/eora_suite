import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M2 step 4b (doc 29 §1.4, D-078, D-079): Master Data → Engineering → BOMs and routing maps against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PLANNER = { ...SESSION, permissions: ['master.routing.view', 'master.routing.create', 'master.routing.submit'] };
const APPROVER = { ...PLANNER, permissions: [...PLANNER.permissions, 'master.routing.approve'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-16T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });

const ITEM = '00000000-0000-7000-8000-000000000301';
const COIL = {
  id: '00000000-0000-7000-8000-000000000302',
  code: 'COIL-1',
  name: 'SPCC coil',
  baseUomId: '00000000-0000-7000-8000-000000000391',
  status: 'ACTIVE',
  version: 1,
};
const NUT = {
  id: '00000000-0000-7000-8000-000000000303',
  code: 'NUT-M6',
  name: 'Nut M6',
  baseUomId: '00000000-0000-7000-8000-000000000392',
  status: 'ACTIVE',
  version: 1,
};
const V1 = {
  id: '00000000-0000-7000-8000-000000000311',
  itemId: ITEM,
  itemCode: 'BRK-100',
  itemName: 'Bracket front',
  itemType: 'FINISHED',
  versionNo: 1,
  status: 'DRAFT',
  effectiveFrom: '2026-10-16',
  effectiveTo: null,
  inUse: false,
  changeReason: null,
  rejectReason: null,
  preparedByName: 'ppc',
  approvedByName: null,
  approvedAt: null,
  lineCount: 1,
  code: 'BRK-100',
  version: 3,
};
const LINE = {
  id: '00000000-0000-7000-8000-000000000321',
  lineNo: 10,
  componentItemId: COIL.id,
  componentCode: 'COIL-1',
  componentName: 'SPCC coil',
  componentType: 'RAW',
  grossQtyPer: '0.485000',
  uomId: COIL.baseUomId,
  uomCode: 'KG',
  scrapAllowance: '0.02000',
  linePurpose: null,
};
const MAP = {
  id: '00000000-0000-7000-8000-000000000331',
  plantCode: 'P1',
  bomVersionId: V1.id,
  routingCode: 'R1',
  routingVersionId: '00000000-0000-7000-8000-000000000341',
  routingVersionNo: 1,
  routingStatus: 'APPROVED',
  bomStatus: 'SUBMITTED',
  mapRevision: 1,
  status: 'DRAFT',
  changeReason: null,
  rejectReason: null,
  preparedByName: 'ppc',
  approvedByName: null,
  approvedAt: null,
  lineCount: 0,
  bomLineCount: 1,
  version: 1,
};
const OP = { id: '00000000-0000-7000-8000-000000000351', opSeq: 10, stepKey: null, processTypeName: 'Blanking', workCenterCode: 'PRS-01' };

async function setup(page: Page, me = PLANNER, version = V1, map = MAP) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/planning.bom_versions?*', page1([version]));
  await mockApi(page, `/planning/bom-versions/${V1.id}`, { status: 200, json: { data: { ...version, lines: [LINE] } } });
  await mockApi(page, '/query/planning.bom_routing_maps?*', page1([map]));
  await mockApi(page, `/planning/bom-routing-maps/${MAP.id}`, {
    status: 200,
    json: {
      data: {
        ...map,
        bomLines: [
          {
            bomLineId: LINE.id,
            lineNo: 10,
            componentCode: 'COIL-1',
            componentName: 'SPCC coil',
            grossQtyPer: '0.485000',
            uomCode: 'KG',
            linePurpose: null,
            routingOperationId: null,
          },
        ],
        operations: [OP],
      },
    },
  });
  await mockApi(page, '/query/core.items?*', page1([COIL, NUT]));
  await mockApi(
    page,
    '/query/core.uoms?*',
    page1([
      { id: COIL.baseUomId, code: 'KG', name: 'Kilogram', status: 'ACTIVE', version: 1 },
      { id: NUT.baseUomId, code: 'PCS', name: 'Piece', status: 'ACTIVE', version: 1 },
    ]),
  );
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test("BOMs list: one row per version; a row opens the item's BOM page", async ({ page }) => {
  await setup(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/boms');
  const row = page.getByRole('table', { name: 'BOMs' }).getByRole('row', { name: /BRK-100/ });
  await expect(row).toContainText('Version 1');
  await expect(row).toContainText('Draft');
  await axe(page);
  await row.getByRole('button', { name: /Open/ }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-100 BOM' })).toBeVisible();
});

test('BOM page: lines are replaced as one list at the version ETag; scrap allowance is sent as a fraction', async ({ page }) => {
  await setup(page);
  const puts: { body: { lines: Record<string, unknown>[] }; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/planning/bom-versions/${V1.id}/lines`, (route) => {
    puts.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...V1, lines: [LINE] } }) });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/boms?item=${ITEM}`);
  const lines = page.getByRole('table', { name: 'Lines of version 1' });
  await expect(lines.getByRole('row', { name: /COIL-1/ })).toContainText('0.485 KG');
  await expect(lines.getByRole('row', { name: /COIL-1/ })).toContainText('2 %');
  await expect(page.getByRole('button', { name: /New map\. Submit this version first/ })).toHaveAttribute('aria-disabled', 'true');
  await axe(page);

  await page.getByRole('button', { name: 'Add line' }).click();
  const drawer = page.getByRole('dialog', { name: 'Add line' });
  await expect(drawer.getByRole('textbox', { name: 'Line' })).toHaveValue('20');
  await choose(page, drawer.getByRole('combobox', { name: 'Component' }), 'NUT-M6 · Nut M6');
  await drawer.getByRole('textbox', { name: 'Quantity per piece' }).fill('2');
  await choose(page, drawer.getByRole('combobox', { name: 'Unit' }), 'PCS · Piece');
  await drawer.getByRole('textbox', { name: 'Scrap allowance (%)' }).fill('1.5');
  await drawer.getByRole('button', { name: 'Add line' }).click();
  await expect(drawer).toBeHidden();
  expect(puts[0]!.ifMatch).toBe('"3"');
  expect(puts[0]!.body.lines).toEqual([
    { lineNo: 10, componentItemId: COIL.id, grossQtyPer: 0.485, uomId: COIL.baseUomId, scrapAllowance: 0.02, linePurpose: null },
    { lineNo: 20, componentItemId: NUT.id, grossQtyPer: 2, uomId: NUT.baseUomId, scrapAllowance: 0.015, linePurpose: null },
  ]);
});

test("a refused line shows the server's reason on that line's field", async ({ page }) => {
  await setup(page);
  await mockApi(
    page,
    `/planning/bom-versions/${V1.id}/lines`,
    problem(422, 'VALIDATION_FAILED', 'Validation failed', {
      errors: { 'lines.1.uomId': ["Use the component's base unit or a unit it has a conversion for."] },
    }),
  );
  await page.goto(`/master/boms?item=${ITEM}`);
  await page.getByRole('button', { name: 'Add line' }).click();
  const drawer = page.getByRole('dialog', { name: 'Add line' });
  await choose(page, drawer.getByRole('combobox', { name: 'Component' }), 'NUT-M6 · Nut M6');
  await drawer.getByRole('textbox', { name: 'Quantity per piece' }).fill('2');
  await choose(page, drawer.getByRole('combobox', { name: 'Unit' }), 'KG · Kilogram');
  await drawer.getByRole('button', { name: 'Add line' }).click();
  await expect(drawer.getByText("Use the component's base unit or a unit it has a conversion for.").first()).toBeVisible();
});

test('routing map: each line gets its operation, saved as one list; approval waits for both versions (D-079)', async ({ page }) => {
  await setup(page, APPROVER, { ...V1, status: 'SUBMITTED' }, { ...MAP });
  const puts: unknown[] = [];
  await page.route(`**/api/v1/planning/bom-routing-maps/${MAP.id}/lines`, (route) => {
    puts.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/boms?item=${ITEM}&version=${V1.id}`);
  const maps = page.getByRole('table', { name: 'Routing maps of version 1' });
  await expect(maps.getByRole('row', { name: /R1/ })).toContainText('0 of 1');
  await maps.getByRole('button', { name: /Open/ }).click();
  await expect(page).toHaveURL(new RegExp(`map=${MAP.id}`));
  const drawer = page.getByRole('dialog', { name: 'Map R1 revision 1' });
  await choose(page, drawer.getByRole('combobox', { name: 'Operation for line 10 COIL-1' }), '10 · Blanking · PRS-01');
  await drawer.getByRole('button', { name: 'Save mapping' }).click();
  await expect.poll(() => puts.length).toBe(1);
  expect(puts[0]).toEqual({ body: { lines: [{ bomLineId: LINE.id, routingOperationId: OP.id }] }, ifMatch: '"1"' });
  await axe(page);
});

test('approving a map before the BOM version is approved says what to do first', async ({ page }) => {
  await setup(page, APPROVER, { ...V1, status: 'SUBMITTED' }, { ...MAP, status: 'SUBMITTED', lineCount: 1 });
  await mockApi(
    page,
    `/planning/bom-routing-maps/${MAP.id}/actions/approve`,
    problem(409, 'INVALID_STATE_TRANSITION', 'Cannot approve', { detail: 'Approve the BOM version and the routing version first.' }),
  );
  await page.goto(`/master/boms?item=${ITEM}&version=${V1.id}&map=${MAP.id}`);
  const drawer = page.getByRole('dialog', { name: 'Map R1 revision 1' });
  await drawer.getByRole('button', { name: 'Approve' }).click();
  await expect(drawer.getByText('Approve the BOM version and the routing version first.')).toBeVisible();
});

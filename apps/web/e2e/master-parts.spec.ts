import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page, choose } from './fixtures';

/* M2 step 2c (doc 29 §1.2, D-073, D-074): Master Data → part and partner screens against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const WRITER = { ...SESSION, permissions: ['master.part.create', 'master.part.view'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-15T02:00:00Z', timezone: 'Asia/Jakarta' };
const KG = { id: '00000000-0000-7000-8000-0000000000f1', code: 'KG', name: 'Kilogram', uomClass: 'MASS', decimals: 3, status: 'ACTIVE', version: 1 };
const ITEM = {
  id: '00000000-0000-7000-8000-0000000000f2',
  code: 'COIL-SPCC-1.2',
  name: 'SPCC coil 1.2 mm',
  itemType: 'RAW',
  baseUomId: KG.id,
  baseUomCode: 'KG',
  lotControlled: true,
  inspectionRequired: false,
  incomingReleaseRequired: true,
  drawingNo: null,
  drawingRev: null,
  status: 'ACTIVE',
  specId: '00000000-0000-7000-8000-0000000000f3',
  specGrade: 'SPCC',
  specThicknessMm: '1.200',
  specWidthMm: null,
  specDensityGCm3: '7.8500',
  specVersion: 2,
  version: 5,
};

const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });

async function setup(page: Page, me = WRITER) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/core.uoms?*', page1([KG]));
  await mockApi(page, '/query/core.items?*', page1([ITEM]));
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('master data module: items list with controls and a Semi-Finished vocabulary, no WIP', async ({ page }) => {
  await setup(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/items');
  await expect(page.getByRole('heading', { level: 1, name: 'Items' })).toBeVisible();
  const row = page.getByRole('table', { name: 'Items' }).getByRole('row', { name: /COIL-SPCC-1\.2/ });
  await expect(row).toContainText('Raw material');
  await expect(row).toContainText('Lot, Incoming release');
  await expect(page.getByRole('navigation', { name: /sections/ })).toHaveCount(0); // one resource: no section tabs
  await expect(page.getByText(/WIP/)).toHaveCount(0);
  await axe(page);
});

test('items: create posts the item, then its material specification', async ({ page }) => {
  await setup(page);
  await mockApi(page, '/query/core.items?*', page1([]));
  const calls: { url: string; method: string; body: unknown; ifMatch?: string }[] = [];
  await page.route('**/api/v1/core/items**', (route) => {
    const req = route.request();
    calls.push({ url: new URL(req.url()).pathname, method: req.method(), body: req.postDataJSON(), ifMatch: req.headers()['if-match'] });
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: ITEM }) });
  });
  await page.goto('/master/items');
  await page.getByRole('button', { name: 'New item' }).click();
  const drawer = page.getByRole('dialog', { name: 'New item' });
  await drawer.getByRole('textbox', { name: 'Code', exact: true }).fill('coil-spcc-1.2');
  await drawer.getByRole('textbox', { name: 'Name', exact: true }).fill('SPCC coil 1.2 mm');
  await choose(page, drawer.getByRole('combobox', { name: 'Type' }), 'Raw material');
  await choose(page, drawer.getByRole('combobox', { name: 'Base unit' }), 'KG · Kilogram');
  await drawer.getByRole('textbox', { name: 'Material grade' }).fill('SPCC');
  await drawer.getByRole('textbox', { name: 'Thickness (mm)' }).fill('1.2');
  await drawer.getByRole('button', { name: 'Create item' }).click();
  await expect(page.getByText('Item COIL-SPCC-1.2 created')).toBeVisible();
  expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual(['POST /api/v1/core/items', `PUT /api/v1/core/items/${ITEM.id}/material-spec`]);
  expect(calls[0]!.body).toMatchObject({ code: 'COIL-SPCC-1.2', itemType: 'RAW', baseUomId: KG.id, lotControlled: true, drawingNo: null });
  expect(calls[1]!.body).toEqual({ grade: 'SPCC', thicknessMm: 1.2, widthMm: null, densityGCm3: null });
  expect(calls[1]!.ifMatch).toBeUndefined(); // first specification
});

test('items: a spec-only edit sends the spec at its own version and leaves the item alone', async ({ page }) => {
  await setup(page);
  const calls: { method: string; url: string; ifMatch?: string }[] = [];
  await page.route('**/api/v1/core/items/**', (route) => {
    const req = route.request();
    calls.push({ method: req.method(), url: new URL(req.url()).pathname, ifMatch: req.headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: ITEM }) });
  });
  await page.goto('/master/items');
  await page.getByRole('button', { name: /Edit COIL-SPCC-1\.2/ }).click();
  const drawer = page.getByRole('dialog', { name: 'COIL-SPCC-1.2' });
  await expect(drawer.getByRole('combobox', { name: 'Base unit' })).toHaveCount(0); // fixed once created
  await drawer.getByRole('textbox', { name: 'Width (mm)' }).fill('1219');
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('COIL-SPCC-1.2 saved')).toBeVisible();
  expect(calls).toEqual([{ method: 'PUT', url: `/api/v1/core/items/${ITEM.id}/material-spec`, ifMatch: '"2"' }]);
});

test('unit conversions: versions open read-only; a new version cannot start before today', async ({ page }) => {
  await setup(page);
  await mockApi(
    page,
    '/query/core.uom_conversions?*',
    page1([
      {
        id: '00000000-0000-7000-8000-0000000000f4',
        code: 'SHEET-A',
        itemId: ITEM.id,
        itemName: 'Sheet A',
        fromUomCode: 'SHEET',
        toUomCode: 'KG',
        factor: '12.5000000000',
        effectiveFrom: '2026-10-15',
        effectiveTo: null,
        current: true,
        version: 1,
      },
    ]),
  );
  await page.goto('/master/units?show=conversions');
  const sections = page.getByRole('navigation', { name: 'Units & Conversions sections' });
  await expect(sections.getByRole('link', { name: 'Unit Conversions' })).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('row', { name: /SHEET-A/ })).toContainText('1 SHEET = 12.5 KG');
  await page.getByRole('button', { name: /View SHEET-A/ }).click();
  await expect(page.getByText(/Conversion versions never change/)).toBeVisible();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'New conversion version' }).click();
  await expect(page.getByRole('dialog', { name: 'New conversion version' }).getByText('Today or later. Past days keep the factor they had.')).toBeVisible();
  await axe(page);
});

test('demand → customers opens the same live screen; a viewer cannot change partners', async ({ page }) => {
  await setup(page, { ...SESSION, permissions: ['master.part.view'] });
  await mockApi(
    page,
    '/query/core.customers?*',
    page1([{ id: '00000000-0000-7000-8000-0000000000f5', code: 'ASTRA', name: 'Sample customer', status: 'ACTIVE', version: 1 }]),
  );
  await page.goto('/demand/customers');
  await expect(page.getByRole('heading', { level: 1, name: 'Customers' })).toBeVisible();
  await expect(page.getByRole('row', { name: /ASTRA/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /New customer\. Your roles do not allow changes to part and partner master data/ })).toHaveAttribute(
    'aria-disabled',
    'true',
  );
});

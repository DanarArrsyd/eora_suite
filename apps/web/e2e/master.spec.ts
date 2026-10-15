import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, problem, SESSION, test, type Page, choose } from './fixtures';

/* M2 step 1c (doc 29 §1): Administration → organisation master data against a mocked API (query resources, doc 24 §5.2). */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const WRITER = { ...SESSION, permissions: ['master.org.create', 'master.org.view'] };
const VIEWER = { ...SESSION, permissions: ['master.org.view'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-15T02:00:00Z', timezone: 'Asia/Jakarta' };
const PLANT = { id: '00000000-0000-7000-8000-0000000000b1', code: 'P1', name: 'Plant 1', version: 1 };

const WC = {
  id: '00000000-0000-7000-8000-0000000000d1',
  code: 'PRS-01',
  name: 'Press line 1',
  workCenterType: 'PRESS',
  availableHoursPerShift: '7.50',
  status: 'ACTIVE',
  plantId: PLANT.id,
  plantCode: 'P1',
  matAtProdLocationId: '00000000-0000-7000-8000-0000000000e1',
  matAtProdLocationCode: 'MAP-PRS-01',
  machineCount: 2,
  updatedAt: '2026-10-15T01:00:00Z',
  version: 3,
};

function page1(rows: unknown[]) {
  return { status: 200, json: { data: rows, meta: { ...META, total: rows.length } } };
}

async function setup(page: Page, me = WRITER, rows: unknown[] = [WC]) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/core.plants?*', page1([PLANT]));
  await mockApi(page, '/query/core.work_centers?*', page1(rows));
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('work centers: live rows with capacity, Material at Production location and status; list query is server-side', async ({ page }) => {
  await setup(page);
  const asked = page.waitForRequest((r) => r.url().includes('/query/core.work_centers?'));
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/admin/work-centers');
  const qs = new URL((await asked).url()).searchParams;
  expect(qs.get('sort')).toBe('plantCode,code');
  expect(qs.get('page[number]')).toBe('1');

  await expect(page.getByRole('heading', { level: 1, name: 'Work Centers' })).toBeVisible();
  const row = page.getByRole('table', { name: 'Work Centers' }).getByRole('row', { name: /PRS-01/ });
  await expect(row).toContainText('7.50 h');
  await expect(row).toContainText('MAP-PRS-01');
  await expect(row).toContainText('Active');
  await expect(page.getByText('1 work center', { exact: true })).toBeVisible();
  await expect(page.getByText(/WIP/)).toHaveCount(0);
  await axe(page);
});

test('work centers: create sends upper-case code and a number for hours; status is not asked for a new record', async ({ page }) => {
  await setup(page, WRITER, []);
  let body: Record<string, unknown> | null = null;
  await mockApi(page, '/core/work-centers', (route) => {
    body = route.request().postDataJSON() as Record<string, unknown>;
    return { status: 201, json: { data: { ...WC, code: 'WLD-02' } } };
  });
  await page.goto('/admin/work-centers');
  await expect(page.getByText('No work centers yet')).toBeVisible();
  await page.getByRole('button', { name: 'New work center' }).click();
  const drawer = page.getByRole('dialog', { name: 'New work center' });
  await expect(drawer.getByLabel('Status')).toHaveCount(0);
  await choose(page, drawer.getByRole('combobox', { name: 'Plant' }), 'P1 · Plant 1');
  await drawer.getByRole('textbox', { name: 'Code', exact: true }).fill('wld-02');
  await drawer.getByRole('textbox', { name: 'Name', exact: true }).fill('Welding cell 2');
  await drawer.getByLabel('Type code').fill('welding');
  await drawer.getByLabel('Available hours per shift').fill('8.5');
  await axe(page);
  await drawer.getByRole('button', { name: 'Create work center' }).click();
  await expect(page.getByText('Work center WLD-02 created')).toBeVisible();
  expect(body).toEqual({ plantId: PLANT.id, code: 'WLD-02', name: 'Welding cell 2', workCenterType: 'WELDING', availableHoursPerShift: 8.5 });
});

test('work centers: an edit sends only the changed fields at the row version; a concurrent change asks for a reload', async ({ page }) => {
  await setup(page);
  const sent: { ifMatch?: string; body: unknown }[] = [];
  await mockApi(page, `/core/work-centers/${WC.id}`, (route) => {
    sent.push({ ifMatch: route.request().headers()['if-match'], body: route.request().postDataJSON() });
    return sent.length === 1
      ? problem(412, 'VERSION_CONFLICT', 'Changed by someone else')
      : { status: 200, json: { data: { ...WC, availableHoursPerShift: null, version: 4 } } };
  });
  await page.goto('/admin/work-centers');
  await page.getByRole('button', { name: /Edit PRS-01/ }).click();
  const drawer = page.getByRole('dialog', { name: 'PRS-01' });
  await expect(drawer.getByRole('textbox', { name: 'Code', exact: true })).toHaveCount(0); // codes are fixed: shown in the title, not as a field
  await drawer.getByLabel('Available hours per shift').fill('');
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await expect(drawer.getByText(/Someone changed this record after you opened it/)).toBeVisible();
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('PRS-01 saved')).toBeVisible();
  expect(sent).toEqual([
    { ifMatch: '"3"', body: { availableHoursPerShift: null } },
    { ifMatch: '"3"', body: { availableHoursPerShift: null } },
  ]);
});

test('a viewer sees the list but cannot create or change records', async ({ page }) => {
  await setup(page, VIEWER);
  await page.goto('/admin/work-centers');
  await expect(page.getByRole('button', { name: /New work center\. Your roles do not allow/ })).toHaveAttribute('aria-disabled', 'true');
  await page.getByRole('button', { name: /View PRS-01/ }).click();
  await expect(page.getByRole('dialog', { name: 'PRS-01' }).getByText('Your roles allow viewing organisation master data, not changing it.')).toBeVisible();
});

test('locations: virtual and Material at Production locations open read-only', async ({ page }) => {
  await setup(page);
  const loc = (code: string, locationType: string, extra: Record<string, unknown> = {}) => ({
    id: `00000000-0000-7000-8000-${code.length.toString().padStart(4, '0')}${code.charCodeAt(2).toString().padStart(8, '0')}`,
    code,
    name: `${code} name`,
    locationType,
    virtual: locationType.startsWith('V_'),
    status: 'ACTIVE',
    plantId: PLANT.id,
    plantCode: 'P1',
    warehouseId: null,
    warehouseCode: null,
    workCenterId: null,
    workCenterCode: null,
    updatedAt: '2026-10-15T01:00:00Z',
    version: 1,
    ...extra,
  });
  await mockApi(page, '/query/core.locations?*', page1([loc('MAP-PRS-01', 'MAT_AT_PROD', { workCenterCode: 'PRS-01' }), loc('V_OPENING', 'V_OPENING')]));
  await page.goto('/admin/locations');
  const table = page.getByRole('table', { name: 'Locations' });
  await expect(table.getByRole('row', { name: /MAP-PRS-01/ })).toContainText('Work center PRS-01');
  await table.getByRole('button', { name: /View V_OPENING/ }).click();
  await expect(page.getByRole('dialog', { name: 'V_OPENING' }).getByText(/never hold stock and cannot be renamed/)).toBeVisible();
});

test('master screens: no permission → a clear denial; phone layout without horizontal overflow', async ({ page }) => {
  await setup(page, { ...SESSION, permissions: ['core.users_roles.view'] });
  await page.goto('/admin/plants');
  await expect(page.getByRole('heading', { name: 'Plants is not available for your roles' })).toBeVisible();

  await setup(page);
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto('/admin/work-centers');
  await expect(page.getByText('MAP-PRS-01')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await axe(page);
});

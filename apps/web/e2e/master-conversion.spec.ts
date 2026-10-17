import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M2 step 5b (doc 29 §1.5, D-080): Master Data → Engineering → Conversion Data against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const PREPARER = { ...SESSION, permissions: ['master.conversion.view', 'master.conversion.create', 'master.conversion.submit'] };
const VALIDATOR = { ...SESSION, permissions: ['master.conversion.view', 'master.conversion.approve'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-17T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });

const ITEM = '00000000-0000-7000-8000-000000000501';
const COIL = '00000000-0000-7000-8000-000000000502';
const FACTS = {
  effectiveFrom: '2026-10-17',
  effectiveTo: null,
  inUse: false,
  changeReason: null,
  rejectReason: null,
  preparedByName: 'csa',
  validatedByName: null,
  validatedAt: null,
  retiredAt: null,
};
const NW = {
  ...FACTS,
  id: '00000000-0000-7000-8000-000000000511',
  itemId: ITEM,
  itemCode: 'BRK-100',
  itemName: 'Bracket front',
  itemType: 'FINISHED',
  versionNo: 1,
  status: 'DRAFT',
  netWeightKg: '0.290500',
  code: 'BRK-100',
  version: 2,
};
const PROFILE = {
  id: '00000000-0000-7000-8000-000000000521',
  itemId: ITEM,
  itemCode: 'BRK-100',
  itemName: 'Bracket front',
  inputItemId: COIL,
  inputItemCode: 'COIL-SPCC',
  inputItemName: 'SPCC coil 1.2',
  inputForm: 'COIL',
  status: 'ACTIVE',
  versionInUseId: null,
  versionInUseNo: null,
  versionCount: 1,
  code: 'BRK-100',
  version: 1,
};
const PV = {
  ...FACTS,
  id: '00000000-0000-7000-8000-000000000531',
  profileId: PROFILE.id,
  itemId: ITEM,
  itemCode: 'BRK-100',
  itemName: 'Bracket front',
  inputItemId: COIL,
  inputItemCode: 'COIL-SPCC',
  inputItemName: 'SPCC coil 1.2',
  inputForm: 'COIL',
  profileStatus: 'ACTIVE',
  formulaCode: 'COIL_V1',
  versionNo: 1,
  status: 'DRAFT',
  code: 'BRK-100',
  version: 4,
};
const INPUTS = [
  { key: 'thickness_mm', label: 'Thickness', unit: 'mm', value: '1.2' },
  { key: 'width_mm', label: 'Strip width', unit: 'mm', value: '120' },
  { key: 'density_g_cm3', label: 'Density', unit: 'g/cm3', value: '7.85' },
  { key: 'pitch_mm', label: 'Pitch', unit: 'mm', value: null },
  { key: 'parts_per_stroke', label: 'Parts per stroke (cavities)', unit: 'pc/stroke', value: null },
  { key: 'head_tail_mm', label: 'Head + tail allowance', unit: 'mm', value: null },
  { key: 'setup_mm', label: 'Setup allowance per run', unit: 'mm', value: null },
];

async function setup(page: Page, me = PREPARER, nw: Record<string, unknown> = NW, pv: Record<string, unknown> = PV) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/planning.item_weights?*', page1([nw]));
  await mockApi(page, '/query/planning.conversion_profiles?*', page1([PROFILE]));
  await mockApi(page, '/query/planning.conversion_profile_versions?*', page1([pv]));
  await mockApi(page, `/planning/conversion-profile-versions/${PV.id}`, { status: 200, json: { data: { ...pv, inputs: INPUTS } } });
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('Conversion Data lists net weights and profiles; a row opens its page', async ({ page }) => {
  await setup(page);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/conversion-data');
  const weights = page.getByRole('table', { name: 'Net weights' }).getByRole('row', { name: /BRK-100/ });
  await expect(weights).toContainText('0.2905 kg/pc');
  await expect(weights).toContainText('Draft');
  await axe(page);

  await page.goto('/master/conversion-data?show=profiles');
  const profile = page.getByRole('table', { name: 'Conversion profiles' }).getByRole('row', { name: /BRK-100/ });
  await expect(profile).toContainText('COIL-SPCC');
  await expect(profile).toContainText('None validated yet');
  await profile.getByRole('button', { name: /Open/ }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-100 from COIL-SPCC' })).toBeVisible();
});

test('net weight page: a Draft weight is edited at its ETag and submitted', async ({ page }) => {
  await setup(page);
  const patches: { body: unknown; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/planning/item-weights/${NW.id}`, (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback();
    patches.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: NW }) });
  });
  await mockApi(page, `/planning/item-weights/${NW.id}/actions/submit`, { status: 200, json: { data: { ...NW, status: 'SUBMITTED' } } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/conversion-data?weight=${ITEM}`);
  await expect(page.getByRole('heading', { level: 1, name: 'BRK-100 net weight' })).toBeVisible();
  await axe(page);

  await page.getByRole('button', { name: 'Edit', exact: true }).click();
  const drawer = page.getByRole('dialog', { name: 'Version 1' });
  await expect(drawer.getByRole('textbox', { name: 'Net weight (kg per piece)' })).toHaveValue('0.2905');
  await drawer.getByRole('textbox', { name: 'Net weight (kg per piece)' }).fill('0.288');
  await drawer.getByRole('button', { name: 'Save changes' }).click();
  await expect(drawer).toBeHidden();
  expect(patches[0]).toEqual({ body: { effectiveFrom: '2026-10-17', changeReason: null, netWeightKg: 0.288 }, ifMatch: '"2"' });

  await page.getByRole('button', { name: 'Submit for approval' }).click();
  await expect(page.getByText('Version 1 submitted for approval')).toBeVisible();
});

test('a validator validates a submitted weight and retires a validated one after confirming', async ({ page }) => {
  await setup(page, VALIDATOR, { ...NW, status: 'SUBMITTED' });
  const calls: string[] = [];
  await page.route('**/api/v1/planning/item-weights/*/actions/*', (route) => {
    calls.push(route.request().url().split('/actions/')[1]!);
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: NW }) });
  });
  await page.goto(`/master/conversion-data?weight=${ITEM}`);
  await expect(page.getByText('Validated by someone who did not prepare or edit this version.')).toBeVisible();
  await page.getByRole('button', { name: 'Validate' }).click();
  await expect(page.getByText('Version 1 validated')).toBeVisible();

  await setup(page, VALIDATOR, { ...NW, status: 'VALIDATED', inUse: true, validatedByName: 'qcs', validatedAt: '2026-10-17T03:00:00Z' });
  await page.reload();
  await page.getByRole('button', { name: 'Retire' }).click();
  const confirm = page.getByRole('dialog', { name: 'Retire version 1' });
  await expect(confirm).toContainText('It stays in use until the end of today.');
  await confirm.getByRole('button', { name: 'Retire version' }).click();
  await expect.poll(() => calls).toEqual(['validate', 'retire']);
});

test('profile page: inputs show their units; saving sends only filled values with their unit at the ETag', async ({ page }) => {
  await setup(page);
  const patches: { body: unknown; ifMatch?: string }[] = [];
  await page.route(`**/api/v1/planning/conversion-profile-versions/${PV.id}`, (route) => {
    if (route.request().method() !== 'PATCH') return route.fallback();
    patches.push({ body: route.request().postDataJSON(), ifMatch: route.request().headers()['if-match'] });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { ...PV, inputs: INPUTS } }) });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/conversion-data?profile=${PROFILE.id}`);
  await expect(page.getByText('1.2 mm')).toBeVisible();
  await expect(page.getByText('7.85 g/cm3')).toBeVisible();
  await expect(page.getByText('Before submitting, add every input.')).toBeVisible();
  await axe(page);

  await page.getByRole('button', { name: 'Edit inputs' }).click();
  const drawer = page.getByRole('dialog', { name: 'Inputs of version 1' });
  await drawer.getByRole('textbox', { name: 'Pitch (mm)' }).fill('320');
  await drawer.getByRole('textbox', { name: 'Parts per stroke (cavities) (pc/stroke)' }).fill('1');
  await drawer.getByRole('button', { name: 'Save inputs' }).click();
  await expect(drawer).toBeHidden();
  expect(patches[0]).toEqual({
    body: {
      inputs: {
        thickness_mm: { value: '1.2', unit: 'mm' },
        width_mm: { value: '120', unit: 'mm' },
        density_g_cm3: { value: '7.85', unit: 'g/cm3' },
        pitch_mm: { value: '320', unit: 'mm' },
        parts_per_stroke: { value: '1', unit: 'pc/stroke' },
      },
    },
    ifMatch: '"4"',
  });
});

test("a refused input shows the server's reason on that input", async ({ page }) => {
  await setup(page);
  await mockApi(page, `/planning/conversion-profile-versions/${PV.id}`, (route) =>
    route.request().method() === 'PATCH'
      ? problem(422, 'VALIDATION_FAILED', 'Validation failed', { errors: { 'inputs.parts_per_stroke': ['Must be a whole number of at least 1.'] } })
      : { status: 200, json: { data: { ...PV, inputs: INPUTS } } },
  );
  await page.goto(`/master/conversion-data?profile=${PROFILE.id}`);
  await page.getByRole('button', { name: 'Edit inputs' }).click();
  const drawer = page.getByRole('dialog', { name: 'Inputs of version 1' });
  await drawer.getByRole('textbox', { name: 'Parts per stroke (cavities) (pc/stroke)' }).fill('1.5');
  await drawer.getByRole('button', { name: 'Save inputs' }).click();
  await expect(drawer.getByText('Must be a whole number of at least 1.').first()).toBeVisible();
});

test('a role with conversion data only still reaches Master Data → Conversion Data', async ({ page }) => {
  await setup(page, { ...SESSION, permissions: ['master.conversion.view'] });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/conversion-data');
  await expect(page.getByRole('link', { name: 'Conversion Data' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'BOMs' })).toHaveCount(0);
});

test('a link to an old version opens that version even when it is not on the first page', async ({ page }) => {
  await setup(page);
  const v2 = { ...NW, id: '00000000-0000-7000-8000-000000000512', versionNo: 2, status: 'VALIDATED', netWeightKg: '0.288000' };
  const old = { ...NW, status: 'VALIDATED', effectiveTo: '2026-10-17' };
  // the server pages the versions: the requested one arrives on page 2
  await mockApi(page, '/query/planning.item_weights?*', (route) => {
    const second = decodeURIComponent(route.request().url()).includes('page[number]=2');
    return { status: 200, json: { data: [second ? old : v2], meta: { ...META, total: 2 } } };
  });
  await page.goto(`/master/conversion-data?weight=${ITEM}&version=${NW.id}`);
  await expect(page.getByRole('navigation', { name: 'Versions' }).locator('a[aria-current="page"]')).toContainText('Version 1');
  await expect(page.getByText('0.2905 kg/pc').first()).toBeVisible();
});

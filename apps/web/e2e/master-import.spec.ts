import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M2 step 6d (doc 29 §1.6, D-081, ADR-0017): Master Data → Data Exchange → Import against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const UPLOADER = { ...SESSION, permissions: ['exchange.import.create', 'exchange.import.submit', 'master.part.view'] };
const COMMITTER = {
  ...SESSION,
  permissions: ['exchange.import.create', 'exchange.import.submit', 'exchange.import.post', 'master.part.create', 'master.part.view'],
};
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-24T02:00:00Z', timezone: 'Asia/Jakarta' };

const TEMPLATES = [
  {
    code: 'items',
    version: 1,
    title: 'Items',
    mode: 'VALID_ROWS',
    permission: 'master.part.create',
    columns: [
      { header: 'Code', required: true, hint: '1–40 characters.' },
      { header: 'Name', required: true, hint: 'At most 200 characters.' },
      { header: 'Base unit', required: true, hint: 'Code of an active unit.' },
    ],
  },
  {
    code: 'routings',
    version: 1,
    title: 'Routings',
    mode: 'ALL_OR_NOTHING',
    permission: 'master.routing.create',
    columns: [
      { header: 'Item code', required: true, hint: null },
      { header: 'Op seq', required: true, hint: null },
    ],
  },
];

const JOB = {
  id: '00000000-0000-7000-8000-000000000901',
  template: 'items',
  templateVersion: 1,
  fileName: 'items-october.xlsx',
  fileSize: 18_432,
  status: 'VALIDATED',
  failureCode: null,
  failureDetail: null,
  rejectReason: null,
  retryOfJobId: null,
  rootJobId: '00000000-0000-7000-8000-000000000901',
  hasErrorFile: true,
  totalRows: 3,
  validRows: 2,
  errorRows: 1,
  skippedRows: 0,
  committedRows: 0,
  uploadedByName: 'ppc',
  submittedByName: null,
  submittedAt: null,
  committedByName: null,
  committedAt: null,
  createdAt: '2026-10-24T02:00:00Z',
  updatedAt: '2026-10-24T02:00:05Z',
  version: 2,
};
const ROWS = [
  { rowNo: 2, status: 'VALID', entityRef: null, values: { Code: 'BRK-A100', Name: 'Bracket A', 'Base unit': 'PCS' }, errors: [] },
  { rowNo: 3, status: 'ERROR', entityRef: null, values: { Code: 'BRK-B200', Name: 'Bracket B', 'Base unit': 'BOX' }, errors: ['Unit BOX does not exist.'] },
  { rowNo: 4, status: 'VALID', entityRef: null, values: { Code: 'NUT-M8', Name: 'Nut', 'Base unit': 'PCS' }, errors: [] },
];

async function setup(page: Page, me: object, job: Record<string, unknown> = JOB) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/exchange/import-templates', { status: 200, json: { data: TEMPLATES } });
  await mockApi(page, '/query/exchange.import_jobs?*', { status: 200, json: { data: [job], meta: { ...META, total: 1 } } });
  await mockApi(page, `/exchange/imports/${JOB.id}`, { status: 200, json: { data: job } });
  await mockApi(page, `/exchange/imports/${JOB.id}/rows?*`, (route) => {
    const status = new URL(route.request().url()).searchParams.get('status');
    const rows = status ? ROWS.filter((r) => r.status === status) : ROWS;
    return { status: 200, json: { data: rows, meta: { total: rows.length, page: { number: 1, size: 100 } } } };
  });
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('the job list shows each import with its result; a row opens the job', async ({ page }) => {
  await setup(page, UPLOADER);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/import');
  const row = page.getByRole('table', { name: 'Import jobs' }).getByRole('row', { name: /items-october\.xlsx/ });
  await expect(row).toContainText('Items');
  await expect(row).toContainText('Checked');
  await axe(page);
  await row.getByRole('button', { name: /Open/ }).click();
  await expect(page.getByRole('heading', { level: 1, name: 'items-october.xlsx' })).toBeVisible();
});

test('a new import shows the template columns and uploads the file as multipart', async ({ page }) => {
  await setup(page, UPLOADER);
  let upload: { template?: string; file?: boolean; type?: string } = {};
  await page.route('**/api/v1/exchange/imports', async (route) => {
    const req = route.request();
    upload = {
      type: req.headers()['content-type'],
      template: /name="template"\r\n\r\n(\w+)/.exec(req.postData() ?? '')?.[1],
      file: (req.postData() ?? '').includes('filename="items.xlsx"'),
    };
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...JOB, status: 'RESERVED', totalRows: 0, validRows: 0, errorRows: 0 } }),
    });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/import?new');
  await choose(page, page.getByRole('combobox', { name: 'Template' }), 'Items');
  await expect(page.getByRole('table', { name: 'Columns of the Items template' })).toContainText('Base unit');
  await expect(page.getByText('Valid rows are saved')).toBeVisible();
  // the uploader may not create parts: committing is someone else's step
  await expect(page.getByText('committing it needs a role that may change parts and partners')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Download empty template' })).toHaveAttribute('href', '/api/v1/exchange/import-templates/items/file');
  await axe(page);

  await page.locator('input[type=file]').setInputFiles({ name: 'notes.csv', mimeType: 'text/csv', buffer: Buffer.from('a,b') });
  await expect(page.getByText('Use the Excel template (.xlsx). “notes.csv” is not an .xlsx file.')).toBeVisible();
  await page.locator('input[type=file]').setInputFiles({ name: 'items.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('PK fake workbook') });
  await expect(page.getByText('items.xlsx')).toBeVisible();
  await page.getByRole('button', { name: 'Upload and check' }).click();
  await expect(page).toHaveURL(new RegExp(`job=${JOB.id}`));
  expect(upload.type).toMatch(/^multipart\/form-data; boundary=/);
  expect(upload).toMatchObject({ template: 'items', file: true });
});

test("the server's refusal of a file shows on the file", async ({ page }) => {
  await setup(page, UPLOADER);
  await mockApi(
    page,
    '/exchange/imports',
    problem(422, 'VALIDATION_FAILED', 'Validation failed', { errors: { file: ['Workbooks with macros are not accepted.'] } }),
  );
  await page.goto('/master/import?new&template=items');
  await page.locator('input[type=file]').setInputFiles({ name: 'items.xlsx', mimeType: 'application/octet-stream', buffer: Buffer.from('PK') });
  await page.getByRole('button', { name: 'Upload and check' }).click();
  await expect(page.getByText('Workbooks with macros are not accepted.')).toBeVisible();
});

test('a checked job opens on its error rows, offers the error file and is submitted for commit', async ({ page }) => {
  await setup(page, UPLOADER);
  const calls: string[] = [];
  await page.route('**/api/v1/exchange/imports/*/actions/*', (route) => {
    calls.push(route.request().url().split('/actions/')[1]!);
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...JOB, status: 'SUBMITTED', submittedAt: '2026-10-24T02:01:00Z', submittedByName: 'ppc' } }),
    });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(`/master/import?job=${JOB.id}`);
  await expect(page.getByRole('list', { name: 'Import steps' }).getByRole('listitem').nth(2)).toHaveAttribute('aria-current', 'step');
  await expect(page.getByRole('tab', { name: 'With errors, 1' })).toHaveAttribute('aria-selected', 'true');
  const table = page.getByRole('table', { name: 'Rows of this file' });
  await expect(table.getByRole('row', { name: /Row 3/ })).toContainText('Unit BOX does not exist.');
  await expect(page.getByRole('link', { name: 'Error file' })).toHaveAttribute('href', `/api/v1/exchange/imports/${JOB.id}/error-file`);
  await expect(page.getByRole('button', { name: 'Upload corrected file' })).toBeVisible();
  await axe(page);

  await page.getByRole('tab', { name: 'All rows, 3' }).click();
  await expect(table.getByRole('row', { name: /Row 4/ })).toContainText('NUT-M8');

  await page.getByRole('button', { name: 'Submit for commit' }).click();
  await expect(page.getByText('Submitted for commit')).toBeVisible();
  expect(calls).toEqual(['submit']);
  // the uploader cannot commit: the action says why
  await expect(page.getByRole('button', { name: 'Commit. Your roles do not allow committing imports.' })).toHaveAttribute('aria-disabled', 'true');
});

test('an all-or-nothing file with an error row cannot be submitted', async ({ page }) => {
  await setup(page, UPLOADER, { ...JOB, template: 'routings', fileName: 'routings.xlsx' });
  await page.goto(`/master/import?job=${JOB.id}`);
  await expect(page.getByText('This template imports everything or nothing. Fix the rows with errors and upload the file again.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Submit for commit. Every row must be valid for this template.' })).toHaveAttribute('aria-disabled', 'true');
});

test('a committer commits a submitted job, or rejects it with a reason', async ({ page }) => {
  const submitted = { ...JOB, status: 'SUBMITTED', submittedAt: '2026-10-24T02:01:00Z', submittedByName: 'ppc' };
  await setup(page, COMMITTER, submitted);
  const bodies: { action: string; body: unknown }[] = [];
  await page.route('**/api/v1/exchange/imports/*/actions/*', (route) => {
    const action = route.request().url().split('/actions/')[1]!;
    bodies.push({ action, body: route.request().postDataJSON() });
    const status = action === 'reject' ? 'REJECTED' : 'COMMITTING';
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { ...submitted, status, rejectReason: action === 'reject' ? 'Wrong period' : null } }),
    });
  });
  await page.goto(`/master/import?job=${JOB.id}`);
  await expect(page.getByText('Waiting for someone allowed to commit imports.')).toBeVisible();

  await page.getByRole('button', { name: 'Reject' }).click();
  const dialog = page.getByRole('alertdialog', { name: 'Reject this import' });
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Wrong period');
  await dialog.getByRole('button', { name: 'Reject import' }).click();
  await expect(page.getByText('Import rejected')).toBeVisible();
  expect(bodies).toEqual([{ action: 'reject', body: { reason: 'Wrong period' } }]);
  await expect(page.getByText('Wrong period')).toBeVisible();

  await setup(page, COMMITTER, submitted);
  await page.reload();
  await page.getByRole('button', { name: 'Commit', exact: true }).click();
  await expect(page.getByText('Commit started')).toBeVisible();
  expect(bodies.at(-1)?.action).toBe('commit');
});

test('committing needs the master permission of the template too', async ({ page }) => {
  await setup(
    page,
    { ...COMMITTER, permissions: ['exchange.import.create', 'exchange.import.post'] },
    { ...JOB, status: 'SUBMITTED', submittedAt: '2026-10-24T02:01:00Z' },
  );
  await page.goto(`/master/import?job=${JOB.id}`);
  await expect(page.getByRole('button', { name: 'Commit. Committing needs a role that may change parts and partners.' })).toHaveAttribute(
    'aria-disabled',
    'true',
  );
});

test('a file with the wrong headers fails at Check with the reason', async ({ page }) => {
  await setup(page, UPLOADER, {
    ...JOB,
    status: 'FAILED',
    failureCode: 'HEADER_MISMATCH',
    failureDetail: 'Missing columns: Base unit. Unknown columns: Unit.',
    hasErrorFile: false,
    totalRows: 0,
    validRows: 0,
    errorRows: 0,
  });
  await page.goto(`/master/import?job=${JOB.id}`);
  await expect(page.getByText('Missing columns: Base unit. Unknown columns: Unit.').first()).toBeVisible();
  await expect(page.getByRole('list', { name: 'Import steps' }).getByRole('listitem').nth(1)).toContainText('(failed)');
  await expect(page.getByRole('button', { name: 'Upload corrected file' })).toBeVisible();
  await expect(page.getByRole('table', { name: 'Rows of this file' })).toHaveCount(0);
});

test('import is closed to roles that may not upload', async ({ page }) => {
  await setup(page, { ...SESSION, permissions: ['master.part.view'] });
  await page.goto('/master/import');
  await expect(page.getByText('Import is not available for your roles')).toBeVisible();
});

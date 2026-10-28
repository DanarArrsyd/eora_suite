import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, problem, SESSION, test, type Page } from './fixtures';

/* M2 step 7c (D-092, ADR-0018): Export on every Master Data list and the Exports page, against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const EXPORTER = { ...SESSION, permissions: ['master.part.view', 'exchange.export.export'] };
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-10-28T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ITEM = {
  id: '00000000-0000-7000-8000-000000000701',
  code: 'BRK-100',
  name: 'Bracket',
  itemType: 'FINISHED',
  baseUomId: '00000000-0000-7000-8000-0000000000f1',
  baseUomCode: 'PCS',
  lotControlled: true,
  inspectionRequired: false,
  incomingReleaseRequired: false,
  drawingNo: null,
  drawingRev: null,
  status: 'ACTIVE',
  specId: null,
  version: 1,
};
const JOB = {
  id: '00000000-0000-7000-8000-000000000801',
  resource: 'core.items',
  title: 'Items',
  status: 'DONE',
  failureCode: null,
  failureDetail: null,
  rowCount: 1278,
  fileName: 'items-20261028-0900.xlsx',
  fileSize: 52_000,
  finishedAt: '2026-10-28T02:00:00Z',
  expiresAt: '2099-01-01T00:00:00Z',
  createdAt: '2026-10-28T01:59:00Z',
  version: 2,
};
const FAILED = {
  ...JOB,
  id: '00000000-0000-7000-8000-000000000802',
  status: 'FAILED',
  failureCode: 'TOO_MANY_ROWS',
  failureDetail: 'This list has 120,000 rows; an export holds at most 100,000.',
  rowCount: null,
  fileName: null,
  expiresAt: null,
};

async function setup(page: Page, me: object = EXPORTER, jobs: unknown[] = [JOB, FAILED]) {
  await mockApi(page, '/auth/me', { status: 200, json: me });
  await mockApi(page, '/query/core.uoms?*', page1([]));
  await mockApi(page, '/query/core.items?*', page1([ITEM]));
  await mockApi(page, '/query/exchange.export_jobs?*', page1(jobs));
}

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('Export on a list queues an Excel export of the list as it is filtered, searched and sorted', async ({ page }) => {
  await setup(page);
  const bodies: unknown[] = [];
  await page.route('**/api/v1/exchange/exports', (route) => {
    bodies.push(route.request().postDataJSON());
    return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { ...JOB, status: 'QUEUED' } }) });
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/items?q=brk&f.itemType=FINISHED&sort=-code');
  await page.getByRole('button', { name: 'Export' }).click();
  // PDF waits for the print templates (O-010): Excel only
  await expect(page.getByRole('menuitem', { name: /PDF/ })).toHaveCount(0);
  await page.getByRole('menuitem', { name: /Excel \(\.xlsx\)/ }).click();
  await expect(page.getByText('Excel export queued').first()).toBeVisible();
  expect(bodies).toEqual([{ resource: 'core.items', filter: { itemType: 'FINISHED' }, q: 'brk', sort: '-code' }]);
});

test("the server's refusal (too many rows) is shown, and roles without export see why", async ({ page }) => {
  await setup(page);
  await mockApi(
    page,
    '/exchange/exports',
    problem(422, 'VALIDATION_FAILED', 'Validation failed', {
      errors: { query: ['This list has 120,000 rows; an export holds at most 100,000. Narrow the filter or the search.'] },
    }),
  );
  await page.goto('/master/items');
  await page.getByRole('button', { name: 'Export' }).click();
  await page.getByRole('menuitem', { name: /Excel/ }).click();
  await expect(page.getByText('Export could not be queued').first()).toBeVisible();
  // the toast may also be repeated in a live region for screen readers
  await expect(page.getByText('This list has 120,000 rows; an export holds at most 100,000.').first()).toBeVisible();

  await setup(page, { ...SESSION, permissions: ['master.part.view'] });
  await page.reload();
  await page.getByRole('button', { name: 'Export' }).click();
  await expect(page.getByRole('menuitem', { name: /Excel/ })).toHaveAttribute('aria-disabled', 'true');
  await expect(page.getByText('Your roles do not allow exports.')).toBeVisible();
});

test('the Exports page lists the requester’s exports; Download fetches a signed link', async ({ page }) => {
  await setup(page);
  let linked = 0;
  await mockApi(page, `/exchange/exports/${JOB.id}/link`, () => {
    linked++;
    return { status: 200, json: { data: { url: `/api/v1/exchange/exports/${JOB.id}/file?expires=1&signature=abc`, expiresAt: '2026-10-28T02:15:00Z' } } };
  });
  await page.route(`**/api/v1/exchange/exports/${JOB.id}/file?*`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      body: 'xlsx',
      headers: { 'content-disposition': 'attachment; filename=items.xlsx' },
    }),
  );
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/master/exports');
  const table = page.getByRole('table', { name: 'Exports' });
  await expect(table.getByRole('row', { name: /Items/ }).first()).toContainText('Ready');
  await expect(table.getByRole('row', { name: /Items/ }).nth(1)).toContainText('This list has 120,000 rows');
  await axe(page);

  const download = page.waitForEvent('download');
  await table
    .getByRole('button', { name: /Download Items/ })
    .first()
    .click();
  expect((await download).suggestedFilename()).toBe('items.xlsx');
  expect(linked).toBe(1);
  // a failed export cannot be downloaded and says why
  await expect(table.getByRole('button', { name: /Download Items/ }).nth(1)).toHaveAttribute('aria-disabled', 'true');
});

test('a notification link (/system/exports?job=…) opens the Exports page on that export', async ({ page }) => {
  await setup(page);
  await page.goto(`/system/exports?job=${JOB.id}`);
  await expect(page).toHaveURL(new RegExp(`/master/exports\\?job=${JOB.id}`));
  await expect(page.getByText(/^Items: Ready until/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Download', exact: true })).toBeVisible();
});

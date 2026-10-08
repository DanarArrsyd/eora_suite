import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/** Phase 2 shared patterns: enterprise list (URL state, filters, table, pagination), dialogs, forms, import/export, notifications. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const LIST = '/system/examples/raw-material-lots';

async function axe(page: Page, name: string) {
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${name} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}
const table = (page: Page) => page.getByRole('table', { name: 'Raw material lots' });
const total = (page: Page) => page.getByText(/^\d[\d,]* lots$/).first();

test.describe('enterprise list example (desktop)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
  });

  test('filters apply server-side, sync to the URL, survive reload and are removable as chips', async ({ page }) => {
    await page.goto(LIST);
    await expect(table(page).getByRole('row')).toHaveCount(22); // 2 header rows + 20
    const form = page.getByRole('form', { name: 'Filters' });
    await form.getByRole('combobox', { name: 'Warehouse' }).selectOption('RM-WH2');
    await form.getByRole('button', { name: 'Apply' }).click();
    await expect(page).toHaveURL(/f\.warehouse=RM-WH2/);
    await expect(total(page)).not.toHaveText('214 lots');
    const filtered = await total(page).textContent();
    await page.reload();
    await expect(total(page)).toHaveText(filtered!);
    await expect(page.getByRole('group', { name: 'Active filters' })).toContainText('Warehouse: RM-WH2');
    await expect(page.getByRole('group', { name: 'Active filters' }).getByRole('button', { name: /Remove filter Plant/ })).toHaveCount(0);
    await page.getByRole('button', { name: 'Remove filter Warehouse: RM-WH2' }).click();
    await expect(page).not.toHaveURL(/f\.warehouse/);
    await expect(total(page)).toHaveText('214 lots');
  });

  test('status tab, sort, search and pagination drive the URL', async ({ page }) => {
    await page.goto(LIST);
    await page.getByRole('tab', { name: /^Hold, \d+/ }).click();
    await expect(page).toHaveURL(/view=hold/);
    await page.getByRole('tab', { name: /^All, 214/ }).click();
    await table(page).getByRole('button', { name: 'Lot', exact: true }).click();
    await expect(page).toHaveURL(/sort=lot\.desc/);
    await expect(table(page).getByRole('columnheader', { name: /Lot/ })).toHaveAttribute('aria-sort', 'descending');
    await page
      .getByRole('navigation', { name: /Pagination/ })
      .getByRole('button', { name: /Next/ })
      .click();
    await expect(page).toHaveURL(/page=2/);
    await page.getByRole('searchbox', { name: 'Search lots' }).fill('SPHC');
    await expect(page).toHaveURL(/q=SPHC/);
    await expect(page).not.toHaveURL(/page=2/);
  });

  test('density presets are exact, persist, and compact hides sub-lines only', async ({ page }) => {
    await page.goto(LIST);
    const firstRow = table(page).locator('tbody tr').first();
    await expect(firstRow).toHaveJSProperty('offsetHeight', 44);
    await page.getByRole('button', { name: 'Compact' }).click();
    await expect(page.getByRole('button', { name: 'Compact' })).toHaveAttribute('aria-pressed', 'true');
    await expect(firstRow).toHaveJSProperty('offsetHeight', 34);
    await page.reload();
    await expect(table(page).locator('tbody tr').first()).toHaveJSProperty('offsetHeight', 34);
    await expect(table(page).getByRole('columnheader', { name: 'Available' })).toBeVisible();
    await page.getByRole('button', { name: 'Comfortable' }).click();
  });

  test('column settings hide optional columns but never critical ones', async ({ page }) => {
    await page.goto(LIST);
    await page.getByRole('button', { name: 'Column settings' }).click();
    const dialog = page.getByRole('dialog', { name: 'Column settings' });
    await expect(dialog.getByRole('checkbox', { name: /Available/ })).toBeDisabled();
    await dialog.getByRole('checkbox', { name: 'Material' }).uncheck();
    await page.keyboard.press('Escape');
    await expect(table(page).getByRole('columnheader', { name: 'Material' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Column settings, 1 hidden' })).toBeFocused();
  });

  test('row menu keeps unavailable actions focusable with their reason; drawer returns focus', async ({ page }) => {
    await page.goto(LIST);
    const more = table(page)
      .getByRole('button', { name: /^More actions for lot/ })
      .first();
    await more.click();
    const item = page.getByRole('menuitem', { name: /Request stock adjustment/ });
    await expect(item).toHaveAttribute('aria-disabled', 'true');
    await expect(item).toContainText('Requires Inventory Controller role');
    await page.keyboard.press('Escape');
    await expect(more).toBeFocused();
    const view = table(page)
      .getByRole('button', { name: /^View lot/ })
      .first();
    await view.click();
    await expect(page.getByRole('dialog', { name: /^Lot / })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(view).toBeFocused();
  });

  test('selection shows the bulk bar and resets on page change', async ({ page }) => {
    await page.goto(LIST);
    await page.getByRole('checkbox', { name: 'Select all rows on this page' }).check();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toContainText('20 selected on this page');
    await page
      .getByRole('navigation', { name: /Pagination/ })
      .getByRole('button', { name: 'Page 2' })
      .click();
    await expect(page.getByRole('region', { name: 'Bulk actions' })).toHaveCount(0);
  });

  test('adapter states: error with reference, no permission, first-run empty, stale', async ({ page }) => {
    await page.goto(`${LIST}?simulate=error`);
    await expect(page.getByRole('alert').filter({ hasText: 'could not be loaded' })).toContainText('Reference 7c1e-55a2');
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
    await page.goto(`${LIST}?simulate=forbidden`);
    await expect(page.getByText('You do not have access to this view')).toBeVisible();
    await page.goto(`${LIST}?simulate=empty`);
    await expect(page.getByText('No raw material lots yet')).toBeVisible();
    await page.goto(`${LIST}?simulate=stale`);
    await expect(page.getByText('Data may be outdated')).toBeVisible();
  });

  test('export queues a job toast; import wizard validates, shows row errors and routes to approval', async ({ page }) => {
    await page.goto(LIST);
    await page.getByRole('button', { name: 'Export' }).click();
    await page.getByRole('menuitem', { name: /Excel/ }).click();
    await expect(page.getByText('Excel export queued', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Import' }).click();
    const dlg = page.getByRole('dialog', { name: 'Import raw material lots' });
    await dlg.getByRole('button', { name: 'Continue' }).click();
    await dlg
      .getByLabel(/File/)
      .setInputFiles({ name: 'lots.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: Buffer.from('sample') });
    await dlg.getByRole('button', { name: 'Validate file' }).click();
    await expect(dlg.getByRole('table', { name: 'Row errors' })).toBeVisible();
    await expect(dlg.getByRole('listitem').filter({ hasText: 'Review' })).toHaveAttribute('aria-current', 'step');
    await dlg.getByRole('button', { name: 'Submit for approval' }).click();
    await expect(dlg.getByText('Import submitted for approval')).toBeVisible();
  });

  test('axe: list, open filter popovers and import dialog', async ({ page }) => {
    await page.goto(LIST);
    await expect(table(page).locator('tbody tr')).toHaveCount(20);
    await axe(page, 'list');
    await page.getByRole('button', { name: /^Grade/ }).click();
    await axe(page, 'grade popover');
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Import' }).click();
    await axe(page, 'import');
  });
});

test.describe('enterprise list example (phone)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
  });

  test('filter bar becomes a bottom sheet with dialog semantics; Esc returns focus', async ({ page }) => {
    await page.goto(LIST);
    await expect(page.getByRole('form', { name: 'Filters' })).toHaveCount(0);
    const open = page.getByRole('button', { name: /^Filters/ });
    await open.click();
    const sheet = page.getByRole('dialog', { name: 'Filters' });
    await expect(sheet).toBeVisible();
    await expect(sheet.getByRole('combobox', { name: 'Warehouse' })).toHaveCSS('font-size', '16px');
    await axe(page, 'filter sheet');
    await page.keyboard.press('Escape');
    await expect(sheet).toHaveCount(0);
    await expect(open).toBeFocused();
  });

  test('rows reflow into cards without page overflow; zero buckets are hidden', async ({ page }) => {
    await page.goto(LIST);
    await expect(table(page).locator('tbody tr')).toHaveCount(20);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    const card = table(page).locator('tbody tr').first();
    const hidden = await card.locator('td').evaluateAll((tds) => tds.filter((td) => getComputedStyle(td).display === 'none').length);
    expect(hidden).toBeGreaterThan(0);
    await expect(page.getByRole('navigation', { name: /Pagination/ }).getByRole('button', { name: /Next/ })).toHaveJSProperty('offsetHeight', 44);
    await axe(page, 'phone list');
  });
});

test.describe('pattern showcase and notifications', () => {
  test('form summary takes focus and lists errors; confirm dialog requires a reason', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/system/patterns');
    const form = page.getByRole('form', { name: 'Request material issue (sample)' });
    await form.getByRole('button', { name: 'Submit request' }).click();
    await expect(form.getByRole('alert')).toContainText('Fix 3 fields before submitting');
    await expect(form.locator('[tabindex="-1"]')).toBeFocused();
    await page.getByRole('button', { name: 'Request reversal…' }).click();
    const dlg = page.getByRole('alertdialog', { name: /Request reversal/ });
    await expect(dlg.getByRole('button', { name: 'Cancel' })).toBeFocused();
    await dlg.getByRole('button', { name: 'Request reversal' }).click();
    await expect(dlg.getByText('Enter at least 10 characters')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Request reversal…' })).toBeFocused();
  });

  test('bell panel lists actionable items equal to the badge and links to the inbox page', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/ppic');
    await page.getByRole('button', { name: 'Notifications: 2 need action' }).click();
    const panel = page.getByRole('dialog', { name: 'Notifications' });
    await expect(panel.getByRole('button', { name: /Needs action/ })).toContainText('2');
    await axe(page, 'bell panel');
    await panel.getByRole('link', { name: 'View all notifications' }).click();
    await expect(page).toHaveURL(/\/system\/notifications$/);
    await expect(page.getByRole('heading', { level: 1, name: /Notifications/ })).toBeVisible();
  });
});

test('hydration keeps a single page tree (no orphaned streamed segments)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  for (const route of ['/ppic', '/system/ui-kit', '/system/patterns', LIST, '/system/notifications']) {
    await page.goto(route);
    await page.waitForTimeout(1500);
    await expect(page.locator('[id^="S:"]'), route).toHaveCount(0);
    await expect(page.locator('main h1'), route).toHaveCount(1);
  }
});

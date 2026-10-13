import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from './fixtures';

/** Phase 3 PPIC Control Tower: data concepts per docs 02/14/15/19 rendered from typed mock contracts. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
async function axe(page: Page, name: string) {
  // Scan the settled page: refetching tables are dimmed on purpose (aria-busy) and would fail contrast mid-transition.
  await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
  await page.waitForTimeout(300);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${name} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}
const row = (page: Page, table: string, text: string | RegExp) => page.getByRole('table', { name: table }).locator('tbody tr').filter({ hasText: text });

test.beforeEach(async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
});

test('CT-0: four decision KPIs with definition, basis and drill link; UOM bands kept apart', async ({ page }) => {
  await page.goto('/ppic/control-tower');
  const kpis = page.getByRole('region', { name: 'Decision KPIs' }).getByRole('article');
  await expect(kpis).toHaveCount(4);
  for (const k of await kpis.all()) {
    await expect(k.getByText('Definition & basis')).toBeVisible();
    await expect(k.getByRole('link', { name: /^Open source list/ })).toBeVisible();
  }
  const flow = page.getByRole('region', { name: 'Material flow' });
  await expect(flow.getByText('Physical material · kg / sheet')).toBeVisible();
  await expect(flow.getByText('Production, quality, FG & shipment · pcs')).toBeVisible();
  await expect(flow.getByRole('link', { name: /QC released · not received.*Not FG available/ })).toBeVisible();
  await expect(page.getByText(/loss/i).filter({ hasNotText: /not confirmed material loss|Loss investigation/i })).toHaveCount(0);
  await axe(page, 'CT-0');
});

test('CT-0: filters scope the overview via the URL and exceptions drill to the source list', async ({ page }) => {
  await page.goto('/ppic/control-tower');
  const form = page.getByRole('form', { name: 'Filters' });
  await form.getByRole('combobox', { name: 'Part' }).selectOption('PART-C');
  await form.getByRole('button', { name: 'Apply' }).click();
  await expect(page).toHaveURL(/f\.part=PART-C/);
  const queue = page.getByRole('table', { name: 'Exception queue' });
  await expect(queue.locator('tbody tr').first()).toContainText('PART-C');
  await queue.getByRole('button', { name: /^Open PART-C · DSL-31/ }).click();
  await expect(page).toHaveURL(/\/ppic\/shortage\?f\.part=PART-C/);
  await expect(page.getByRole('heading', { level: 1, name: /Shortage & Delivery Risk/ })).toBeVisible();
});

test('CT-0 chart: legend, risk marks and a table view', async ({ page }) => {
  await page.goto('/ppic/control-tower');
  await expect(page.getByRole('group', { name: /Outstanding due versus projected supply/ })).toBeVisible();
  await page.getByRole('button', { name: 'Show as table' }).click();
  await expect(page.getByRole('table', { name: /Due versus projected supply/ })).toContainText('At risk');
});

test('CT-9: weekly netting, unscheduled blanket balance, revision history and partial fulfilment (doc 14 #28–#47)', async ({ page }) => {
  await page.goto('/ppic/demand-fulfillment');
  const w42 = row(page, 'Weekly demand by bucket', /2026-W42.*PART-A.*CUST-01/);
  await expect(w42).toContainText('1,200'); // forecast 1,200 consumed by firm 250 → outstanding demand 1,200, not 1,450
  await expect(row(page, 'Weekly demand by bucket', /PART-C.*No customer/)).toContainText('Needs review');
  const blanket = row(page, 'Blanket order balances', 'SO-2002/1');
  await expect(blanket).toContainText('10,000');
  await expect(blanket).toContainText('8,700');
  await page.getByRole('tab', { name: /^Fulfilled & superseded/ }).click();
  await expect(row(page, 'Demand & Fulfillment', /^DSL-3Customer/)).toContainText('Superseded');
  await page.getByRole('tab', { name: /^Partially fulfilled/ }).click();
  const dsl1 = row(page, 'Demand & Fulfillment', 'DSL-1');
  await expect(dsl1).toContainText('150');
  await dsl1.getByRole('button', { name: 'View schedule line DSL-1' }).click();
  const drawer = page.getByRole('dialog', { name: 'Schedule line DSL-1' });
  await expect(drawer).toContainText('Shipment SHP-1');
  await expect(drawer.getByRole('link', { name: /Open CPO-1001\/10/ })).toHaveAttribute('href', '/demand/customer-po');
  await page.keyboard.press('Escape');
  await page.getByRole('tab', { name: /^Exceptions/ }).click();
  await expect(row(page, 'Demand & Fulfillment', 'DSL-42')).toContainText('Revision mapping needs confirmation');
  await axe(page, 'CT-9');
});

test('CT-8: a daily due is at risk although the week is covered (doc 14 #48); pending QC is not projected', async ({ page }) => {
  await page.goto('/ppic/shortage');
  await expect(row(page, 'Shortage & Delivery Risk', 'DSL-31')).toContainText('At risk');
  await expect(row(page, 'Shortage & Delivery Risk', 'DSL-32')).toContainText('Covered');
  await row(page, 'Shortage & Delivery Risk', 'DSL-41').getByRole('button', { name: /^View/ }).click();
  await expect(page.getByRole('dialog')).toContainText('Pending QC (shown, not projected)');
});

test('Conversion: coil sample 1,930 est. pcs; unit-less pitch is not calculable (doc 14 #5, #49)', async ({ page }) => {
  await page.goto('/ppic/conversion?q=C-21');
  const c21 = row(page, 'Coil / Sheet Conversion', 'PART-A');
  await expect(c21).toContainText('est.');
  await expect(c21).toContainText('1,930');
  await expect(page.getByRole('table', { name: 'Coil / Sheet Conversion' }).locator('tbody tr')).toHaveCount(2); // PART-A and PART-F, never summed
  await page.goto('/ppic/conversion?view=notCalculable');
  await expect(page.getByRole('table', { name: 'Coil / Sheet Conversion' })).toContainText('missing: pitch unit');
  await axe(page, 'conversion');
});

test('Pending QC vs FG available stay separate (doc 14 #50, CT-A2, CT-A3)', async ({ page }) => {
  await page.goto('/ppic/pending-quality');
  const il = row(page, 'Pending Quality', 'IL-0412');
  await expect(il).toContainText('200');
  await expect(il).toContainText('300');
  await page.goto('/ppic/fg-availability?f.part=PART-D');
  const fg = row(page, 'Finished Goods Availability', 'PART-D');
  await expect(fg).toContainText('300'); // incoming from QC, not available
  await expect(fg.locator('td').nth(4)).toContainText('zero');
});

test('CT-6: indicators match ADR-0005 sample, finality shown, no total loss figure', async ({ page }) => {
  await page.goto('/ppic/material-variance');
  const run = row(page, 'Material Variance', 'RUN-0412');
  await expect(run).toContainText('+47');
  await expect(run).toContainText('−17');
  await expect(run).toContainText('+7.610');
  await expect(run.getByText('Final').first()).toBeVisible();
  await expect(page.getByRole('region', { name: /Gross yield variance status/ })).toContainText('no total “loss” figure');
  await run.getByRole('button', { name: 'View run RUN-0412' }).click();
  await expect(page.getByRole('dialog', { name: 'Run RUN-0412' })).toContainText('None (only from a resolved case)');
  await axe(page, 'CT-6 drawer');
});

test('adapter states on a CT screen: error with reference, forbidden, stale', async ({ page }) => {
  await page.goto('/ppic/rm-availability?simulate=error');
  await expect(page.getByRole('alert').filter({ hasText: 'could not be loaded' })).toContainText('Reference ct-7c1e');
  await page.goto('/ppic/rm-availability?simulate=forbidden');
  await expect(page.getByText('You do not have access to this view')).toBeVisible();
  await page.goto('/ppic/control-tower?simulate=stale');
  await expect(page.getByText('Read model is stale')).toBeVisible();
});

for (const route of ['/ppic/control-tower', '/ppic/demand-fulfillment', '/ppic/material-variance']) {
  test(`phone layout without page overflow and axe clean — ${route}`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(route);
    await page.waitForTimeout(800);
    expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
    await axe(page, `phone ${route}`);
  });
}

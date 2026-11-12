import { expect, test, type Page } from './fixtures';

const WIDTHS = [1920, 1600, 1440, 1280, 1200, 1024, 768, 640, 390, 375];
const ROUTES = [
  '/ppic',
  '/ppic/control-tower',
  '/ppic/work-orders',
  '/inventory/raw-material',
  '/system/ui-kit',
  '/system/patterns',
  '/system/examples/raw-material-lots',
  '/system/notifications',
  '/ppic/demand-fulfillment',
  '/ppic/material-variance',
];

async function visible(page: Page, selector: string) {
  return page.locator(selector).first().isVisible();
}

for (const width of WIDTHS) {
  test(`no page-level horizontal overflow and required topbar items @${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    for (const route of ROUTES) {
      await page.goto(route);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${route} overflow`).toBeLessThanOrEqual(0);
      const header = page.locator('header').first();
      await expect(header.getByRole('button', { name: /^Notifications/ }), `${route} bell`).toBeVisible();
      await expect(header.getByRole('button', { name: /^Account menu/ }), `${route} profile`).toBeVisible();
      await expect(page.locator('nav[aria-label="Breadcrumb"] [aria-current="page"]'), `${route} current crumb`).toBeVisible();
      if (width < 768) {
        await expect(header.getByRole('button', { name: 'Menu', exact: true })).toBeVisible();
        await expect(header.getByRole('button', { name: 'Company, plant and page context' })).toBeVisible();
      } else {
        await expect(page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
        await expect(header.getByRole('button', { name: /^Workspace:/ })).toBeVisible();
      }
    }
  });
}

/** The shell fills the viewport and never scrolls as a page: long module menus scroll inside their sidebar. */
for (const size of [
  { width: 1440, height: 640 },
  { width: 1024, height: 600 },
  { width: 375, height: 640 },
]) {
  test(`no page-level vertical overflow on a short screen @${size.width}x${size.height}`, async ({ page }) => {
    await page.setViewportSize(size);
    for (const route of ['/ppic', '/master', '/inventory/raw-material']) {
      await page.goto(route);
      await expect(page.locator('header').first()).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
      expect(overflow, `${route} vertical overflow`).toBeLessThanOrEqual(0);
    }
  });
}

/** D-028 search presentation per viewport and page context. */
const SEARCH_CASES: { width: number; route: string; expected: 'full' | 'compact' | 'icon' }[] = [
  { width: 1920, route: '/ppic/control-tower', expected: 'full' },
  { width: 1600, route: '/ppic/control-tower', expected: 'full' },
  { width: 1500, route: '/ppic/control-tower', expected: 'compact' },
  { width: 1440, route: '/ppic/control-tower', expected: 'compact' },
  { width: 1300, route: '/ppic/control-tower', expected: 'icon' },
  { width: 1300, route: '/ppic/work-orders', expected: 'compact' },
  { width: 1200, route: '/ppic/work-orders', expected: 'compact' },
  { width: 1199, route: '/ppic/work-orders', expected: 'icon' },
  { width: 390, route: '/ppic/work-orders', expected: 'icon' },
];
for (const c of SEARCH_CASES) {
  test(`search is ${c.expected} at ${c.width}px on ${c.route} (D-028)`, async ({ page }) => {
    await page.setViewportSize({ width: c.width, height: 900 });
    await page.goto(c.route);
    for (const mode of ['full', 'compact', 'icon'] as const) {
      expect(await visible(page, `[data-search="${mode}"]`), `${mode} visibility`).toBe(mode === c.expected);
    }
  });
}

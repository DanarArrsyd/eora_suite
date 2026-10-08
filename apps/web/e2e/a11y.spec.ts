import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ROUTES = [
  '/ppic',
  '/ppic/control-tower',
  '/inventory/raw-material',
  '/system/ui-kit',
  '/system/patterns',
  '/system/examples/raw-material-lots',
  '/system/notifications',
  '/ppic/daily-delivery',
  '/ppic/shortage',
  '/ppic/rm-availability',
  '/ppic/material-at-production',
  '/ppic/wo-progress',
  '/ppic/daily-results',
  '/ppic/pending-quality',
  '/ppic/daily-fg',
  '/ppic/fg-availability',
  '/does-not-exist',
];

for (const [label, width] of [
  ['desktop', 1440],
  ['mobile', 390],
] as const) {
  for (const route of ROUTES) {
    test(`axe ${TAGS.join('/')} — ${route} (${label})`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(route);
      // Scan the settled page: no pending data (aria-busy) and colour transitions finished (120–180 ms tokens).
      await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
      await page.waitForTimeout(300);
      const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();
      expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    });
  }
}

test('axe — open overlays: search dialog, workspace popover, account menu, notifications (desktop)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/control-tower');
  const scan = async (name: string) => {
    const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
    expect(r.violations.map((v) => `${name} ${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
  };
  await page.keyboard.press('Control+k');
  await scan('search');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: /^Workspace:/ }).click();
  await scan('workspace');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: /^Notifications/ }).click();
  await scan('notifications');
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: /^Account menu/ }).click();
  await scan('account');
});

test('axe — mobile navigation sheet open', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ppic/control-tower');
  await page.getByRole('button', { name: 'Menu', exact: true }).click();
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => v.id)).toEqual([]);
});

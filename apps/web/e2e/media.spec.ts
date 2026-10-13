import AxeBuilder from '@axe-core/playwright';
import { expect, test } from './fixtures';

/** Automated proxies for user preferences (doc 22 §6). They do not replace manual high-contrast / OS-level validation. */

test('reduced motion — transitions collapse to 0 s across the shell', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/control-tower');
  const animated = await page.evaluate(
    () =>
      [...document.querySelectorAll<HTMLElement>('body *')]
        .map((el) => getComputedStyle(el))
        .filter((cs) => cs.transitionDuration.split(',').some((d) => parseFloat(d) > 0) || cs.animationDuration.split(',').some((d) => parseFloat(d) > 0))
        .length,
  );
  expect(animated).toBe(0);
});

test('forced colors — focus indicator stays visible and axe reports no violations', async ({ page }) => {
  await page.emulateMedia({ forcedColors: 'active' });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/system/ui-kit');
  for (let i = 0; i < 4; i += 1) {
    await page.keyboard.press('Tab');
    const outline = await page.evaluate(() => {
      const cs = getComputedStyle(document.activeElement as Element);
      return { style: cs.outlineStyle, width: parseFloat(cs.outlineWidth) };
    });
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBeGreaterThanOrEqual(2);
  }
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(results.violations.map((v) => v.id)).toEqual([]);
});

for (const [label, width] of [
  ['desktop', 1440],
  ['mobile', 390],
] as const) {
  test(`landmarks — one banner, one main, labelled navigation (${label})`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/inventory/raw-material');
    await expect(page.getByRole('banner')).toHaveCount(1);
    await expect(page.getByRole('main')).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toBeVisible();
    if (label === 'desktop') await expect(page.getByRole('navigation', { name: 'Modules' })).toBeVisible();
    const unlabelledNav = await page.evaluate(
      () =>
        [...document.querySelectorAll('nav, [role="navigation"]')].filter((n) => !n.getAttribute('aria-label') && !n.getAttribute('aria-labelledby')).length,
    );
    expect(unlabelledNav).toBe(0);
    await expect(page.getByRole('main').getByRole('heading', { level: 1 })).toHaveCount(1);
  });
}

test('status tabs — keyboard selection updates the panel (UI kit)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/system/ui-kit');
  const list = page.getByRole('tablist', { name: 'View by' });
  await list.getByRole('tab', { name: 'All, 11' }).focus();
  await page.keyboard.press('ArrowRight');
  await expect(list.getByRole('tab', { name: 'Unrestricted, 6' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('tabpanel', { name: /Unrestricted/ })).toContainText('unrestricted');
});

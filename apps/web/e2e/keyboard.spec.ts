import { expect, test } from '@playwright/test';

test('skip link is the first tab stop and moves focus to main', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/control-tower');
  await page.keyboard.press('Tab');
  const skip = page.getByRole('link', { name: 'Skip to main content' });
  await expect(skip).toBeFocused();
  await expect(skip).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main')).toBeFocused();
});

test('Ctrl+K and Cmd+K open search; Escape closes and returns focus', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/work-orders');
  const trigger = page.locator('[data-search="compact"]');
  await trigger.focus();
  await page.keyboard.press('Control+k');
  await expect(page.getByRole('dialog', { name: 'Search records' })).toBeVisible();
  await expect(page.getByRole('textbox', { name: 'Search records' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog', { name: 'Search records' })).toBeHidden();
  await expect(trigger).toBeFocused();
  await page.keyboard.press('Meta+k');
  await expect(page.getByRole('dialog', { name: 'Search records' })).toBeVisible();
});

test('focus ring visible on every tab stop in the shell (first 25)', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic/control-tower');
  const missing: string[] = [];
  for (let i = 0; i < 25; i++) {
    await page.keyboard.press('Tab');
    const r = await page.evaluate(() => {
      const a = document.activeElement as HTMLElement;
      const s = getComputedStyle(a);
      const ok = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2) || (s.boxShadow !== 'none' && s.boxShadow !== '');
      return ok ? '' : `${a.tagName} ${a.getAttribute('aria-label') ?? a.textContent?.trim().slice(0, 30)}`;
    });
    if (r) missing.push(r);
  }
  expect(missing).toEqual([]);
});

test('mobile navigation: focus management, scroll lock, Escape and backdrop', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ppic/control-tower');
  // CSS locator: while the modal is open Radix hides outside content from the accessibility tree (expected).
  const trigger = page.locator('header button[aria-haspopup="dialog"]', { hasText: 'Menu' });
  await trigger.click();
  const sheet = page.getByRole('dialog', { name: 'EORA Suite' });
  await expect(sheet).toBeVisible();
  await expect(sheet.getByRole('button', { name: 'Close navigation' })).toBeFocused();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  const locked = await page.evaluate(() => getComputedStyle(document.body).overflow === 'hidden' || document.body.hasAttribute('data-scroll-locked'));
  expect(locked).toBe(true);
  for (let i = 0; i < 30; i++) await page.keyboard.press('Tab');
  expect(await sheet.evaluate((el) => el.contains(document.activeElement))).toBe(true);
  await page.keyboard.press('Escape');
  await expect(sheet).toBeHidden();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await expect(sheet).toBeVisible();
  await page.mouse.click(380, 400); // backdrop area right of the sheet
  await expect(sheet).toBeHidden();
});

test('mobile navigation drill-down navigates and closes', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/ppic/control-tower');
  await page.getByRole('button', { name: 'Menu', exact: true }).click();
  const sheet = page.getByRole('dialog', { name: 'EORA Suite' });
  await sheet.getByRole('button', { name: 'All modules' }).click();
  await sheet.getByRole('button', { name: /^Inventory/ }).click();
  await sheet.getByRole('link', { name: 'Raw Material' }).click();
  await expect(page).toHaveURL(/\/inventory\/raw-material$/);
  await expect(sheet).toBeHidden();
});

test('tablet: module menu overlay has backdrop and closes with Escape', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.goto('/ppic/control-tower');
  const toggle = page.getByRole('button', { name: 'Show or hide module menu' });
  await toggle.click();
  const overlay = page.getByRole('dialog', { name: 'PPIC / Planning' });
  await expect(overlay).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(overlay).toBeHidden();
  await expect(toggle).toBeFocused();
});

test('200% zoom proxy (640 CSS px): mobile layout, content reachable, no overflow', async ({ page }) => {
  await page.setViewportSize({ width: 640, height: 450 });
  await page.goto('/system/ui-kit');
  await expect(page.getByRole('button', { name: 'Menu', exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0);
  const last = page.getByText('Loading raw material lots…');
  await last.scrollIntoViewIfNeeded();
  await expect(last).toBeInViewport();
});

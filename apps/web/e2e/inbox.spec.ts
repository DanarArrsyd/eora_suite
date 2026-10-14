import AxeBuilder from '@axe-core/playwright';
import { expect, INBOX, mockApi, test } from './fixtures';

/* M1 live inbox (doc 07; GET /inbox, POST /inbox/read) behind the bell and on /system/notifications, mocked API. */

test('notifications page shows the server inbox without a mock label, with severity and action badges', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/system/notifications');
  const inbox = page.getByRole('region', { name: 'Inbox' });
  await expect(inbox.getByText('Sensitive role granted', { exact: true })).toBeVisible();
  await expect(inbox.getByText('Mock data')).toHaveCount(0);
  const warning = inbox.getByRole('listitem').filter({ hasText: 'Sensitive role granted' });
  await expect(warning).toContainText('Warning');
  await expect(warning).toContainText('(unread)');
  const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(r.violations.map((v) => v.id)).toEqual([]);
});

test('mark as read sends the id; mark all asks the server for the whole inbox (no ids)', async ({ page }) => {
  const sent: unknown[] = [];
  await mockApi(page, '/inbox/read', (route) => {
    sent.push(route.request().postDataJSON());
    return { status: 200, json: { meta: { updated: 1 } } };
  });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/system/notifications');
  const inbox = page.getByRole('region', { name: 'Inbox' });
  await inbox.getByRole('button', { name: /^Mark as read\s*: Sensitive role granted$/ }).click();
  await expect.poll(() => sent.length).toBe(1);
  expect(sent[0]).toEqual({ ids: [INBOX[2]!.id] });
  await inbox.getByRole('button', { name: 'Mark all as read' }).click();
  await expect.poll(() => sent.length).toBe(2);
  expect(sent[1]).toEqual({});
});

test('the bell counts only items needing action; with none, unread shows as a dot, not a number', async ({ page }) => {
  await mockApi(page, '/inbox', { status: 200, json: { data: INBOX.slice(2), meta: { unread: 1, actionRequired: 0 } } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic');
  const bell = page.getByRole('button', { name: 'Notifications: 1 unread' });
  await expect(bell).toBeVisible();
  await expect(bell).not.toContainText('1');
});

test('bell totals come from the whole inbox, not only the loaded page', async ({ page }) => {
  // 50 newest items are all read and informational; older ones still need action / are unread
  await mockApi(page, '/inbox', { status: 200, json: { data: [INBOX[3]], meta: { unread: 7, actionRequired: 3 } } });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/ppic');
  await page.getByRole('button', { name: 'Notifications: 3 need action, 7 unread' }).click();
  const panel = page.getByRole('dialog', { name: 'Notifications' });
  await expect(panel.getByRole('button', { name: /Needs action/ })).toHaveAttribute('aria-pressed', 'true');
  await expect(panel.getByRole('button', { name: /Needs action/ })).toContainText('3');
});

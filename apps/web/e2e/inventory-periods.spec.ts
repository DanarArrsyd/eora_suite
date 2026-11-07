import AxeBuilder from '@axe-core/playwright';
import { choose, expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 8 (doc 32 §1.8, M3-D8, BR-PER-01/03, D-102): Inventory → Period Close against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-07T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const PLANT = { id: ID(2), code: 'P1', name: 'Plant 1', status: 'ACTIVE', version: 1 };
const PERIOD = {
  id: ID(1),
  code: '2026-10',
  version: 1,
  plantId: PLANT.id,
  plantCode: 'P1',
  status: 'CLOSING',
  closeRequestedById: ID(8),
  closeRequestedByName: 'Inventory controller',
  closeRequestedAt: '2026-11-07T01:00:00Z',
  closedByName: null,
  closedAt: null,
  reopenRequestedById: null,
  reopenRequestedByName: null,
  reopenRequestedAt: null,
  reopenReason: null,
  reopenedByName: null,
  reopenedAt: null,
  pendingRequests: 0,
};
const ME = (permissions: string[], userId = ID(9)) => ({ status: 200, json: { ...SESSION, user: { ...SESSION.user, id: userId }, permissions } });

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('period close: inventory control asks to close a month that has ended', async ({ page }) => {
  await page.clock.setFixedTime(new Date('2026-11-07T09:00:00+07:00'));
  await mockApi(page, '/auth/me', ME(['inventory.period_close.view', 'inventory.period_close.post', 'master.org.view']));
  await mockApi(page, '/query/inventory.periods?*', page1([]));
  await mockApi(page, '/query/core.plants?*', page1([PLANT]));
  const calls: unknown[] = [];
  await page.route('**/api/v1/inventory/periods/close-requests', (route) => {
    calls.push(route.request().postDataJSON());
    return route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: { id: ID(1), plantId: PLANT.id, period: '2026-10', status: 'CLOSING' } }),
    });
  });

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/inventory/period-close');
  await expect(page.getByRole('heading', { level: 1, name: 'Period Close' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  await axe(page);
  await page.getByRole('button', { name: 'New close request' }).click();
  const drawer = page.getByRole('dialog', { name: 'New close request' });
  await choose(page, drawer.getByRole('combobox', { name: 'Plant' }), 'P1 · Plant 1');
  // only months that have ended are offered
  await drawer.getByRole('combobox', { name: 'Month' }).click();
  await expect(page.getByRole('option', { name: 'November 2026' })).toHaveCount(0);
  await page.getByRole('option', { name: 'October 2026' }).click();
  await drawer.getByRole('button', { name: 'Ask to close' }).click();
  await page.getByRole('alertdialog', { name: 'Ask to close this month' }).getByRole('button', { name: 'Ask to close' }).click();
  await expect(page).toHaveURL(new RegExp(`period=${ID(1)}`));
  expect(calls[0]).toEqual({ plantId: PLANT.id, period: '2026-10' });
});

test('period close: another person closes the month, or rejects the close with a reason', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.period_close.approve']));
  await mockApi(page, '/query/inventory.periods?*', page1([PERIOD]));
  const rejected: unknown[] = [];
  await page.route(`**/api/v1/inventory/periods/${ID(1)}/close-return`, (route) => {
    rejected.push(route.request().postDataJSON());
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { id: ID(1), plantId: PLANT.id, period: '2026-10', status: 'OPEN' } }),
    });
  });
  await page.route(`**/api/v1/inventory/periods/${ID(1)}/close`, (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: { id: ID(1), plantId: PLANT.id, period: '2026-10', status: 'CLOSED' } }),
    }),
  );

  await page.goto(`/inventory/period-close?period=${ID(1)}`);
  await expect(page.getByRole('heading', { level: 1, name: 'October 2026 · P1' })).toBeVisible();
  await axe(page);
  await page.getByRole('button', { name: 'Reject close' }).click();
  const dialog = page.getByRole('dialog', { name: 'Reject the close of October 2026' });
  await dialog.getByRole('textbox', { name: 'Reason' }).fill('Counts not reconciled yet');
  await dialog.getByRole('button', { name: 'Reject close' }).click();
  await expect(page.getByText('Close of October 2026 rejected; the month is open again')).toBeVisible();
  expect(rejected[0]).toEqual({ reason: 'Counts not reconciled yet' });
  await page.getByRole('button', { name: 'Close month' }).click();
  await expect(page.getByText('October 2026 closed')).toBeVisible();
});

test('period close: the requester waits and may only withdraw its own request', async ({ page }) => {
  await mockApi(page, '/auth/me', ME(['inventory.period_close.post', 'inventory.period_close.approve'], PERIOD.closeRequestedById));
  await mockApi(page, '/query/inventory.periods?*', page1([PERIOD]));
  await page.goto(`/inventory/period-close?period=${ID(1)}`);
  await expect(page.getByText('You asked to close this month.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Close month' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Withdraw close request' })).toBeVisible();
});

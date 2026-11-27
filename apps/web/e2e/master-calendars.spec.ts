import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* Master Data → Calendars (doc 40, D-142): shift times per weekday with breaks, production day versions, exception days. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const PLANT = { id: ID(21), code: 'P1', name: 'Plant 1' };

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

const day = (isoWeekday: number, startsAt: string, endsAt: string, breakMinutes: number, workMinutes: number) => ({
  isoWeekday,
  startsAt,
  endsAt,
  breakMinutes,
  workMinutes,
});

const calendars = (canManage: boolean, exceptions: unknown[] = []) => ({
  plant: PLANT,
  today: '2026-11-27',
  canManage,
  business: [
    {
      id: ID(31),
      scope: 'TENANT',
      effectiveFrom: '2026-10-15',
      timezone: 'Asia/Jakarta',
      productionDayStartsAt: '06:00',
      status: 'IN_FORCE',
      removable: false,
    },
  ],
  exceptions,
  shiftCalendars: [
    {
      id: ID(41),
      scope: 'PLANT',
      code: 'STD',
      name: 'Standard shifts',
      effectiveFrom: '2026-11-27',
      status: 'IN_FORCE',
      removable: false,
      shifts: [
        {
          id: ID(51),
          code: 'S1',
          name: 'Shift 1',
          days: [1, 2, 3, 4].map((d) => day(d, '06:00', '14:00', 60, 420)).concat([day(5, '06:00', '14:30', 90, 420), day(6, '07:00', '12:15', 15, 300)]),
        },
        {
          id: ID(52),
          code: 'S2',
          name: 'Shift 2',
          days: [1, 2, 3, 4].map((d) => day(d, '14:00', '22:00', 60, 420)).concat([day(5, '14:30', '22:30', 60, 420), day(6, '13:00', '18:15', 15, 300)]),
        },
      ],
    },
  ],
});

async function setup(page: Page, permissions: string[], canManage: boolean) {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions } });
  await mockApi(page, '/query/core.plants?*', { status: 200, json: { data: [PLANT], meta: { total: 1, page: { number: 1, size: 100 } } } });
  await mockApi(page, `/core/plants/${PLANT.id}/calendars`, { status: 200, json: { data: calendars(canManage) } });
  await page.setViewportSize({ width: 1440, height: 900 });
}

test('calendars: shift week with breaks and work hours; a viewer reads only', async ({ page }) => {
  await setup(page, ['master.calendar.view', 'master.org.view'], false);
  await page.goto('/master/calendars');
  await expect(page.getByRole('heading', { level: 1, name: 'Calendars' })).toBeVisible();
  const week = page.getByRole('table');
  await expect(week).toContainText('06:00–14:30');
  await expect(week).toContainText('07:00–12:15');
  await expect(week).toContainText('14 h');
  await expect(week).toContainText('10 h');
  await expect(page.getByRole('button', { name: 'New shift version' })).toHaveCount(0);
  await axe(page);

  await page.getByRole('tab', { name: /Working days/ }).click();
  await expect(page.getByRole('table').first()).toContainText('06:00');
  await expect(page.getByRole('button', { name: 'Add exception day' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'New version' })).toHaveCount(0);
});

test('calendars: a planner adds an exception day', async ({ page }) => {
  await setup(page, ['master.calendar.view', 'master.calendar.create', 'master.org.view'], true);
  let posted: unknown = null;
  await mockApi(page, `/core/plants/${PLANT.id}/calendar-exceptions`, (route) => {
    posted = route.request().postDataJSON();
    return {
      status: 201,
      json: {
        data: calendars(true, [{ id: ID(61), date: '2026-12-05', kind: 'HOLIDAY', description: 'Company outing', scope: 'PLANT', editable: true }]),
      },
    };
  });
  await page.goto('/master/calendars?tab=business');
  await page.getByRole('button', { name: 'Add exception day' }).click();
  await expect(page.getByRole('heading', { name: 'Add exception day' })).toBeVisible();
  await page.getByRole('button', { name: /^Day: not set/ }).click();
  await page.getByRole('button', { name: 'Today', exact: true }).click();
  await page.getByLabel('Description').fill('Company outing');
  await page.getByRole('button', { name: 'Add day' }).click();
  await expect(page.getByRole('table').nth(1)).toContainText('Company outing');
  expect(posted).toMatchObject({ kind: 'HOLIDAY', description: 'Company outing' });
});

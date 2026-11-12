import AxeBuilder from '@axe-core/playwright';
import { expect, mockApi, SESSION, test, type Page } from './fixtures';

/* M3 step 9 (doc 32 §1.9, BR-INV-02): Inventory → Movement Ledger against a mocked API. */

const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const META = { total: 0, page: { number: 1, size: 20 }, asOf: '2026-11-12T02:00:00Z', timezone: 'Asia/Jakarta' };
const page1 = (rows: unknown[]) => ({ status: 200, json: { data: rows, meta: { ...META, total: rows.length } } });
const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;

const LEG = {
  id: ID(1),
  code: 'RCV-P1-2026-0001',
  version: 1,
  documentId: ID(2),
  docType: 'RM_RECEIPT',
  documentStatus: 'POSTED',
  legNo: 2,
  movementCode: 'RCPT_SUPPLIER',
  plantCode: 'P1',
  locationCode: 'RM-A01',
  locationType: 'RM_WAREHOUSE',
  itemId: ID(4),
  itemCode: 'SHEET-SPCC-12',
  itemName: 'SPCC sheet 1.2 mm',
  lotNo: 'L2611-0001',
  stockStatus: 'UNRESTRICTED',
  qty: '500',
  uomCode: 'KG',
  reversesMovementId: null,
  effectiveDate: '2026-11-06',
  postedAt: '2026-11-06T04:55:00Z',
  postedByName: 'Materials PIC',
};
const UNDONE = { ...LEG, id: ID(5), code: 'REV-P1-2026-0002', docType: 'REVERSAL', qty: '-500', reversesMovementId: LEG.id };

async function axe(page: Page) {
  await page.waitForTimeout(250);
  const r = await new AxeBuilder({ page }).withTags(TAGS).analyze();
  expect(r.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('movement ledger: the stock side of every posting, newest first, viewed and never edited', async ({ page }) => {
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['inventory.stock.view'] } });
  const listUrl = page.waitForRequest((r) => r.url().includes('/query/inventory.movements?'));
  await mockApi(page, '/query/inventory.movements?*', page1([UNDONE, LEG]));

  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/inventory/movements');
  await expect(page.getByRole('heading', { level: 1, name: 'Movement Ledger' })).toBeVisible();
  await expect(page.locator('#main').getByText('Mock data')).toHaveCount(0);
  const url = decodeURIComponent((await listUrl).url());
  expect(url).toContain('filter[stockSide]=true');
  expect(url).toContain('sort=-sequence');
  const table = page.getByRole('table', { name: 'Movement Ledger' });
  await expect(table.getByRole('row', { name: /RCV-P1-2026-0001/ })).toContainText('+500 KG');
  await expect(table.getByRole('row', { name: /REV-P1-2026-0002/ })).toContainText('Supplier receipt (reversal)');
  await expect(table.getByRole('row', { name: /REV-P1-2026-0002/ })).toContainText('−500 KG');
  // no create action; master-data filters are hidden from a stock-only role
  await expect(page.getByRole('button', { name: /^New / })).toHaveCount(0);
  await expect(page.getByRole('combobox', { name: 'Plant', exact: true })).toHaveCount(0);
  await axe(page);

  await page.getByRole('button', { name: 'View RCV-P1-2026-0001 leg 2 SHEET-SPCC-12' }).click();
  const drawer = page.getByRole('dialog', { name: 'RCV-P1-2026-0001 · leg 2' });
  await expect(drawer).toContainText('The ledger is never edited.');
  await expect(drawer).toContainText('Materials PIC');
  await expect(drawer.getByRole('textbox')).toHaveCount(0);
});

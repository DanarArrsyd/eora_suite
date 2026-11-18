import AxeBuilder from '@axe-core/playwright';
import type { Page, Route } from '@playwright/test';
import { expect, mockApi, SESSION, test } from './fixtures';

async function mockRecovery(page: Page, handler: (path: string, route: Route) => Promise<unknown>) {
  await page.route(/\/api\/v1\/planning\/daily-recovery/, async (route) => {
    const url = new URL(route.request().url());
    await handler(url.pathname.replace('/api/v1', '') + url.search, route);
  });
  await mockApi(page, '/query/core.customers**', { status: 200, json: { data: [{ id: ID(1), code: 'DPM', name: 'Customer DPM' }], meta: { total: 1 } } });
}

const ID = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const row = {
  id: `${ID(1)}|${ID(2)}`,
  planId: null as string | null,
  revision: 0,
  version: 0,
  customerId: ID(1),
  customerCode: 'DPM',
  customerName: 'Customer DPM',
  itemId: ID(2),
  itemCode: 'PART-A',
  itemName: 'Bracket Inner',
  partNo: 'DPM-001',
  requiredQty: '500',
  backlogQty: '0',
  shippedTodayQty: '0',
  fgQty: '100',
  beforeCheckQty: '380',
  acceptedQty: '0',
  allocated: { fg: '100', beforeCheck: '380', accepted: '0', wo: '0' },
  materialCapacityQty: '900',
  minimumQty: '500',
  batchQty: '22',
  bufferQty: '0',
  shift1Qty: '0',
  shift2Qty: '0',
  shift3Qty: '0',
  reason: null as string | null,
  netQty: '20',
  proposedQty: '506',
  planQty: '0',
  positionQty: '-20',
  workOrderId: null,
  workOrderCode: null,
  workOrderStatus: null,
  status: 'PLAN_REQUIRED',
  inputsChanged: false,
  lots: [
    { id: ID(3), lot_id: ID(4), lot_no: 'FG-DPM-181126-0001', stock_status: 'PENDING_QC', qty: '380', allocated: '0', location_code: 'QC-01', uom: 'PCS' },
  ],
  demands: [{ id: ID(5), due: '2026-11-19', outstanding: '500', protected_qty: '0' }],
  workOrders: [],
  production: [{ wo_no: 'WO-P1-001', work_order_id: ID(6), lot_no: 'FG-DPM-181126-0001', shift_code: 'S1', qty: '380', doc_no: 'PR-P1-001' }],
};
const meta = {
  total: 1,
  page: 1,
  perPage: 25,
  today: '2026-11-18',
  targetDate: '2026-11-19',
  asOf: '2026-11-18T04:00:00Z',
  fingerprint: 'a'.repeat(64),
  formulaCode: 'DAILY_RECOVERY_V1',
};

test('daily recovery persists batch and shift edits, exposes lot sources and creates the linked draft WO', async ({ page }) => {
  let current = { ...row };
  await mockRecovery(page, async (path, route) => {
    if (path.startsWith('/planning/daily-recovery?')) return route.fulfill({ json: { data: [current], meta } });
    if (path === '/planning/daily-recovery' && route.request().method() === 'PUT') {
      const body = route.request().postDataJSON();
      expect(route.request().headers()['if-match']).toBe('"0"');
      expect(body).toMatchObject({ minimumQty: 500, batchQty: 22, shift1Qty: 506, shift2Qty: 0, shift3Qty: 0, reason: 'One full material batch' });
      current = { ...current, planId: ID(7), revision: 1, version: 2, shift1Qty: '506', planQty: '506', reason: body.reason };
      return route.fulfill({ json: { data: current, fingerprint: meta.fingerprint } });
    }
    if (path === `/planning/daily-recovery/${ID(7)}/actions/create-work-order`) {
      expect(route.request().headers()['if-match']).toBe('"2"');
      return route.fulfill({ status: 201, json: { data: { workOrderId: ID(8), replayed: false } } });
    }
    if (path.startsWith('/query/core.customers'))
      return route.fulfill({ json: { data: [{ id: ID(1), code: 'DPM', name: 'Customer DPM' }], meta: { total: 1 } } });
  });
  await page.goto('/ppic/daily-recovery?targetDate=2026-11-19');
  await expect(page.getByRole('heading', { name: 'Daily Recovery Planner' })).toBeVisible();
  await page.getByRole('button', { name: 'Bracket Inner', exact: true }).click();
  await expect(page.getByText('FG-DPM-181126-0001').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Buat SPK Draft' })).toBeDisabled();
  await page.getByLabel('Plan Shift I (pcs)', { exact: true }).fill('506');
  await page.getByLabel('Alasan / penyesuaian Planner').fill('One full material batch');
  await page.getByRole('button', { name: 'Simpan rencana shift', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Buat SPK Draft' })).toBeEnabled();
  await page.getByRole('button', { name: 'Buat SPK Draft' }).click();
  await expect(page).toHaveURL(new RegExp(`/ppic/work-orders\\?order=${ID(8)}`));
});

test('Before Check covers delivery provisionally and exposes stale planning inputs', async ({ page }) => {
  await mockRecovery(page, async (path, route) => {
    if (path.startsWith('/planning/daily-recovery?'))
      return route.fulfill({
        json: {
          data: [{ ...row, planId: ID(7), version: 2, status: 'WAIT_QC', netQty: '0', proposedQty: '0', beforeCheckQty: '400', inputsChanged: true }],
          meta,
        },
      });
  });
  await page.goto('/ppic/daily-recovery?targetDate=2026-11-19');
  await expect(page.getByText('Kejar QC', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Bracket Inner', exact: true }).click();
  await expect(page.getByText('Posisi berubah sejak rencana disimpan')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Buat SPK Draft' })).toBeDisabled();
});

test('the planner sees a source-change refusal and keeps the editable plan open', async ({ page }) => {
  await mockRecovery(page, async (path, route) => {
    if (path.startsWith('/planning/daily-recovery?'))
      return route.fulfill({ json: { data: [{ ...row, planId: ID(7), version: 2, planQty: '506', shift1Qty: '506' }], meta } });
    if (path.includes('/actions/create-work-order'))
      return route.fulfill({
        status: 409,
        contentType: 'application/problem+json',
        json: { status: 409, code: 'RECOVERY_INPUTS_CHANGED', title: 'Planning inputs changed', detail: 'Review the new QC quantities and save again.' },
      });
  });
  await page.goto('/ppic/daily-recovery?targetDate=2026-11-19');
  await page.getByRole('button', { name: 'Bracket Inner', exact: true }).click();
  await page.getByRole('button', { name: 'Buat SPK Draft' }).click();
  await expect(page.getByText('Rencana belum diproses')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Simpan rencana shift', exact: true })).toBeVisible();
});

test('daily recovery is readable and accessible on a phone with view-only permissions', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockApi(page, '/auth/me', { status: 200, json: { ...SESSION, permissions: ['planning.recovery.view', 'planning.control_tower.view'] } });
  await mockRecovery(page, async (path, route) => {
    if (path === '/auth/me') return route.fulfill({ json: { ...SESSION, permissions: ['planning.recovery.view', 'planning.control_tower.view'] } });
    if (path.startsWith('/planning/daily-recovery?')) return route.fulfill({ json: { data: [row], meta } });
  });
  await page.goto('/ppic/daily-recovery?targetDate=2026-11-19');
  await expect(page.getByRole('heading', { name: 'Daily Recovery Planner' })).toBeVisible();
  await page.getByRole('button', { name: 'Bracket Inner', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Simpan rencana shift', exact: true })).toBeDisabled();
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  expect(results.violations.map((v) => v.id)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

import { describe, expect, it } from 'vitest';
import { NAV_MODULES } from './config';
import { mockNavigationSource } from './navigation.mock';
import { allRouteParams, breadcrumbFor, isItemVisible, isMenuItem, itemBadge, matchRoute, moduleBadge, visibleModules } from './selectors';
import type { NavModule } from './types';

const all = { allowedModules: '*' as const };

describe('navigation config', () => {
  it('has unique module ids and unique item ids per module', () => {
    const moduleIds = NAV_MODULES.map((m) => m.id);
    expect(new Set(moduleIds).size).toBe(moduleIds.length);
    for (const m of NAV_MODULES) {
      const ids = m.groups.flatMap((g) => g.items.map((i) => i.id));
      expect(new Set(ids).size, `duplicate item id in ${m.id}`).toBe(ids.length);
    }
  });

  it('contains no WIP label anywhere (D-002)', () => {
    const labels = NAV_MODULES.flatMap((m) => [m.label, ...m.groups.flatMap((g) => [g.label, ...g.items.map((i) => i.label)])]);
    expect(labels.filter((l) => /\bWIP\b/i.test(l))).toEqual([]);
  });

  it('uses Material Variance and Finished Goods terminology (D-024)', () => {
    const labels = NAV_MODULES.flatMap((m) => m.groups.flatMap((g) => g.items.map((i) => i.label)));
    expect(labels).toContain('Material Variance');
    expect(labels.some((l) => /Material Loss|Finish Good\b/.test(l))).toBe(false);
  });

  it('includes Sales Orders and Master Production Schedule as approved V1 items (D-030, D-031)', () => {
    const ppic = NAV_MODULES.find((m) => m.id === 'ppic')!;
    const items = ppic.groups.flatMap((g) => g.items);
    expect(items.find((i) => i.id === 'sales-orders')?.decision).toBeUndefined();
    expect(items.find((i) => i.id === 'mps')?.decision).toBeUndefined();
  });

  it('places basic Sales Orders, Delivery Schedules and MPS in R1 (D-035)', () => {
    const items = NAV_MODULES.flatMap((m) => m.groups.flatMap((g) => g.items));
    for (const id of ['sales-orders', 'delivery-schedules', 'mps']) expect(items.filter((i) => i.id === id).every((i) => i.release === 'R1')).toBe(true);
  });
});

describe('visibility', () => {
  it('hides items that depend on an OPEN decision unless explicitly shown', () => {
    const netting = NAV_MODULES.find((m) => m.id === 'demand')!
      .groups.flatMap((g) => g.items)
      .find((i) => i.id === 'netting')!;
    expect(isItemVisible(netting, all)).toBe(false);
    expect(isItemVisible(netting, { ...all, showPendingDecisions: true })).toBe(true);
  });

  it('filters modules by role (server-filtered list mocked)', () => {
    const mods = visibleModules(NAV_MODULES, { allowedModules: mockNavigationSource.allowedModules('quality-inspector') });
    expect(mods.map((m) => m.id)).toEqual(['dashboard', 'quality', 'traceability']);
  });

  it('drops V2 items by default', () => {
    const fake: NavModule = { ...NAV_MODULES[0]!, groups: [{ label: 'G', items: [{ ...NAV_MODULES[0]!.groups[0]!.items[0]!, release: 'V2' }] }] };
    expect(visibleModules([fake], all)).toEqual([]);
  });
});

describe('actionable badges (D-025)', () => {
  it('counts only items with an actionable source', () => {
    const dashboard = NAV_MODULES.find((m) => m.id === 'dashboard')!;
    const overview = dashboard.groups[0]!.items.find((i) => i.id === 'overview')!;
    expect(itemBadge(overview, { tasks: 5 })).toBe(0);
  });

  it('module badge sums distinct sources once', () => {
    const inventory = NAV_MODULES.find((m) => m.id === 'inventory')!;
    expect(moduleBadge(inventory, { approvals: 2, tasks: 9 })).toBe(2);
    const dashboard = NAV_MODULES.find((m) => m.id === 'dashboard')!;
    expect(moduleBadge(dashboard, { approvals: 1, tasks: 2 })).toBe(3);
  });
});

describe('routing', () => {
  it('matches module and item routes and builds the breadcrumb', () => {
    const match = matchRoute(NAV_MODULES, '/ppic/control-tower');
    expect(match?.item?.label).toBe('Control Tower');
    expect(breadcrumbFor(match).map((c) => c.label)).toEqual(['PPIC / Planning', 'Overview', 'Control Tower']);
    expect(match?.item?.topbarContext).toBe('period');
  });

  it('returns undefined for unknown routes', () => {
    expect(matchRoute(NAV_MODULES, '/ppic/unknown')).toBeUndefined();
    expect(matchRoute(NAV_MODULES, '/nope')).toBeUndefined();
  });

  it('generates one static param per item', () => {
    const count = NAV_MODULES.reduce((n, m) => n + m.groups.reduce((k, g) => k + g.items.length, 0), 0);
    expect(allRouteParams(NAV_MODULES)).toHaveLength(count);
  });
});

describe('item permissions', () => {
  const adjustments = NAV_MODULES.find((m) => m.id === 'inventory')!
    .groups.flatMap((g) => g.items)
    .find((i) => i.id === 'adjustments')!;

  it('shows an item to a holder of any of its permissions: requesters view, approvers decide (D-100)', () => {
    expect(isItemVisible(adjustments, { ...all, permissions: ['inventory.adjustment.view'] })).toBe(true);
    expect(isItemVisible(adjustments, { ...all, permissions: ['inventory.adjustment.approve'] })).toBe(true);
    expect(isItemVisible(adjustments, { ...all, permissions: ['inventory.stock.view'] })).toBe(false);
  });
});

describe('menus of a signed-in user (D-126)', () => {
  const live = NAV_MODULES.flatMap((m) => m.groups.flatMap((g) => g.items)).filter((i) => i.live);
  const item = (id: string) => live.find((i) => i.id === id)!;

  it('never lists a page that is not built, or a mock screen', () => {
    const all = NAV_MODULES.flatMap((m) => m.groups.flatMap((g) => g.items));
    const everything = Array.from(new Set(all.flatMap((i) => (typeof i.permission === 'string' ? [i.permission] : (i.permission ?? [])).concat(i.menu ?? []))));
    expect(all.filter((i) => isMenuItem(i, [...everything, 'core.audit.view'])).every((i) => i.live)).toBe(true);
    expect(
      isMenuItem(
        all.find((i) => i.id === 'control-tower')!,
        everything,
      ),
    ).toBe(false);
  });

  it('needs the view permission and one of the work permissions; an auditor reads every page', () => {
    expect(isMenuItem(item('items'), ['master.part.view'])).toBe(false);
    expect(isMenuItem(item('items'), ['master.part.view', 'master.part.create'])).toBe(true);
    expect(isMenuItem(item('items'), ['master.part.create'])).toBe(false);
    expect(isMenuItem(item('items'), ['master.part.view', 'core.audit.view'])).toBe(true);
    expect(isMenuItem(item('backward-trace'), ['traceability.lot_trace.view'])).toBe(true);
  });

  it('gives demand entry to delivery and lets the planner read it, with the Pending QC queue (D-127)', () => {
    const dlv = ['demand.sales_order.view', 'demand.sales_order.create', 'demand.sales_order.post', 'demand.forecast.view', 'demand.forecast.create'];
    const ppc = ['demand.sales_order.view', 'demand.forecast.view', 'quality.inspection.view', 'planning.mps.create', 'planning.wo.create'];
    const fgw = ['demand.sales_order.view', 'quality.inspection.view', 'warehouse.fg_receipt.create'];
    for (const id of ['sales-orders', 'forecast']) {
      expect(isMenuItem(item(id), dlv)).toBe(true);
      expect(isMenuItem(item(id), ppc)).toBe(true);
    }
    expect(isMenuItem(item('pending-qc'), ppc)).toBe(true);
    expect(isMenuItem(item('pending-qc'), dlv)).toBe(false);
    // reading alone is not a menu: the FG warehouse may see orders and QC lots without them in its sidebar
    expect(isMenuItem(item('sales-orders'), fgw)).toBe(false);
    expect(isMenuItem(item('pending-qc'), fgw)).toBe(false);
  });

  it('marks every live page with a permission', () => {
    expect(live.filter((i) => !i.permission).map((i) => i.id)).toEqual([]);
  });
});

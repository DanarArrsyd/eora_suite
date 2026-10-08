import { describe, expect, it } from 'vitest';
import { NAV_MODULES } from './config';
import { mockNavigationSource } from './navigation.mock';
import { allRouteParams, breadcrumbFor, isItemVisible, itemBadge, matchRoute, moduleBadge, visibleModules } from './selectors';
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

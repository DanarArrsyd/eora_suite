import type { BadgeCounts, NavItem, NavModule, Release } from './types';

export type VisibilityOptions = {
  /** Module ids allowed for the user ('*' = all). Server-filtered in production. */
  allowedModules: readonly string[] | '*';
  /** Releases shown in this build. V2 hidden unless feature-flagged. */
  releases?: readonly Release[];
  /** Production builds hide items that depend on OPEN decisions. */
  showPendingDecisions?: boolean;
  /** The signed-in user's permission hints; when given, items naming a `permission` the user lacks are hidden (the server still decides). */
  permissions?: readonly string[];
};

const DEFAULT_RELEASES: readonly Release[] = ['R1', 'R2'];

export function isItemVisible(item: NavItem, opts: VisibilityOptions): boolean {
  const releases = opts.releases ?? DEFAULT_RELEASES;
  if (!releases.includes(item.release)) return false;
  if (item.decision && !opts.showPendingDecisions) return false;
  if (item.permission && opts.permissions && !opts.permissions.includes(item.permission)) return false;
  return true;
}

/** Modules (with filtered groups/items) visible to the user. Empty groups and modules are dropped. */
export function visibleModules(modules: readonly NavModule[], opts: VisibilityOptions): NavModule[] {
  return modules
    .filter((m) => opts.allowedModules === '*' || opts.allowedModules.includes(m.id))
    .map((m) => ({ ...m, groups: m.groups.map((g) => ({ ...g, items: g.items.filter((i) => isItemVisible(i, opts)) })).filter((g) => g.items.length > 0) }))
    .filter((m) => m.groups.length > 0);
}

export function itemHref(moduleId: string, itemId: string): string {
  return `/${moduleId}/${itemId}`;
}

export function moduleHref(moduleId: string): string {
  return `/${moduleId}`;
}

/** Count for one item: actionable sources only (D-025). */
export function itemBadge(item: NavItem, counts: BadgeCounts): number {
  return item.badge ? (counts[item.badge] ?? 0) : 0;
}

/** Module badge = sum of DISTINCT actionable sources present in the module (navigation doc §10). */
export function moduleBadge(module: NavModule, counts: BadgeCounts): number {
  const sources = new Set(module.groups.flatMap((g) => g.items.map((i) => i.badge)).filter((b): b is NonNullable<typeof b> => Boolean(b)));
  let total = 0;
  for (const s of sources) total += counts[s] ?? 0;
  return total;
}

export type RouteMatch = { module: NavModule; item?: NavItem; group?: string };

/** Resolve a pathname such as `/ppic/control-tower` to module/item from the config. */
export function matchRoute(modules: readonly NavModule[], pathname: string): RouteMatch | undefined {
  const [moduleId, itemId] = pathname.split('/').filter(Boolean);
  const mod = modules.find((m) => m.id === moduleId);
  if (!mod) return undefined;
  if (!itemId) return { module: mod };
  for (const g of mod.groups) {
    const item = g.items.find((i) => i.id === itemId);
    if (item) return { module: mod, item, group: g.label };
  }
  return undefined;
}

/** Breadcrumb trail: module › group › item (group omitted for module landing). */
export function breadcrumbFor(match: RouteMatch | undefined): { label: string; href?: string }[] {
  if (!match) return [];
  const trail: { label: string; href?: string }[] = [{ label: match.module.label, href: moduleHref(match.module.id) }];
  if (match.item) {
    if (match.group) trail.push({ label: match.group });
    trail.push({ label: match.item.label });
  }
  return trail;
}

/** All static route params for build-time generation. */
export function allRouteParams(modules: readonly NavModule[]): { module: string; item: string }[] {
  return modules.flatMap((m) => m.groups.flatMap((g) => g.items.map((i) => ({ module: m.id, item: i.id }))));
}

/** Pages outside module navigation (internal/system) still get a breadcrumb. */
export const STATIC_TRAILS: Readonly<Record<string, { label: string; href?: string }[]>> = {
  '/system/ui-kit': [{ label: 'System' }, { label: 'UI kit' }],
  '/system/patterns': [{ label: 'System' }, { label: 'Enterprise patterns' }],
  '/system/examples/raw-material-lots': [{ label: 'System' }, { label: 'Examples' }, { label: 'Raw material lots' }],
  '/system/notifications': [{ label: 'Notifications' }],
};

export function trailForPath(match: RouteMatch | undefined, pathname: string): { label: string; href?: string }[] {
  return match ? breadcrumbFor(match) : (STATIC_TRAILS[pathname] ?? []);
}

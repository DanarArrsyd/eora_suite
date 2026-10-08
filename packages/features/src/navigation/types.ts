import type { LucideIcon } from 'lucide-react';

/** Release placement (doc 21): R1 vertical slice, R2 V1 additions, V2 hidden unless feature-flagged. */
export type Release = 'R1' | 'R2' | 'V2';

/** Actionable badge sources only (D-025). Never total record counts. */
export type BadgeSource = 'approvals' | 'qc' | 'variance' | 'tasks';

export type NavItem = {
  id: string;
  label: string;
  icon: LucideIcon;
  release: Release;
  /** Permission key checked server-side; UI filtering is convenience only. */
  permission?: string;
  badge?: BadgeSource;
  /** Depends on an OPEN decision in docs/13 — hidden in production builds. */
  decision?: `O-${string}`;
  /** Delivery phase that builds the page (doc 22). */
  phase?: 3 | 4 | 5 | 'later';
  /** Page context shown in the topbar (navigation doc §5). */
  topbarContext?: TopbarContext;
};

export type TopbarContext = 'period' | 'warehouse';

export type NavGroup = { label: string; items: NavItem[] };

export type NavModule = {
  id: string;
  label: string;
  icon: LucideIcon;
  permission: string;
  pinBottom?: boolean;
  groups: NavGroup[];
};

export type RoleKey = 'ppic-planner' | 'quality-inspector' | 'materials-pic' | 'company-super-admin';

export type BadgeCounts = Partial<Record<BadgeSource | 'bell', number>>;

/** Contract for the server navigation/badge endpoint (GET /api/v1/me/navigation). */
export interface NavigationSource {
  /** Module ids the user may open (server-filtered by permission + tenant). */
  allowedModules(role: RoleKey): readonly string[] | '*';
  /** Actionable counts, scoped exactly like the destination lists. */
  badgeCounts(role: RoleKey): BadgeCounts;
}

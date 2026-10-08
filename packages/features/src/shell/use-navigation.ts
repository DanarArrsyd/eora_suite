'use client';

import * as React from 'react';
import { usePathname } from 'next/navigation';
import { NAV_MODULES, matchRoute, moduleBadge, visibleModules, type BadgeCounts, type NavModule, type RouteMatch } from '../navigation';
import { useShellSession } from './session';

export type ShellNavigation = {
  modules: NavModule[];
  counts: BadgeCounts;
  match: RouteMatch | undefined;
  /** Module whose menu is shown in the secondary sidebar (route module, or a module previewed from the primary sidebar). */
  menuModule: NavModule | undefined;
  /** Module highlighted in the primary sidebar: route module or previewed module; none on non-module pages. */
  activeModuleId: string | undefined;
  setMenuModuleId: (id: string | undefined) => void;
  badgeFor: (m: NavModule) => number;
  pathname: string;
};

/** Navigation state derived from the single config + session role + current route. */
export function useShellNavigation(): ShellNavigation {
  const pathname = usePathname() ?? '/';
  const { role, source } = useShellSession();
  const modules = React.useMemo(
    () => visibleModules(NAV_MODULES, { allowedModules: source.allowedModules(role), showPendingDecisions: false }),
    [role, source],
  );
  const counts = React.useMemo(() => source.badgeCounts(role), [role, source]);
  const match = React.useMemo(() => matchRoute(modules, pathname), [modules, pathname]);
  // A module previewed from the primary sidebar applies only to the route it was chosen on.
  const [preview, setPreview] = React.useState<{ pathname: string; moduleId: string } | undefined>(undefined);
  const menuModuleId = preview?.pathname === pathname ? preview.moduleId : undefined;
  const setMenuModuleId = React.useCallback((id: string | undefined) => setPreview(id ? { pathname, moduleId: id } : undefined), [pathname]);

  const menuModule = modules.find((m) => m.id === (menuModuleId ?? match?.module.id)) ?? match?.module ?? modules[0];
  const badgeFor = React.useCallback((m: NavModule) => moduleBadge(m, counts), [counts]);
  const activeModuleId = menuModuleId ?? match?.module.id;
  return { modules, counts, match, menuModule, activeModuleId, setMenuModuleId, badgeFor, pathname };
}

'use client';

import * as React from 'react';
import { usePathname } from 'next/navigation';
import { NAV_MODULES, isMenuItem, matchRoute, moduleBadge, visibleModules, type BadgeCounts, type NavModule, type RouteMatch } from '../navigation';
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
  const { role, source, identity } = useShellSession();
  const permissions = identity?.permissions;
  const modules = React.useMemo(() => {
    // a signed-in user (D-126): only pages on the live API, and of those only the ones whose permission the user holds;
    // pages not built yet and mock screens stay out of the menu (the server still decides every call)
    if (permissions) {
      return NAV_MODULES.map((m) => ({
        ...m,
        groups: m.groups.map((g) => ({ ...g, items: g.items.filter((i) => isMenuItem(i, permissions)) })).filter((g) => g.items.length > 0),
      })).filter((m) => m.groups.length > 0);
    }
    // the prototype without a session (design review): the prototype role's modules
    return visibleModules(NAV_MODULES, { allowedModules: source.allowedModules(role), showPendingDecisions: false });
  }, [role, source, permissions]);
  const counts = React.useMemo(() => source.badgeCounts(role), [role, source]);
  // the route's place in the whole navigation (breadcrumb, title), also for a page that is not one of the user's menus
  const match = React.useMemo(() => matchRoute(NAV_MODULES, pathname), [pathname]);
  // A module previewed from the primary sidebar applies only to the route it was chosen on.
  const [preview, setPreview] = React.useState<{ pathname: string; moduleId: string } | undefined>(undefined);
  const menuModuleId = preview?.pathname === pathname ? preview.moduleId : undefined;
  const setMenuModuleId = React.useCallback((id: string | undefined) => setPreview(id ? { pathname, moduleId: id } : undefined), [pathname]);

  const menuModule = modules.find((m) => m.id === (menuModuleId ?? match?.module.id)) ?? match?.module ?? modules[0];
  const badgeFor = React.useCallback((m: NavModule) => moduleBadge(m, counts), [counts]);
  const activeModuleId = menuModuleId ?? match?.module.id;
  return { modules, counts, match, menuModule, activeModuleId, setMenuModuleId, badgeFor, pathname };
}

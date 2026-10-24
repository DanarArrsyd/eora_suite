'use client';

import * as React from 'react';
import { usePathname } from 'next/navigation';
import { NAV_MODULES, isItemVisible, matchRoute, moduleBadge, visibleModules, type BadgeCounts, type NavModule, type RouteMatch } from '../navigation';
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

/** Modules running on the live API, whose menus follow the user's permission hints item by item. */
const LIVE_MODULES = new Set(['master', 'admin']);

/** Navigation state derived from the single config + session role + current route. */
export function useShellNavigation(): ShellNavigation {
  const pathname = usePathname() ?? '/';
  const { role, source, identity } = useShellSession();
  const permissions = identity?.permissions;
  const modules = React.useMemo(() => {
    let allowed = source.allowedModules(role);
    // Administration and Master Data run on the live API: shown from the user's permission hints, not from the
    // prototype role. Each page checks its own permission; the server enforces every call.
    if (permissions) {
      const others = allowed === '*' ? NAV_MODULES.map((m) => m.id) : [...allowed];
      const admin = permissions.includes('core.users_roles.view') || permissions.includes('master.org.view');
      // Master Data (D-076): organisation, part/partner and engineering master, conversion data (D-080); each item carries
      // its own view permission; Import (D-081) shows for whoever may upload
      const master = ['master.org.view', 'master.part.view', 'master.routing.view', 'master.conversion.view', 'exchange.import.create'].some((p) =>
        permissions.includes(p),
      );
      allowed = [...others.filter((id) => id !== 'admin' && id !== 'master'), ...(master ? ['master'] : []), ...(admin ? ['admin'] : [])];
    }
    const visible = visibleModules(NAV_MODULES, { allowedModules: allowed, showPendingDecisions: false });
    // live modules also hide the items whose own view permission the user lacks; prototype modules keep their menus
    return visible
      .map((m) =>
        permissions && LIVE_MODULES.has(m.id)
          ? {
              ...m,
              groups: m.groups
                .map((g) => ({ ...g, items: g.items.filter((i) => isItemVisible(i, { allowedModules: '*', permissions })) }))
                .filter((g) => g.items.length > 0),
            }
          : m,
      )
      .filter((m) => m.groups.length > 0);
  }, [role, source, permissions]);
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

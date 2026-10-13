'use client';

import * as React from 'react';
import { TooltipProvider } from '@eora/ui';
import { mockNavigationSource, type NavigationSource, type RoleKey } from '../navigation';

/**
 * Shell session. `identity` is the signed-in user from Laravel (`GET /auth/me`, ADR-0006) — tenant, plants, permission
 * hints and the session actions. Without it (component tests, review pages) the prototype values are used. The role
 * switch still drives the navigation of modules that run on mock data and is labelled as a prototype control.
 */
export type ShellIdentity = {
  user: { name: string; email: string };
  tenant: { id: string; code: string; name: string };
  tenants: { id: string; code: string; name: string }[];
  plants: { id: string; code: string; name: string }[];
  activePlantId: string | null;
  permissions: readonly string[];
  selectTenant: (tenantId: string) => Promise<unknown>;
  selectPlant: (plantId: string) => Promise<unknown>;
  signOut: () => Promise<unknown>;
};

type ShellSession = {
  role: RoleKey;
  setRole: (role: RoleKey) => void;
  source: NavigationSource;
  user: { name: string; initials: string; email?: string };
  workspace: { company: string; plant: string };
  /** Present when signed in against the API. */
  identity?: ShellIdentity;
};

export function initialsOf(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return ((parts[0]?.[0] ?? '?') + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '')).toUpperCase();
}

const ShellSessionContext = React.createContext<ShellSession | null>(null);
const ROLE_KEY = 'eora.prototype.role';

const DEFAULT_ROLE: RoleKey = 'ppic-planner';
const roleListeners = new Set<() => void>();

function readRole(): RoleKey {
  try {
    return (window.localStorage.getItem(ROLE_KEY) as RoleKey | null) ?? DEFAULT_ROLE;
  } catch {
    return DEFAULT_ROLE; // storage unavailable
  }
}
function subscribeRole(listener: () => void) {
  roleListeners.add(listener);
  window.addEventListener('storage', listener);
  return () => {
    roleListeners.delete(listener);
    window.removeEventListener('storage', listener);
  };
}

export function ShellSessionProvider({
  children,
  source = mockNavigationSource,
  identity,
}: {
  children: React.ReactNode;
  source?: NavigationSource;
  identity?: ShellIdentity;
}) {
  const role = React.useSyncExternalStore(subscribeRole, readRole, () => DEFAULT_ROLE);

  const setRole = React.useCallback((next: RoleKey) => {
    try {
      window.localStorage.setItem(ROLE_KEY, next);
    } catch {
      /* storage unavailable: change applies to this tab only */
    }
    roleListeners.forEach((l) => l());
  }, []);

  const value = React.useMemo<ShellSession>(() => {
    if (!identity) {
      return {
        role,
        setRole,
        source,
        user: { name: 'R. Pratama', initials: 'RP' },
        workspace: { company: 'PT Sample Manufaktur', plant: 'Plant A · Cikarang' },
      };
    }
    const plant = identity.plants.find((p) => p.id === identity.activePlantId);
    return {
      role,
      setRole,
      source,
      identity,
      user: { name: identity.user.name, initials: initialsOf(identity.user.name), email: identity.user.email },
      workspace: { company: identity.tenant.name, plant: plant ? `${plant.code} · ${plant.name}` : 'No plant selected' },
    };
  }, [role, setRole, source, identity]);
  // TooltipProvider lives here (not in AppShell): Radix providers create a new context value on every render, and any
  // context change above the page's Suspense boundary during hydration makes React client-render the streamed segment.
  return (
    <ShellSessionContext.Provider value={value}>
      <TooltipProvider delayDuration={300}>{children}</TooltipProvider>
    </ShellSessionContext.Provider>
  );
}

export function useShellSession(): ShellSession {
  const ctx = React.useContext(ShellSessionContext);
  if (!ctx) throw new Error('useShellSession must be used inside <ShellSessionProvider>');
  return ctx;
}

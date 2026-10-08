'use client';

import * as React from 'react';
import { mockNavigationSource, type NavigationSource, type RoleKey } from '../navigation';

/**
 * Prototype session (Phase 1). Real identity, tenant and permissions come from Laravel (ADR-0006);
 * the role switch exists only to review RBAC-aware navigation and is labelled as such in the UI.
 */
type ShellSession = {
  role: RoleKey;
  setRole: (role: RoleKey) => void;
  source: NavigationSource;
  user: { name: string; initials: string };
  workspace: { company: string; plant: string };
};

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

export function ShellSessionProvider({ children, source = mockNavigationSource }: { children: React.ReactNode; source?: NavigationSource }) {
  const role = React.useSyncExternalStore(subscribeRole, readRole, () => DEFAULT_ROLE);

  const setRole = React.useCallback((next: RoleKey) => {
    try {
      window.localStorage.setItem(ROLE_KEY, next);
    } catch {
      /* storage unavailable: change applies to this tab only */
    }
    roleListeners.forEach((l) => l());
  }, []);

  const value = React.useMemo<ShellSession>(
    () => ({
      role,
      setRole,
      source,
      user: { name: 'R. Pratama', initials: 'RP' },
      workspace: { company: 'PT Sample Manufaktur', plant: 'Plant A · Cikarang' },
    }),
    [role, setRole, source],
  );
  return <ShellSessionContext.Provider value={value}>{children}</ShellSessionContext.Provider>;
}

export function useShellSession(): ShellSession {
  const ctx = React.useContext(ShellSessionContext);
  if (!ctx) throw new Error('useShellSession must be used inside <ShellSessionProvider>');
  return ctx;
}

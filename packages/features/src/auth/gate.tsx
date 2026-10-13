'use client';

import * as React from 'react';
import { useRouter } from 'next/navigation';
import { Button, ErrorState, LoadingState } from '@eora/ui';
import { ShellSessionProvider, type ShellIdentity } from '../shell/session';
import { failureMessage } from './api';
import { useMe, useSessionActions } from './session';

function currentPath(): string {
  return typeof window === 'undefined' ? '/' : window.location.pathname + window.location.search;
}

/**
 * Workspace guard (ADR-0006, doc 25 §4): the workspace renders only for a signed-in user with an active tenant. No
 * session → `/login?next=…`; no tenant → `/select-tenant?next=…`. The server enforces every permission and scope; this
 * gate only decides which screen to show.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const me = useMe();
  const actions = useSessionActions();
  const target = me.data === null ? 'login' : me.data && !me.data.activeTenantId ? 'select-tenant' : null;

  React.useEffect(() => {
    if (target) router.replace(`/${target}?next=${encodeURIComponent(currentPath())}`);
  }, [target, router]);

  const { selectTenant, selectPlant, logout } = actions;
  const identity = React.useMemo<ShellIdentity | null>(() => {
    const data = me.data;
    const tenant = data?.tenants.find((t) => t.id === data.activeTenantId);
    if (!data || !tenant) return null;
    return {
      user: { name: data.user.displayName, email: data.user.email },
      tenant,
      tenants: data.tenants,
      plants: data.plants,
      activePlantId: data.activePlantId,
      permissions: data.permissions,
      selectTenant: (id) => selectTenant.mutateAsync(id),
      selectPlant: (id) => selectPlant.mutateAsync(id),
      signOut: async () => {
        await logout.mutateAsync().catch(() => undefined); // signed out locally either way
        router.replace('/login');
      },
    };
  }, [me.data, selectTenant, selectPlant, logout, router]);

  if (me.isError) {
    return (
      <main className="grid min-h-dvh place-items-center bg-workspace">
        <ErrorState
          title="Your session could not be checked"
          description={failureMessage(me.error)}
          action={
            <Button variant="primary" onClick={() => void me.refetch()}>
              Try again
            </Button>
          }
        />
      </main>
    );
  }
  if (!identity) {
    return (
      <main className="grid min-h-dvh place-items-center bg-workspace">
        <LoadingState title={target ? 'Redirecting to sign-in…' : 'Checking your session…'} />
      </main>
    );
  }
  return <ShellSessionProvider identity={identity}>{children}</ShellSessionProvider>;
}

'use client';

import * as React from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { Building2, ChevronRight } from 'lucide-react';
import { failureMessage, safeNext, useMe, useSessionActions } from '@eora/features/auth';
import { Button, EmptyState, InlineAlert, LoadingState } from '@eora/ui';
import { AuthCard } from './auth-card';

/** Tenant picker (doc 25 §2.6, DD-07): only workspaces where the user holds an ACTIVE membership are listed. */
export function SelectTenant() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get('next'));
  const me = useMe();
  const { selectTenant, logout } = useSessionActions();
  const [error, setError] = React.useState<string | null>(null);
  const signedOut = me.data === null;

  React.useEffect(() => {
    if (signedOut) router.replace(`/login?next=${encodeURIComponent(next)}`);
  }, [signedOut, next, router]);

  if (me.isPending || !me.data) return <LoadingState title="Checking your session…" />;

  const choose = (id: string) => {
    setError(null);
    selectTenant.mutate(id, {
      onSuccess: () => router.replace(next),
      onError: (e) => setError(failureMessage(e, 'The workspace could not be opened.')),
    });
  };
  const signOut = () => logout.mutate(undefined, { onSettled: () => router.replace('/login') });

  return (
    <AuthCard
      title="Choose a workspace"
      description={`Signed in as ${me.data.user.email}. Each workspace is a separate company account.`}
      footer={
        <Button variant="link" onClick={signOut}>
          Sign out
        </Button>
      }
    >
      {me.data.tenants.length === 0 ? (
        <EmptyState
          className="py-4"
          title="No active workspace"
          description="Your account has no active membership. Ask your administrator to invite you or to reactivate your access."
        />
      ) : (
        <div className="flex flex-col gap-3">
          {error ? <InlineAlert tone="danger" title={error} /> : null}
          <ul className="m-0 flex list-none flex-col gap-2 p-0" aria-label="Workspaces">
            {me.data.tenants.map((t) => (
              <li key={t.id}>
                <button
                  type="button"
                  onClick={() => choose(t.id)}
                  disabled={selectTenant.isPending}
                  className="flex min-h-14 w-full items-center gap-3 rounded-md border border-line-control bg-surface px-4 py-3 text-left hover:border-accent hover:bg-selected focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-wait"
                >
                  <Building2 aria-hidden="true" className="size-5 shrink-0 text-ink-2" />
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate text-sm font-semibold text-ink">{t.name}</span>
                    <span className="truncate text-xs text-ink-2">{t.code}</span>
                  </span>
                  <ChevronRight aria-hidden="true" className="size-4 shrink-0 text-ink-2" />
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </AuthCard>
  );
}

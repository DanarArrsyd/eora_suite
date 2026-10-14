'use client';

import * as React from 'react';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BellRing, ClipboardCheck, Lock, Scale, Stamp, SquareCheckBig } from 'lucide-react';
import { Badge, Button, EmptyState, ErrorState, LoadingState, MockDataLabel, cn } from '@eora/ui';
import { useShellSession } from '../../shell/session';
import { liveNotificationSource } from './live';
import { mockNotificationSource } from './notifications.mock';
import type { InboxNotification, NotificationCategory, NotificationSource } from './types';

const ICON: Record<NotificationCategory, typeof Stamp> = { approval: Stamp, qc: ClipboardCheck, variance: Scale, task: SquareCheckBig, system: BellRing };
type Filter = 'all' | 'action' | 'unread';

const fmt = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' });

/**
 * The inbox query shared by the bell and the Notifications page: the live server inbox when signed in (refreshed every
 * minute and on focus), the labelled mock inbox in review pages without a session.
 */
export function useInbox(override?: NotificationSource) {
  const { role, identity } = useShellSession();
  const qc = useQueryClient();
  const source = override ?? (identity ? liveNotificationSource : mockNotificationSource);
  const queryKey = ['notifications', source.isMock ? `mock:${role}` : `live:${identity?.tenant.id ?? ''}`];
  const q = useQuery({
    queryKey,
    queryFn: ({ signal }) => source.list(role, signal),
    refetchInterval: source.isMock ? false : 60_000,
  });
  const mark = useMutation({
    mutationFn: (ids: string[] | 'all') => (ids === 'all' ? source.markAllRead(role) : source.markRead(role, ids)),
    onSuccess: () => qc.invalidateQueries({ queryKey }),
  });
  return { q, mark, source };
}

/**
 * EoraNotificationCenter — persisted inbox (doc 07). Filters: All / Needs action / Unread. Deep links are shown only when the
 * current role can open the target module; otherwise the item says which access is missing (server re-checks on open).
 */
export function NotificationCenter({
  variant,
  source: override,
  onNavigate,
}: {
  variant: 'panel' | 'page';
  source?: NotificationSource;
  onNavigate?: () => void;
}) {
  const { role, source: nav } = useShellSession();
  const { q, mark, source } = useInbox(override);
  const [filter, setFilter] = React.useState<Filter>(variant === 'panel' ? 'action' : 'all');
  const allowed = nav.allowedModules(role);
  // live links: the server re-checks access when the page opens; mock links follow the prototype role
  const canOpen = (n: InboxNotification) => !!n.href && (!source.isMock || allowed === '*' || (!!n.module && allowed.includes(n.module)));

  const items = q.data?.items ?? [];
  // Needs action / Unread are totals of the whole inbox; the list shows the latest notifications
  const counts = { all: items.length, action: q.data?.actionRequired ?? 0, unread: q.data?.unread ?? 0 };
  const shown = items.filter((n) => (filter === 'action' ? n.actionable : filter === 'unread' ? !n.read : true)).slice(0, variant === 'panel' ? 5 : undefined);

  const labels: Record<Filter, string> = { all: 'All', action: 'Needs action', unread: 'Unread' };

  return (
    <div className="flex min-h-0 flex-col">
      <div
        className={cn('flex flex-wrap items-center justify-between gap-2 border-b border-line', variant === 'panel' ? 'px-4 py-3' : 'px-5 py-3 max-md:px-4')}
      >
        <div role="group" aria-label="Show notifications" className="flex gap-1">
          {(Object.keys(labels) as Filter[]).map((f) => (
            <button
              key={f}
              type="button"
              aria-pressed={filter === f}
              onClick={() => setFilter(f)}
              className={cn(
                'inline-flex h-8 items-center gap-1.5 rounded-md px-2.5 text-[13px] font-medium text-ink-2s hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring max-md:h-11',
                filter === f && 'bg-accent-subtle font-semibold text-accent hover:bg-accent-subtle',
              )}
            >
              {labels[f]}
              <span className="tabular-nums">{counts[f]}</span>
            </button>
          ))}
        </div>
        <div className="flex items-center gap-2">
          {source.isMock ? <MockDataLabel /> : null}
          {q.isSuccess ? (
            <Button size="sm" variant="ghost" className="text-link" disabled={!counts.unread || mark.isPending} onClick={() => mark.mutate('all')}>
              Mark all as read
            </Button>
          ) : null}
        </div>
      </div>
      {q.isLoading ? <LoadingState title="Loading notifications" /> : null}
      {q.isError ? (
        <ErrorState
          title="Notifications could not be loaded"
          description="Your inbox is kept on the server; nothing was lost."
          action={
            <Button size="sm" variant="primary" onClick={() => q.refetch()}>
              Try again
            </Button>
          }
        />
      ) : null}
      {q.isSuccess && shown.length === 0 ? (
        <EmptyState
          title={filter === 'action' ? 'Nothing needs your action' : filter === 'unread' ? 'No unread notifications' : 'No notifications yet'}
          description={
            source.isMock
              ? 'New approvals, QC results and variance cases appear here.'
              : 'Security notices and, as modules go live, approvals and QC results appear here.'
          }
        />
      ) : null}
      {shown.length ? (
        <ul aria-label="Notifications" className={cn('m-0 list-none p-0', variant === 'panel' && 'max-h-[60vh] overflow-y-auto')}>
          {shown.map((n) => {
            const Icon = ICON[n.category];
            const open = canOpen(n);
            return (
              <li
                key={n.id}
                className={cn(
                  'flex gap-3 border-b border-line px-4 py-3 last:border-b-0',
                  !n.read && 'bg-selected/40',
                  variant === 'page' && 'px-5 max-md:px-4',
                )}
              >
                <span aria-hidden="true" className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-stat-neutral-bg text-ink-2">
                  <Icon className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="m-0 flex flex-wrap items-center gap-2 text-[13.5px] font-semibold">
                    {!n.read ? <span aria-hidden="true" className="size-2 rounded-full bg-accent" /> : null}
                    <span>{n.title}</span>
                    {!n.read ? <span className="sr-only">(unread)</span> : null}
                    {n.actionable ? <Badge tone="warning">Needs action</Badge> : null}
                    {n.severity === 'CRITICAL' ? (
                      <Badge tone="danger">Critical</Badge>
                    ) : n.severity === 'WARNING' ? (
                      <Badge tone="warning">Warning</Badge>
                    ) : null}
                  </p>
                  <p className="m-0 mt-0.5 text-[13px] text-ink-2">{n.body}</p>
                  <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-2">
                    <time dateTime={n.createdAt}>{fmt.format(new Date(n.createdAt))} WIB</time>
                    {n.href && open ? (
                      <Link
                        href={n.href}
                        onClick={() => {
                          if (!n.read) mark.mutate([n.id]);
                          onNavigate?.();
                        }}
                        className="font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        Open<span className="sr-only">: {n.title}</span>
                      </Link>
                    ) : n.href ? (
                      <span className="inline-flex items-center gap-1">
                        <Lock aria-hidden="true" className="size-3" /> No access to {n.module} — ask your administrator
                      </span>
                    ) : null}
                    {!n.read ? (
                      <button
                        type="button"
                        onClick={() => mark.mutate([n.id])}
                        className="font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        Mark as read<span className="sr-only">: {n.title}</span>
                      </button>
                    ) : null}
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}
      {variant === 'panel' ? (
        <div className="border-t border-line px-4 py-2.5">
          <Link
            href="/system/notifications"
            onClick={onNavigate}
            className="text-[13px] font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
          >
            View all notifications
          </Link>
        </div>
      ) : null}
    </div>
  );
}

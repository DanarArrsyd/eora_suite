'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, EnterprisePage, EoraForm, FormTextareaField } from '@eora/features/patterns';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  ErrorState,
  InlineAlert,
  LoadingState,
  Modal,
  ModalBody,
  ModalContent,
  PermissionDeniedState,
} from '@eora/ui';
import { actionMessage, formFailure } from '@/modules/admin/errors';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { querySource } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M3 step 8 (doc 32 §1.8, M3-D8, BR-PER-01/03, D-102): Inventory → Control → Period Close on the live API. Inventory
 * control asks to close a month that has ended; a second person closes it. A closed month takes no postings; a CSA may ask
 * to reopen it, and another approver reopens it (an exceptional event). Every request can be withdrawn by its requester
 * or rejected with the reason.
 */

type Status = 'OPEN' | 'CLOSING' | 'CLOSED';

export type PeriodRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  status: Status;
  closeRequestedById: string | null;
  closeRequestedByName: string | null;
  closeRequestedAt: string | null;
  closedByName: string | null;
  closedAt: string | null;
  reopenRequestedById: string | null;
  reopenRequestedByName: string | null;
  reopenRequestedAt: string | null;
  reopenReason: string | null;
  reopenedByName: string | null;
  reopenedAt: string | null;
  pendingRequests: number;
};

const READ = [
  'inventory.period_close.view',
  'inventory.period_close.post',
  'inventory.period_close.approve',
  'inventory.period_reopen.view',
  'inventory.period_reopen.request_correction',
  'inventory.period_reopen.approve',
] as const;

const STATUS_OPTIONS: Option[] = [
  { value: 'OPEN', label: 'Open' },
  { value: 'CLOSING', label: 'Closing (waiting for approval)' },
  { value: 'CLOSED', label: 'Closed' },
];

const monthName = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

/** The twelve months that have ended, newest first (the server checks against the plant calendar). */
function pastMonths(now = new Date()): Option[] {
  return Array.from({ length: 12 }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - 1 - i, 1);
    const value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    return { value, label: monthName(value) };
  });
}

type Answer = { data: { period: string; status: Status } };

export const PERIODS: MasterResource<PeriodRow> = {
  id: 'period-close',
  resource: 'inventory.periods',
  permission: { view: READ[0], create: 'inventory.period_close.post', label: 'inventory periods', alsoView: READ.slice(1) },
  title: 'Period Close',
  description:
    'Monthly inventory periods per plant. A month that has ended is closed on a request approved by a second person; a closed month takes no postings, and its documents are corrected in the open month.',
  itemLabel: 'periods',
  singular: 'period',
  createLabel: 'New close request',
  searchPlaceholder: 'Search month…',
  rowLabel: (r) => `${monthName(r.code)} ${r.plantCode}`,
  openHref: (r) => `/inventory/period-close?period=${r.id}`,
  defaultSort: [{ id: 'code', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'code', header: 'Month', lead: true, sortable: true, cell: (r) => monthName(r.code), sub: (r) => r.code },
    { id: 'plantCode', header: 'Plant', sortable: true, cell: (r) => r.plantCode },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="period" status={r.status} /> },
    {
      id: 'waiting',
      header: 'Waiting',
      cell: (r) =>
        r.status === 'CLOSING'
          ? `Close asked by ${r.closeRequestedByName ?? '—'}`
          : r.reopenRequestedById
            ? `Reopen asked by ${r.reopenRequestedByName ?? '—'}`
            : r.pendingRequests > 0
              ? `${r.pendingRequests} request(s) to decide`
              : '—',
    },
    { id: 'closedAt', header: 'Closed', cell: (r) => (r.closedAt ? when(r.closedAt) : '—'), sub: (r) => r.closedByName ?? undefined },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants' },
    {
      key: 'period',
      label: 'Month',
      kind: 'select',
      required: true,
      options: pastMonths(),
      hint: 'Only a month that has ended. Adjustments and reversals of it must be decided first.',
    },
  ],
  blank: { plantId: '', period: '' },
  toForm: () => ({ plantId: '', period: '' }),
  confirm: (v) => ({
    title: 'Ask to close this month',
    description: `${v.period ? monthName(v.period) : 'The month'} takes no more postings from now on. A second person closes it, or rejects the request and it opens again.`,
    confirmLabel: 'Ask to close',
  }),
  savedMessage: (result) => `Close of ${monthName((result as Answer).data.period)} asked; it waits for a second person`,
  savedNote: 'The month is listed as Closing until the close is approved or rejected.',
  create: (v) => call(() => api.POST('/inventory/periods/close-requests', { body: { plantId: v.plantId!, period: v.period! } })),
  update: () => Promise.reject(new Error('A period changes only by its requests.')),
};

/** Inventory → Control → Period Close: the list, or one period's page with `?period=<id>`. */
export function PeriodsScreen() {
  const period = useSearchParams()?.get('period');
  return period ? <PeriodPage key={period} id={period} /> : <MasterScreen config={PERIODS} />;
}

type Action = { label: string; run: () => Promise<unknown>; done: string; primary?: boolean };
type Asking = {
  title: string;
  description: string;
  submitLabel: string;
  field: string;
  minimum: number;
  run: (text: string) => Promise<unknown>;
  done: string;
};

function PeriodPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const can = (p: string) => permissions.includes(p);
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [asking, setAsking] = React.useState<Asking | null>(null);

  const row = useQuery({
    queryKey: ['master', 'inventory.periods', 'one', id],
    queryFn: async ({ signal }) =>
      (await querySource<PeriodRow>('inventory.periods').list({ filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' }, signal)).rows[0] ??
      null,
    enabled: READ.some(can),
  });

  if (me.isPending || (READ.some(can) && row.isPending)) return <LoadingState title="Loading period" />;
  if (!READ.some(can)) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Periods are not available for your roles"
          description="Ask a tenant administrator for a role that includes period close."
        />
      </div>
    );
  }
  const r = row.data;
  if (row.isError || !r) {
    return (
      <div className="p-6 max-md:p-4">
        {row.isError ? (
          <ErrorState
            title="The period could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void row.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Period not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/inventory/period-close')}>
                Back to periods
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const meId = me.data?.user.id;
  const month = monthName(r.code);
  const path = { params: { path: { period: id } } };
  const act = async (a: Action) => {
    setError(null);
    setBusy(true);
    try {
      await a.run();
      setNotice(a.done);
      void client.invalidateQueries({ queryKey: ['master'] });
    } catch (e) {
      setError(actionMessage(e, 'Nothing changed.'));
    } finally {
      setBusy(false);
    }
  };

  // what this user may do now, in this period's state (the server decides; requesters never decide their own request)
  const actions: Action[] = [];
  const asks: Asking[] = [];
  let waiting: string | null = null;
  // any open month that has ended, e.g. one reopened long ago, can be asked to close again (the server checks the date)
  const now = new Date();
  const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
  if (r.status === 'OPEN' && r.code < thisMonth && can('inventory.period_close.post')) {
    actions.push({
      label: 'Ask to close month',
      primary: true,
      run: () => call(() => api.POST('/inventory/periods/close-requests', { body: { plantId: r.plantId, period: r.code } })),
      done: `Close of ${month} asked; it waits for a second person`,
    });
  }
  if (r.status === 'CLOSING') {
    const mine = meId === r.closeRequestedById;
    waiting = mine ? 'You asked to close this month. Another person closes it or rejects the request.' : null;
    if (mine && can('inventory.period_close.post')) {
      actions.push({
        label: 'Withdraw close request',
        run: () => call(() => api.POST('/inventory/periods/{period}/close-return', { ...path, body: {} })),
        done: `${month} is open again`,
      });
    }
    if (!mine && can('inventory.period_close.approve')) {
      actions.push({
        label: 'Close month',
        primary: true,
        run: () => call(() => api.POST('/inventory/periods/{period}/close', path)),
        done: `${month} closed`,
      });
      asks.push({
        title: `Reject the close of ${month}`,
        description: 'The month opens again for postings. The requester sees the reason.',
        submitLabel: 'Reject close',
        field: 'Reason',
        minimum: 3,
        run: (reason) => call(() => api.POST('/inventory/periods/{period}/close-return', { ...path, body: { reason } })),
        done: `Close of ${month} rejected; the month is open again`,
      });
    }
  }
  if (r.status === 'CLOSED' && !r.reopenRequestedById && can('inventory.period_reopen.request_correction')) {
    asks.push({
      title: `Ask to reopen ${month}`,
      description:
        'Reopening a closed month is an exceptional event: another approver decides it, and it is recorded with your reason. A single wrong document is corrected in the open month instead (Reversals).',
      submitLabel: 'Ask to reopen',
      field: 'Reason',
      minimum: 3,
      run: (reason) => call(() => api.POST('/inventory/periods/{period}/reopen-requests', { ...path, body: { reason } })),
      done: `Reopen of ${month} asked; it waits for another approver`,
    });
  }
  if (r.status === 'CLOSED' && r.reopenRequestedById) {
    const mine = meId === r.reopenRequestedById;
    waiting = mine ? 'You asked to reopen this month. Another approver decides it.' : null;
    if (mine && can('inventory.period_reopen.request_correction')) {
      actions.push({
        label: 'Withdraw reopen request',
        run: () => call(() => api.POST('/inventory/periods/{period}/reopen-return', { ...path, body: {} })),
        done: 'Reopen request withdrawn',
      });
    }
    if (!mine && can('inventory.period_reopen.approve')) {
      actions.push({
        label: 'Reopen month',
        primary: true,
        run: () => call(() => api.POST('/inventory/periods/{period}/reopen', path)),
        done: `${month} reopened`,
      });
      asks.push({
        title: `Reject the reopen of ${month}`,
        description: 'The month stays closed. The requester sees the reason.',
        submitLabel: 'Reject reopen',
        field: 'Reason',
        minimum: 3,
        run: (reason) => call(() => api.POST('/inventory/periods/{period}/reopen-return', { ...path, body: { reason } })),
        done: 'Reopen request rejected; the month stays closed',
      });
    }
  }

  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Plant', value: r.plantCode },
    { label: 'Month', value: `${month} (${r.code})` },
    { label: 'Close asked by', value: r.closeRequestedByName ? `${r.closeRequestedByName}, ${when(r.closeRequestedAt)}` : '—' },
    { label: 'Closed by', value: r.closedByName ? `${r.closedByName}, ${when(r.closedAt)}` : '—' },
    ...(r.reopenRequestedById
      ? [
          { label: 'Reopen asked by', value: `${r.reopenRequestedByName ?? '—'}, ${when(r.reopenRequestedAt)}` },
          { label: 'Reopen reason', value: r.reopenReason ?? '—' },
        ]
      : []),
    ...(r.reopenedByName ? [{ label: 'Last reopened by', value: `${r.reopenedByName}, ${when(r.reopenedAt)}` }] : []),
    { label: 'Requests to decide', value: r.pendingRequests > 0 ? `${r.pendingRequests} adjustment or reversal request(s) of this month` : 'None' },
  ];

  return (
    <EnterprisePage
      title={`${month} · ${r.plantCode}`}
      description={
        r.status === 'OPEN'
          ? 'Open: postings for this month are taken.'
          : r.status === 'CLOSING'
            ? 'Closing: no postings for this month while the close waits for approval.'
            : 'Closed: no postings for this month. A wrong document is corrected in the open month with its evidence.'
      }
      meta={<DomainStatusBadge domain="period" status={r.status} />}
      actions={
        <Button onClick={() => router.push('/inventory/period-close')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All periods
        </Button>
      }
    >
      {notice ? (
        <InlineAlert
          tone="success"
          title={notice}
          className="mb-4"
          action={
            <Button size="sm" onClick={() => setNotice(null)}>
              Dismiss
            </Button>
          }
        />
      ) : null}
      {waiting ? (
        <InlineAlert tone="info" title="Waiting for a second person" className="mb-4">
          {waiting}
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-lg:grid-cols-1">
        <Card>
          <CardHeader>
            <CardTitle>Period</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="m-0 grid grid-cols-[160px_minmax(0,1fr)] gap-x-4 gap-y-2.5 text-[13.5px] max-sm:grid-cols-1 max-sm:gap-y-1">
              {facts.map((f) => (
                <React.Fragment key={f.label}>
                  <dt className="text-ink-2 max-sm:mt-2">{f.label}</dt>
                  <dd className="m-0 text-ink">{f.value}</dd>
                </React.Fragment>
              ))}
            </dl>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Decisions</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2">
            {error ? (
              <InlineAlert tone="danger" title="Not done">
                {error}
              </InlineAlert>
            ) : null}
            {actions.map((a) => (
              <Button key={a.label} variant={a.primary ? 'primary' : undefined} disabled={busy} onClick={() => void act(a)}>
                {a.label}
              </Button>
            ))}
            {asks.map((a) => (
              <Button key={a.title} disabled={busy} onClick={() => setAsking(a)}>
                {a.submitLabel}
              </Button>
            ))}
            {actions.length === 0 && asks.length === 0 ? <p className="m-0 text-[13px] text-ink-2">Nothing for you to decide on this month now.</p> : null}
          </CardContent>
        </Card>
      </div>
      <Modal open={!!asking} onOpenChange={(open) => !open && setAsking(null)}>
        {asking ? (
          <ModalContent title={asking.title} description={asking.description} closeLabel="Close">
            <ModalBody>
              <p className="m-0 mb-3 text-[13.5px] text-ink-2">{asking.description}</p>
              <EoraForm
                label={asking.title}
                schema={z.object({ text: z.string().trim().min(asking.minimum, 'Give the reason.').max(500, 'Use at most 500 characters.') })}
                defaultValues={{ text: '' }}
                submitLabel={asking.submitLabel}
                secondary={
                  <Button type="button" onClick={() => setAsking(null)}>
                    Cancel
                  </Button>
                }
                onSubmit={async (v) => {
                  try {
                    await asking.run(v.text);
                    setAsking(null);
                    setNotice(asking.done);
                    void client.invalidateQueries({ queryKey: ['master'] });
                    return { ok: true };
                  } catch (e) {
                    return formFailure(e, 'Nothing changed.');
                  }
                }}
              >
                <FormTextareaField name="text" label={asking.field} required rows={3} maxLength={500} />
              </EoraForm>
            </ModalBody>
          </ModalContent>
        ) : null}
      </Modal>
    </EnterprisePage>
  );
}

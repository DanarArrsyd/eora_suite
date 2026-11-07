'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DataTable, DomainStatusBadge, EnterprisePage, type STATUS_CATALOGUE, type EoraColumn } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState, ErrorState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { DOCUMENT_TYPES } from '@/modules/master/document-types';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { querySource } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { ApprovalPanel, when } from '@/modules/master/version-ui';

/*
 * M3 step 7 (doc 32 §1.7, doc 04 row 13, D-060, D-101): Inventory → Control → Reversals on the live API. Anyone who may
 * request corrections in the plant asks for the reversal of a posted document with a reason; a second person approves
 * (posts) or rejects it on the request's page, which shows the legs that will be negated.
 */

type Status = 'SUBMITTED' | 'REJECTED' | 'POSTED';
type StockStatus = keyof (typeof STATUS_CATALOGUE)['stock'];

export type ReversalRow = {
  id: string;
  code: string;
  version: number;
  status: Status;
  plantId: string;
  plantCode: string;
  effectiveDate: string;
  reason: string;
  originalId: string;
  originalCode: string;
  originalType: string;
  originalDate: string;
  requestedById: string;
  requestedByName: string | null;
  submittedAt: string;
  decidedByName: string | null;
  decidedAt: string | null;
  rejectReason: string | null;
};

type Leg = {
  id: string;
  legNo: number;
  movementCode: string;
  locationCode: string;
  locationType: string;
  itemCode: string;
  lotNo: string | null;
  stockStatus: StockStatus;
  qty: string;
  uomCode: string;
};

type Submitted = { data: { documentId: string; replayed: boolean } };

const READ = ['inventory.reversal.view', 'inventory.reversal.request_correction', 'inventory.reversal.approve'] as const;
const typeLabel = (t: string) => DOCUMENT_TYPES[t] ?? t;
const TYPE_OPTIONS: Option[] = Object.entries(DOCUMENT_TYPES)
  .filter(([value]) => value !== 'REVERSAL')
  .map(([value, label]) => ({ value, label }));
const STATUS_OPTIONS: Option[] = [
  { value: 'SUBMITTED', label: 'Submitted (waiting for approval)' },
  { value: 'POSTED', label: 'Posted' },
  { value: 'REJECTED', label: 'Rejected' },
];

export const REVERSALS: MasterResource<ReversalRow> = {
  id: 'reversals',
  resource: 'inventory.reversals',
  permission: { view: READ[0], create: 'inventory.reversal.request_correction', label: 'reversals', alsoView: READ.slice(1) },
  title: 'Reversals',
  description:
    'A posted document is corrected by its reversal: every leg is undone as it was posted. Anyone who may request corrections asks for one with the reason; a second person approves it, and only then does stock change.',
  itemLabel: 'reversals',
  singular: 'reversal request',
  searchPlaceholder: 'Search reversal…',
  rowLabel: (r) => `${r.code ?? 'reversal request'} of ${r.originalCode}`,
  openHref: (r) => `/inventory/reversals?request=${r.id}`,
  defaultSort: [{ id: 'submittedAt', desc: true }],
  filters: [
    PLANT_FILTER,
    { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS },
    { key: 'originalType', type: 'multiselect', source: 'static', label: 'Document type', options: TYPE_OPTIONS },
  ],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'submittedAt',
      header: 'Requested',
      lead: true,
      sortable: true,
      cell: (r) => when(r.submittedAt),
      sub: (r) => (r.code ? <span className="font-mono">{r.code}</span> : (r.requestedByName ?? undefined)),
    },
    {
      id: 'originalCode',
      header: 'Reverses',
      sortable: true,
      cell: (r) => <span className="font-mono">{r.originalCode}</span>,
      sub: (r) => typeLabel(r.originalType),
    },
    { id: 'reason', header: 'Reason', cell: (r) => r.reason },
    { id: 'plantCode', header: 'Plant', sortable: true, cell: (r) => r.plantCode },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [
    {
      key: 'documentId',
      label: 'Document',
      kind: 'select',
      required: true,
      options: 'reversibleDocuments',
      hint: 'A posted document that is not reversed yet and has no reversal waiting.',
    },
    { key: 'reason', label: 'Reason', kind: 'text', required: true, maxLength: 500, hint: 'Why it is wrong, e.g. "Received on the wrong rack".' },
  ],
  blank: { documentId: '', reason: '' },
  toForm: () => ({ documentId: '', reason: '' }),
  check: async (v): Promise<Record<string, string>> => ((v.reason ?? '').trim().length < 3 ? { reason: 'Say why the document is wrong.' } : {}),
  confirm: () => ({
    title: 'Request this reversal',
    description: 'Every leg of the document will be undone once a second person approves it. Nothing changes in stock until then.',
    confirmLabel: 'Request reversal',
  }),
  savedMessage: (result) =>
    (result as Submitted).data.replayed
      ? 'This request was already sent; it is waiting for approval'
      : 'Reversal requested; it waits for a second person to approve it',
  savedNote: 'It is listed as Submitted until a second person approves or rejects it.',
  create: (v, ctx) =>
    call(() =>
      api.POST('/inventory/reversals', {
        params: { header: { 'Idempotency-Key': ctx.idempotencyKey } },
        body: { documentId: v.documentId!, reason: v.reason! },
      }),
    ),
  update: () => Promise.reject(new Error('A request is never edited: reject it and request again.')),
};

const LEG_COLUMNS: EoraColumn<Leg>[] = [
  { id: 'legNo', header: 'Leg', cell: (l) => l.legNo },
  {
    id: 'locationCode',
    header: 'Location',
    lead: true,
    cell: (l) => l.locationCode,
    sub: (l) => (l.locationType.startsWith('V_') ? 'Outside stock' : undefined),
  },
  { id: 'itemCode', header: 'Item', cell: (l) => <span className="font-mono">{l.itemCode}</span>, sub: (l) => l.lotNo ?? 'No lot' },
  { id: 'stockStatus', header: 'Status', cell: (l) => <DomainStatusBadge domain="stock" status={l.stockStatus} /> },
  { id: 'qty', header: 'Posted', numeric: true, cell: (l) => `${l.qty} ${l.uomCode}` },
  {
    id: 'undo',
    header: 'Reversal',
    numeric: true,
    critical: true,
    cell: (l) => `${l.qty.startsWith('-') ? l.qty.slice(1) : `-${l.qty}`} ${l.uomCode}`,
  },
];

/** Inventory → Control → Reversals: the list, or one request's page with `?request=<id>`. */
export function ReversalsScreen() {
  const request = useSearchParams()?.get('request');
  return request ? <ReversalPage key={request} id={request} /> : <MasterScreen config={REVERSALS} />;
}

function ReversalPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = READ.some((p) => permissions.includes(p));
  const canApprove = permissions.includes('inventory.reversal.approve');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  // one key per approval attempt of this page: a retried approval replays instead of posting twice (ADR-0014)
  const key = React.useRef(`rev-approve-${id}-${crypto.randomUUID()}`);

  const row = useQuery({
    queryKey: ['master', 'inventory.reversals', 'one', id],
    queryFn: async ({ signal }) =>
      (await querySource<ReversalRow>('inventory.reversals').list({ filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' }, signal))
        .rows[0] ?? null,
    enabled: canView,
  });
  const originalId = row.data?.originalId;
  const legs = useQuery({
    queryKey: ['master', 'inventory.movements', 'of', originalId],
    queryFn: async ({ signal }) =>
      (
        await querySource<Leg & { code: string; version: number }>('inventory.movements').list(
          { filters: { documentId: originalId! }, search: '', sort: [{ id: 'legNo', desc: false }], page: 1, pageSize: 100, view: 'all' },
          signal,
        )
      ).rows,
    enabled: !!originalId && permissions.includes('inventory.stock.view'),
  });

  if (me.isPending || (canView && row.isPending)) return <LoadingState title="Loading reversal" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Reversals are not available for your roles"
          description="Ask a tenant administrator for a role that includes reversals."
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
            title="The reversal could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void row.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Reversal not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/inventory/reversals')}>
                Back to reversals
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const mine = me.data?.user.id === r.requestedById;
  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Reverses', value: <span className="font-mono">{r.originalCode}</span> },
    { label: 'Document type', value: typeLabel(r.originalType) },
    { label: 'Posted for', value: r.originalDate },
    { label: 'Plant', value: r.plantCode },
    { label: 'Reason', value: r.reason },
    { label: 'Reversal day', value: r.effectiveDate },
    ...(r.status === 'REJECTED' ? [{ label: 'Rejected because', value: r.rejectReason ?? '—' }] : []),
  ];

  return (
    <EnterprisePage
      title={r.code ?? `Reversal of ${r.originalCode}`}
      description={`Undoes ${typeLabel(r.originalType).toLowerCase()} ${r.originalCode}: ${r.reason}`}
      meta={<DomainStatusBadge domain="document" status={r.status} />}
      actions={
        <Button onClick={() => router.push('/inventory/reversals')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All reversals
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
      {r.status === 'SUBMITTED' && mine ? (
        <InlineAlert tone="info" title="You requested this reversal" className="mb-4">
          Another person approves or rejects it.
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-lg:grid-cols-1">
        <div className="flex min-w-0 flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Request</CardTitle>
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
          {legs.data ? (
            <Card>
              <CardHeader>
                <CardTitle>Legs undone</CardTitle>
              </CardHeader>
              <CardContent>
                <DataTable
                  label={`Legs of ${r.originalCode}`}
                  columns={LEG_COLUMNS}
                  rows={legs.data}
                  getRowId={(l) => l.id}
                  rowLabel={(l) => `Leg ${l.legNo} ${l.itemCode} at ${l.locationCode}`}
                  status="ready"
                  sort={[]}
                  onSortChange={() => undefined}
                  density="compact"
                  empty={<EmptyState title="No legs" description="This document posted no legs." />}
                />
              </CardContent>
            </Card>
          ) : null}
        </div>
        <ApprovalPanel
          label="Reversal"
          record={{
            status: r.status === 'POSTED' ? 'APPROVED' : r.status,
            preparedByName: r.requestedByName,
            approvedByName: r.decidedByName,
            approvedAt: r.decidedAt,
          }}
          can={{ view: true, create: false, submit: false, approve: canApprove && !mine }}
          approvedNote={`Posted as ${r.code ?? 'a reversal'}. ${r.originalCode} is now reversed; post the corrected document if one is needed.`}
          rejectNote="Nothing changes in stock. The requester sees the reason and can request again."
          act={async () => {
            const posted = await call(() =>
              api.POST('/inventory/reversals/{reversal}/approve', { params: { path: { reversal: id }, header: { 'Idempotency-Key': key.current } } }),
            );
            key.current = `rev-approve-${id}-${crypto.randomUUID()}`;
            return posted;
          }}
          reject={(why) => call(() => api.POST('/inventory/reversals/{reversal}/reject', { params: { path: { reversal: id } }, body: { reason: why } }))}
          onDone={(message) => {
            setNotice(message.replace(/approved$/, 'approved and posted'));
            void client.invalidateQueries({ queryKey: ['master'] });
          }}
        />
      </div>
    </EnterprisePage>
  );
}

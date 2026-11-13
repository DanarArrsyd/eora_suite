'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, DomainStatusBadge, EnterprisePage, STATUS_CATALOGUE } from '@eora/features/patterns';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  ErrorState,
  Field,
  InlineAlert,
  Input,
  LoadingState,
  PermissionDeniedState,
  Select,
} from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll, querySource } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { ApprovalPanel, when } from '@/modules/master/version-ui';
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M4 step 5 (doc 33 §1.5, D-113, doc 04 row 9, BR-QC-01..03): Quality → Pending QC Queue and Dispositions on the live API.
 * The inspector decides part or all of a lot waiting at the QC area — release, hold, rework, reject (with a reason) — and
 * the rest keeps waiting. Releasing a held quantity, or a rejected one, is asked of a second person (QCS).
 */

type Waiting = 'PENDING_QC' | 'HOLD' | 'REJECTED';
type Outcome = 'RELEASE' | 'HOLD' | 'REWORK' | 'REJECT';

export type QcLineRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  locationId: string;
  locationCode: string;
  locationType: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string | null;
  lotNo: string | null;
  stockStatus: Waiting;
  qty: string;
  uomCode: string;
  uomDecimals: number;
  updatedAt: string;
};

type DispositionRow = {
  id: string;
  code: string;
  docNo: string | null;
  version: number;
  status: 'SUBMITTED' | 'REJECTED' | 'POSTED' | 'REVERSED';
  outOfPolicy: boolean;
  plantCode: string;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  fromStatus: string;
  releasedQty: string;
  holdQty: string;
  reworkQty: string;
  rejectQty: string;
  uomCode: string;
  note: string | null;
  requestedById: string | null;
  requestedByName: string | null;
  decidedByName: string | null;
  rejectReason: string | null;
  reversalId: string | null;
  reversalReason: string | null;
  reversalRequestedById: string | null;
  reversalRequestedByName: string | null;
  at: string;
};

/** D-113 reasons for hold, rework and reject (tenant configuration later). */
const REASONS = [
  { value: 'DIMENSION', label: 'Dimension out of tolerance' },
  { value: 'VISUAL', label: 'Visual (scratch, dent)' },
  { value: 'BURR', label: 'Burr' },
  { value: 'CRACK', label: 'Crack' },
  { value: 'MATERIAL', label: 'Material' },
  { value: 'OTHER', label: 'Other (explain)' },
];
const OUTCOMES: { value: Outcome; label: string; place: string }[] = [
  { value: 'RELEASE', label: 'Release', place: 'stays at the QC area, released' },
  { value: 'HOLD', label: 'Hold', place: 'stays at the QC area, on hold' },
  { value: 'REWORK', label: 'Rework', place: 'goes to the NG area for rework' },
  { value: 'REJECT', label: 'Reject', place: 'goes to the NG area, rejected' },
];
const stockLabel = (s: string) => STATUS_CATALOGUE.stock[s as keyof typeof STATUS_CATALOGUE.stock]?.label ?? s;

export const PENDING_QC: MasterResource<QcLineRow> = {
  id: 'pending-qc',
  resource: 'inventory.stock',
  permission: { view: 'quality.inspection.view', create: 'quality.inspection.post', label: 'quality dispositions' },
  noCreate: true,
  fixedFilters: { stockStatus: ['PENDING_QC', 'HOLD', 'REJECTED'], locationType: ['QC_AREA', 'NG_AREA'] },
  title: 'Pending QC Queue',
  description:
    'Output waiting for a quality decision, held quantities, and rejected ones that may be released out of policy. Open a lot to release, hold, rework or reject part or all of it.',
  itemLabel: 'lots',
  singular: 'lot',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.itemCode} ${r.lotNo ?? 'no lot'} ${stockLabel(r.stockStatus)} at ${r.locationCode}`,
  openHref: (r) => `/quality/pending-qc?line=${r.id}`,
  defaultSort: [{ id: 'updatedAt', desc: false }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'itemCode', header: 'Part', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    { id: 'lotNo', header: 'Lot', sortable: true, cell: (r) => (r.lotNo ? <span className="font-mono">{r.lotNo}</span> : 'No lot') },
    { id: 'locationCode', header: 'Place', sortable: true, cell: (r) => r.locationCode, sub: (r) => r.plantCode },
    { id: 'stockStatus', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
    { id: 'updatedAt', header: 'Waiting since', sortable: true, cell: (r) => when(r.updatedAt) },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Output comes from production postings.')),
  update: () => Promise.reject(new Error('Lots are decided on their page.')),
  readOnly: () => 'Lots are decided on their page.',
};

export const DISPOSITIONS: MasterResource<DispositionRow> = {
  id: 'inspection-lots',
  resource: 'quality.dispositions',
  permission: {
    view: 'quality.inspection.view',
    create: 'quality.inspection.post',
    label: 'quality dispositions',
    alsoView: ['quality.disposition_release.approve'],
  },
  noCreate: true,
  title: 'Dispositions',
  description: 'Quality decisions on output lots, and releases out of policy that wait for a second person. A posted disposition is corrected by its reversal.',
  itemLabel: 'dispositions',
  singular: 'disposition',
  searchPlaceholder: 'Search disposition…',
  rowLabel: (r) => `${r.code} ${r.itemCode} ${r.lotNo ?? 'no lot'}`,
  openHref: (r) => `/quality/inspection-lots?request=${r.id}`,
  defaultSort: [{ id: 'at', desc: true }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'at',
      header: 'When',
      lead: true,
      sortable: true,
      cell: (r) => when(r.at),
      sub: (r) => (r.docNo ? <span className="font-mono">{r.docNo}</span> : 'Release request'),
    },
    { id: 'itemCode', header: 'Part', cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.lotNo ?? 'No lot' },
    {
      id: 'outcome',
      header: 'Decision',
      cell: (r) =>
        [
          [r.status === 'SUBMITTED' || r.status === 'REJECTED' ? 'Release' : 'Released', r.releasedQty],
          ['Hold', r.holdQty],
          ['Rework', r.reworkQty],
          ['Reject', r.rejectQty],
        ]
          .filter(([, q]) => Number(q) > 0)
          .map(([l, q]) => `${l} ${q}`)
          .join(' · ') || '—',
      sub: (r) => (r.outOfPolicy ? `Out of policy, from ${stockLabel(r.fromStatus)}` : undefined),
    },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Dispositions are made from the Pending QC Queue.')),
  update: () => Promise.reject(new Error('A disposition is corrected by its reversal.')),
  readOnly: () => 'A disposition is corrected by its reversal.',
};

/** Quality → Pending QC Queue: the queue, or one lot's page with `?line=<stock line>`. */
export function PendingQcScreen() {
  const line = useSearchParams()?.get('line');
  return line ? <LotPage key={line} id={line} /> : <MasterScreen config={PENDING_QC} />;
}

/** Quality → Dispositions: the list, or one disposition or release request with `?request=<id>`. */
export function DispositionsScreen() {
  const request = useSearchParams()?.get('request');
  return request ? <DispositionPage key={request} id={request} /> : <MasterScreen config={DISPOSITIONS} />;
}

function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

type Decision = { qty: string; reasonCode: string; note: string };
const BLANK_DECISION: Decision = { qty: '', reasonCode: '', note: '' };

function LotPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('quality.inspection.view');
  const canDecide = permissions.includes('quality.inspection.post');
  const canAsk = permissions.includes('quality.inspection.submit');
  const router = useRouter();
  const client = useQueryClient();
  const [decisions, setDecisions] = React.useState<Record<Outcome, Decision>>({
    RELEASE: BLANK_DECISION,
    HOLD: BLANK_DECISION,
    REWORK: BLANK_DECISION,
    REJECT: BLANK_DECISION,
  });
  const [askQty, setAskQty] = React.useState('');
  const [askReason, setAskReason] = React.useState('');
  const [notice, setNotice] = React.useState<string | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);
  const [confirm, setConfirm] = React.useState<null | 'decide' | 'ask'>(null);
  // one key per attempt: a retried post replays instead of posting twice (ADR-0014)
  const key = React.useRef(`qcd-${crypto.randomUUID()}`);

  const line = useQuery({
    queryKey: ['master', 'inventory.stock', 'qc', id],
    queryFn: async ({ signal }) => (await queryAll<QcLineRow>('inventory.stock', { id }, signal, 'itemCode'))[0] ?? null,
    enabled: canView,
  });
  const r = line.data;

  if (me.isPending || (canView && line.isPending)) return <LoadingState title="Loading lot" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Quality is not available for your roles" description="Ask a tenant administrator for an inspector role." />
      </div>
    );
  }
  if (line.isError || !r || !['PENDING_QC', 'HOLD', 'REJECTED'].includes(r.stockStatus)) {
    return (
      <div className="p-6 max-md:p-4">
        {line.isError ? (
          <ErrorState
            title="The lot could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void line.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Nothing waits here any more"
            description="This quantity was decided, moved or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/quality/pending-qc')}>
                Back to the queue
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const allowed: Outcome[] = r.stockStatus === 'PENDING_QC' ? ['RELEASE', 'HOLD', 'REWORK', 'REJECT'] : r.stockStatus === 'HOLD' ? ['REJECT'] : [];
  const asked = (Object.entries(decisions) as [Outcome, Decision][]).filter(([o, d]) => allowed.includes(o) && d.qty !== '');
  const set = (o: Outcome, patch: Partial<Decision>) => setDecisions((all) => ({ ...all, [o]: { ...all[o], ...patch } }));
  const check = (): string | null => {
    if (asked.length === 0) return 'Enter the quantity of at least one decision.';
    let total = 0;
    for (const [o, d] of asked) {
      const label = OUTCOMES.find((x) => x.value === o)!.label;
      if (!/^\d+(\.\d+)?$/.test(d.qty) || Number(d.qty) <= 0) return `${label}: enter a quantity above zero.`;
      const precision = unitPrecisionError(d.qty, r.uomCode, r.uomDecimals);
      if (precision) return `${label}: ${precision}`;
      if (o !== 'RELEASE' && !d.reasonCode) return `${label}: choose the reason.`;
      if (d.reasonCode === 'OTHER' && d.note.trim().length < 3) return `${label}: explain it when the reason is Other.`;
      total += Number(d.qty);
    }
    if (total > Number(r.qty)) return `Only ${r.qty} ${r.uomCode} waits here.`;
    return null;
  };
  const decide = async () => {
    const posted = (await call(() =>
      api.POST('/quality/dispositions', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: {
          stockLineId: r.id,
          note: null,
          ngLocationId: null,
          lines: asked.map(([o, d]) => ({
            outcome: o,
            qty: d.qty,
            reasonCode: o === 'RELEASE' ? null : (d.reasonCode as 'DIMENSION'),
            note: d.note.trim() || null,
          })),
        },
      }),
    )) as { data: { docNo: string; replayed: boolean } };
    key.current = `qcd-${crypto.randomUUID()}`;
    setDecisions({ RELEASE: BLANK_DECISION, HOLD: BLANK_DECISION, REWORK: BLANK_DECISION, REJECT: BLANK_DECISION });
    setNotice(posted.data.replayed ? `${posted.data.docNo} was already posted; nothing new was posted` : `${posted.data.docNo} posted`);
    setProblem(null);
    void client.invalidateQueries({ queryKey: ['master'] });
  };
  const ask = async () => {
    await call(() =>
      api.POST('/quality/release-requests', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: { stockLineId: r.id, qty: askQty, reason: askReason.trim(), qcLocationId: null },
      }),
    );
    key.current = `qcd-${crypto.randomUUID()}`;
    setAskQty('');
    setAskReason('');
    setNotice('Release asked; it waits for a second person (Dispositions)');
    setProblem(null);
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  return (
    <EnterprisePage
      title={`${r.itemCode}${r.lotNo ? ` · ${r.lotNo}` : ''}`}
      description={`${r.qty} ${r.uomCode} at ${r.locationCode}.`}
      meta={<DomainStatusBadge domain="stock" status={r.stockStatus} />}
      actions={
        <Button onClick={() => router.push('/quality/pending-qc')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          Queue
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
      {problem ? (
        <InlineAlert tone="danger" title="Not sent" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      {allowed.length > 0 && canDecide ? (
        <Card className="mb-4">
          <CardHeader>
            <CardTitle>Decide</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="m-0 mb-3 text-[13px] text-ink-2">
              Enter what you decided; the rest keeps waiting. {r.stockStatus === 'HOLD' ? 'A held quantity is rejected here; its release is asked below.' : ''}
            </p>
            <div className="flex flex-col divide-y divide-line">
              {OUTCOMES.filter((o) => allowed.includes(o.value)).map((o) => (
                <fieldset
                  key={o.value}
                  className="m-0 grid min-w-0 grid-cols-[minmax(0,180px)_minmax(0,1fr)_minmax(0,1fr)] items-start gap-x-3 gap-y-2 border-0 px-0 py-3 first:pt-0 max-md:grid-cols-1"
                >
                  <legend className="col-span-full float-left m-0 w-full p-0 text-[13.5px] font-semibold text-ink">
                    {o.label} <span className="font-normal text-ink-2">· {o.place}</span>
                  </legend>
                  <Field label={`${o.label} (${r.uomCode})`}>
                    {(control) => (
                      <Input
                        {...control}
                        inputMode="decimal"
                        className="tabular-nums"
                        value={decisions[o.value].qty}
                        onChange={(e) => set(o.value, { qty: e.target.value.trim() })}
                      />
                    )}
                  </Field>
                  {o.value === 'RELEASE' ? (
                    <p className="m-0 self-center text-[13px] text-ink-2 max-md:hidden">No reason needed.</p>
                  ) : (
                    <Field label={`${o.label} reason`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={decisions[o.value].reasonCode}
                          onValueChange={(v) => set(o.value, { reasonCode: v })}
                          placeholder="Choose"
                          options={REASONS}
                        />
                      )}
                    </Field>
                  )}
                  <Field label={`${o.label} note`}>
                    {(control) => (
                      <Input {...control} maxLength={500} value={decisions[o.value].note} onChange={(e) => set(o.value, { note: e.target.value })} />
                    )}
                  </Field>
                </fieldset>
              ))}
            </div>
            <div className="mt-4 flex justify-end">
              <Button
                variant="primary"
                onClick={() => {
                  const why = check();
                  setProblem(why);
                  if (!why) setConfirm('decide');
                }}
              >
                Post decision
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}
      {r.stockStatus !== 'PENDING_QC' && canAsk ? (
        <Card>
          <CardHeader>
            <CardTitle>Ask a release out of policy</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="m-0 mb-3 text-[13px] text-ink-2">
              Releasing a {r.stockStatus === 'HOLD' ? 'held' : 'rejected'} quantity needs a second person (quality supervisor). Nothing moves until it is
              approved.
            </p>
            <div className="grid grid-cols-[minmax(0,160px)_minmax(0,1fr)] gap-3 max-md:grid-cols-1">
              <Field label={`Release (${r.uomCode})`}>
                {(control) => (
                  <Input {...control} inputMode="decimal" className="tabular-nums" value={askQty} onChange={(e) => setAskQty(e.target.value.trim())} />
                )}
              </Field>
              <Field label="Reason" required hint="Why it may be released, e.g. the deviation the customer accepted.">
                {(control) => <Input {...control} maxLength={500} value={askReason} onChange={(e) => setAskReason(e.target.value)} />}
              </Field>
            </div>
            <div className="mt-4 flex justify-end">
              <Button
                onClick={() => {
                  const why =
                    !/^\d+(\.\d+)?$/.test(askQty) || Number(askQty) <= 0
                      ? 'Enter the quantity to release.'
                      : Number(askQty) > Number(r.qty)
                        ? `Only ${r.qty} ${r.uomCode} is here.`
                        : (unitPrecisionError(askQty, r.uomCode, r.uomDecimals) ?? (askReason.trim().length < 3 ? 'Give the reason.' : null));
                  setProblem(why);
                  if (!why) setConfirm('ask');
                }}
              >
                Ask release
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <ConfirmDialog
        open={confirm === 'decide'}
        onOpenChange={(v) => (v ? undefined : setConfirm(null))}
        title="Post this decision"
        description={`${asked.map(([o, d]) => `${OUTCOMES.find((x) => x.value === o)!.label} ${d.qty}`).join(', ')} ${r.uomCode}. A posted disposition is corrected only by its reversal.`}
        confirmLabel="Post decision"
        onConfirm={async () => {
          try {
            await decide();
          } catch (e) {
            setProblem(refusal(e, 'The decision could not be posted.'));
          }
          setConfirm(null);
        }}
      />
      <ConfirmDialog
        open={confirm === 'ask'}
        onOpenChange={(v) => (v ? undefined : setConfirm(null))}
        title="Ask this release"
        description={`${askQty} ${r.uomCode} back to released once a second person approves it. Nothing moves until then.`}
        confirmLabel="Ask release"
        onConfirm={async () => {
          try {
            await ask();
          } catch (e) {
            setProblem(refusal(e, 'The release could not be asked.'));
          }
          setConfirm(null);
        }}
      />
    </EnterprisePage>
  );
}

function DispositionPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('quality.inspection.view') || permissions.includes('quality.disposition_release.approve');
  const canApprove = permissions.includes('quality.disposition_release.approve');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const key = React.useRef(`qcr-approve-${id}-${crypto.randomUUID()}`);
  // one key per attempt for the reversal decision (D-113: a quality supervisor decides it)
  const reverseKey = React.useRef(`qcrv-${crypto.randomUUID()}`);
  const row = useQuery({
    queryKey: ['master', 'quality.dispositions', 'one', id],
    queryFn: async ({ signal }) =>
      (await querySource<DispositionRow>('quality.dispositions').list({ filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' }, signal))
        .rows[0] ?? null,
    enabled: canView,
  });
  if (me.isPending || (canView && row.isPending)) return <LoadingState title="Loading disposition" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Quality is not available for your roles" description="Ask a tenant administrator for a quality role." />
      </div>
    );
  }
  const r = row.data;
  if (row.isError || !r) {
    return (
      <div className="p-6 max-md:p-4">
        <EmptyState
          title="Disposition not found"
          description="It does not exist or is outside the plants you can see."
          action={
            <Button size="sm" onClick={() => router.push('/quality/inspection-lots')}>
              Back to dispositions
            </Button>
          }
        />
      </div>
    );
  }
  const mine = me.data?.user.id === r.requestedById;
  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Part', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ?? 'No lot' },
    { label: 'From', value: stockLabel(r.fromStatus) },
    ...(Number(r.releasedQty) > 0
      ? [{ label: r.status === 'SUBMITTED' || r.status === 'REJECTED' ? 'Release asked' : 'Released', value: `${r.releasedQty} ${r.uomCode}` }]
      : []),
    ...(Number(r.holdQty) > 0 ? [{ label: 'Hold', value: `${r.holdQty} ${r.uomCode}` }] : []),
    ...(Number(r.reworkQty) > 0 ? [{ label: 'Rework', value: `${r.reworkQty} ${r.uomCode}` }] : []),
    ...(Number(r.rejectQty) > 0 ? [{ label: 'Reject', value: `${r.rejectQty} ${r.uomCode}` }] : []),
    { label: r.outOfPolicy ? 'Reason' : 'Note', value: r.note ?? '—' },
    ...(r.status === 'REJECTED' ? [{ label: 'Rejected because', value: r.rejectReason ?? '—' }] : []),
    ...(r.reversalId ? [{ label: 'Reversal asked', value: `${r.reversalReason ?? '—'} (${r.reversalRequestedByName ?? 'unknown'})` }] : []),
  ];
  return (
    <EnterprisePage
      title={r.docNo ?? `${r.itemCode} release request`}
      description={r.outOfPolicy ? 'A release out of policy: a second person decides it.' : 'A quality disposition.'}
      meta={<DomainStatusBadge domain="document" status={r.status} />}
      actions={
        <Button onClick={() => router.push('/quality/inspection-lots')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All dispositions
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
        <InlineAlert tone="info" title="You asked for this release" className="mb-4">
          A second person approves or rejects it.
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-lg:grid-cols-1">
        <Card>
          <CardHeader>
            <CardTitle>{r.outOfPolicy ? 'Release request' : 'Disposition'}</CardTitle>
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
        <div className="flex flex-col gap-4">
          {r.outOfPolicy ? (
            <ApprovalPanel
              label="Release"
              record={{
                status: r.status === 'POSTED' || r.status === 'REVERSED' ? 'APPROVED' : r.status,
                preparedByName: r.requestedByName,
                approvedByName: r.decidedByName,
                approvedAt: r.status === 'SUBMITTED' ? null : r.at,
              }}
              can={{ view: true, create: false, submit: false, approve: canApprove && !mine }}
              approvedNote={`Posted as ${r.docNo ?? 'a disposition'}. A posted release is corrected by its reversal.`}
              rejectNote="Nothing moves. The inspector sees the reason."
              act={async () => {
                const posted = await call(() =>
                  api.POST('/quality/release-requests/{release}/approve', { params: { path: { release: id }, header: { 'Idempotency-Key': key.current } } }),
                );
                key.current = `qcr-approve-${id}-${crypto.randomUUID()}`;
                return posted;
              }}
              reject={(why) => call(() => api.POST('/quality/release-requests/{release}/reject', { params: { path: { release: id } }, body: { reason: why } }))}
              onDone={(message) => {
                setNotice(message.replace(/approved$/, 'approved and posted'));
                void client.invalidateQueries({ queryKey: ['master'] });
              }}
            />
          ) : null}
          {r.reversalId ? (
            <ApprovalPanel
              label="Reversal"
              record={{ status: 'SUBMITTED', preparedByName: r.reversalRequestedByName, approvedByName: null, approvedAt: null }}
              can={{ view: true, create: false, submit: false, approve: canApprove && me.data?.user.id !== r.reversalRequestedById }}
              approvedNote="Reversed: the stock is back where it was before this disposition."
              rejectNote="Nothing moves; the disposition stays posted. Who asked sees the reason."
              act={async () => {
                const reversal = r.reversalId!;
                const posted = await call(() =>
                  api.POST('/inventory/reversals/{reversal}/approve', { params: { path: { reversal }, header: { 'Idempotency-Key': reverseKey.current } } }),
                );
                reverseKey.current = `qcrv-${crypto.randomUUID()}`;
                return posted;
              }}
              reject={(why) =>
                call(() => api.POST('/inventory/reversals/{reversal}/reject', { params: { path: { reversal: r.reversalId! } }, body: { reason: why } }))
              }
              onDone={(message) => {
                setNotice(message.replace(/approved$/, 'approved: the disposition is reversed'));
                void client.invalidateQueries({ queryKey: ['master'] });
              }}
            />
          ) : null}
        </div>
      </div>
    </EnterprisePage>
  );
}

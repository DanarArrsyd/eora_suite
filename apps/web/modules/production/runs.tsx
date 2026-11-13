'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, DataTable, DomainStatusBadge, EnterprisePage, type EoraColumn } from '@eora/features/patterns';
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
import { queryAll } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M4 step 4 (doc 33 §1.4, D-111, doc 04 rows 5 and 8, ADR-0003): Production → Production Runs and Production Postings on
 * the live API. The production leader opens a run for a released work order at a work center of its routing, posts what
 * was consumed (measured: weighed or counted what is left, or weighed what was used, with evidence), made (good, NG
 * rework, NG reject pieces) and scrapped (kg with a reason), and closes the run. Nothing is consumed automatically.
 */

type RunStatus = 'OPEN' | 'CLOSED' | 'CANCELLED';

export type RunRow = {
  id: string;
  code: string;
  version: number;
  status: RunStatus;
  plantId: string;
  plantCode: string;
  workOrderId: string;
  workOrderCode: string;
  workOrderStatus: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  uomDecimals: number;
  inspectionRequired: boolean;
  workCenterCode: string;
  machineCode: string | null;
  goodQty: string;
  ngReworkQty: string;
  ngRejectQty: string;
  postings: number;
  note: string | null;
  openedByName: string | null;
  openedAt: string;
  closedAt: string | null;
  closedByName: string | null;
  cancelReason: string | null;
};

type AtWorkCenter = {
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string | null;
  lotNo: string | null;
  qty: string;
  uomCode: string;
  uomDecimals: number;
};
type Run = RunRow & { atWorkCenter: AtWorkCenter[]; plantToday: string };

type PostingRow = {
  id: string;
  code: string;
  version: number;
  status: 'POSTED' | 'REVERSED';
  plantCode: string;
  runId: string;
  runCode: string;
  workOrderCode: string;
  itemCode: string;
  uomCode: string;
  workCenterCode: string;
  productionDate: string;
  shiftCode: string | null;
  goodQty: string;
  ngReworkQty: string;
  ngRejectQty: string;
  scrapSummary: string | null;
  outputLotNo: string | null;
  correctsCode: string | null;
  postedByName: string | null;
  postedAt: string;
};

/** ADR-0003 methods in M4 (D-111). */
const METHODS = [
  { value: 'WEIGHED_REMAINING', label: 'Weighed what is left' },
  { value: 'COUNTED_REMAINING', label: 'Counted what is left' },
  { value: 'WEIGHED_CONSUMED', label: 'Weighed what was used' },
];
/** BR-OUT-04 scrap reasons (D-111). */
const SCRAP_REASONS = [
  { value: 'SKELETON', label: 'Skeleton / web' },
  { value: 'SETUP', label: 'Setup and trial pieces' },
  { value: 'EDGE_TRIM', label: 'Edge trim / coil end' },
  { value: 'DEFECT', label: 'Defective material' },
  { value: 'OTHER', label: 'Other (explain)' },
];
const RUN_STATUS = [
  { value: 'OPEN', label: 'Open' },
  { value: 'CLOSED', label: 'Closed' },
  { value: 'CANCELLED', label: 'Cancelled' },
];
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const runStatus = (s: RunStatus) => s;

export const PRODUCTION_RUNS: MasterResource<RunRow> = {
  id: 'runs',
  resource: 'production.runs',
  permission: { view: 'production.run.view', create: 'production.run.post', label: 'production runs' },
  title: 'Production Runs',
  description:
    'A work order made at one work center. The production leader opens the run, posts what was consumed, made and scrapped, and closes it. Material is consumed only as measured.',
  itemLabel: 'runs',
  singular: 'run',
  createLabel: 'Open run',
  createHref: '/production/runs?new=1',
  searchPlaceholder: 'Search run or work order…',
  rowLabel: (r) => `${r.code} ${r.workOrderCode} ${r.workCenterCode}`,
  openHref: (r) => `/production/runs?run=${r.id}`,
  defaultSort: [{ id: 'openedAt', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: RUN_STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'openedAt', header: 'Opened', lead: true, sortable: true, cell: (r) => when(r.openedAt), sub: (r) => <span className="font-mono">{r.code}</span> },
    { id: 'workOrderCode', header: 'Work order', sortable: true, cell: (r) => <span className="font-mono">{r.workOrderCode}</span>, sub: (r) => r.itemCode },
    { id: 'workCenterCode', header: 'Work center', sortable: true, cell: (r) => r.workCenterCode, sub: (r) => r.machineCode ?? r.plantCode },
    { id: 'goodQty', header: 'Good', numeric: true, critical: true, cell: (r) => `${r.goodQty} ${r.uomCode}` },
    { id: 'ngQty', header: 'NG', numeric: true, cell: (r) => `${Number(r.ngReworkQty) + Number(r.ngRejectQty)} ${r.uomCode}` },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={runStatus(r.status)} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Runs are opened on their own page.')),
  update: () => Promise.reject(new Error('A run is closed or cancelled on its page.')),
  readOnly: () => 'A run is closed or cancelled on its page.',
};

export const PRODUCTION_POSTINGS: MasterResource<PostingRow> = {
  id: 'postings',
  resource: 'production.postings',
  permission: { view: 'production.posting.view', create: 'production.posting.post', label: 'production postings' },
  noCreate: true,
  title: 'Production Postings',
  description:
    'What each run consumed, made and scrapped, one document per run, production date and shift. A wrong posting is reversed and posted again as a correction.',
  itemLabel: 'postings',
  singular: 'posting',
  searchPlaceholder: 'Search posting, run or work order…',
  rowLabel: (r) => `${r.code} ${r.runCode} ${r.workOrderCode}`,
  openHref: (r) => `/production/runs?run=${r.runId}`,
  defaultSort: [{ id: 'postedAt', desc: true }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'postedAt', header: 'Posted', lead: true, sortable: true, cell: (r) => when(r.postedAt), sub: (r) => <span className="font-mono">{r.code}</span> },
    {
      id: 'productionDate',
      header: 'Production date',
      sortable: true,
      cell: (r) => r.productionDate,
      sub: (r) => (r.shiftCode ? `Shift ${r.shiftCode}` : undefined),
    },
    { id: 'workOrderCode', header: 'Work order', sortable: true, cell: (r) => <span className="font-mono">{r.workOrderCode}</span>, sub: (r) => r.runCode },
    { id: 'goodQty', header: 'Good', numeric: true, critical: true, cell: (r) => `${r.goodQty} ${r.uomCode}` },
    { id: 'ngQty', header: 'NG', numeric: true, cell: (r) => `${Number(r.ngReworkQty) + Number(r.ngRejectQty)} ${r.uomCode}` },
    { id: 'scrapSummary', header: 'Scrap', numeric: true, cell: (r) => r.scrapSummary ?? '—' },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Postings are made on their run.')),
  update: () => Promise.reject(new Error('A posting is corrected by its reversal.')),
  readOnly: () => 'A posting is corrected by its reversal.',
};

/** Production → Production Runs: the list, a run with `?run=<id>`, or opening one with `?new=1` (optionally `&wo=<id>`). */
export function ProductionRunsScreen() {
  const params = useSearchParams();
  const run = params?.get('run');
  if (run) return <RunPage key={run} id={run} />;
  if (params?.get('new') || params?.get('wo')) return <OpenRunPage initialWo={params?.get('wo') ?? ''} />;
  return <MasterScreen config={PRODUCTION_RUNS} />;
}

/** The first field message of a refused request, or its explanation. */
function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

type WorkOrder = {
  id: string;
  code: string;
  itemCode: string;
  itemName: string;
  qty: string;
  uomCode: string;
  workCenters: { id: string; code: string; name: string; opSeq: number }[];
};

function OpenRunPage({ initialWo }: { initialWo: string }) {
  const me = useMe();
  const can = (me.data?.permissions ?? []).includes('production.run.post');
  const router = useRouter();
  const [woId, setWoId] = React.useState(initialWo);
  const [wcId, setWcId] = React.useState('');
  const [note, setNote] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const orders = useQuery({
    queryKey: ['master', 'planning.work_orders', 'producible'],
    queryFn: ({ signal }) =>
      queryAll<{ id: string; code: string; itemCode: string; qty: string; uomCode: string }>(
        'planning.work_orders',
        { status: ['RELEASED', 'IN_PROGRESS'] },
        signal,
      ),
    enabled: can,
  });
  const order = useQuery({
    queryKey: ['master', 'planning.work_orders', 'one', woId],
    queryFn: async () => (await call(() => api.GET('/planning/work-orders/{order}', { params: { path: { order: woId } } }))) as unknown as { data: WorkOrder },
    enabled: can && !!woId,
    retry: false,
  });
  const o = order.data?.data;
  const workCenter = wcId || o?.workCenters[0]?.id || '';

  if (me.isPending) return <LoadingState title="Loading" />;
  if (!can) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Opening runs is not available for your roles" description="Ask a tenant administrator for the production leader role." />
      </div>
    );
  }
  const open = async () => {
    setBusy(true);
    try {
      const created = (await call(() =>
        api.POST('/production/runs', { body: { workOrderId: woId, workCenterId: workCenter, machineId: null, note: note.trim() || null } }),
      )) as unknown as { data: { id: string } };
      router.push(`/production/runs?run=${created.data.id}`);
    } catch (e) {
      setProblem(refusal(e, 'The run could not be opened.'));
      setBusy(false);
    }
  };

  return (
    <EnterprisePage
      title="Open run"
      description="A run is one work order made at one work center. Its material must be issued to that work center first."
      actions={
        <Button onClick={() => router.push('/production/runs')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All runs
        </Button>
      }
    >
      {problem ? (
        <InlineAlert tone="danger" title="Not opened" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <Card>
        <CardContent className="pt-5">
          <div className="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <Field label="Work order" required hint={o ? `${o.itemCode} · ${o.itemName} · ${o.qty} ${o.uomCode}` : 'Released or in production.'}>
              {(control) => (
                <Select
                  {...control}
                  value={woId}
                  onValueChange={(v) => {
                    setWoId(v);
                    setWcId('');
                    setProblem(null);
                  }}
                  placeholder={orders.isPending ? 'Loading…' : 'Choose a work order'}
                  options={(orders.data ?? []).map((w) => ({ value: w.id, label: `${w.code} · ${w.itemCode} · ${w.qty} ${w.uomCode}` }))}
                />
              )}
            </Field>
            <Field label="Work center" required hint="Work centers of the work order’s routing; the first operation is proposed, as for the material issue.">
              {(control) => (
                <Select
                  {...control}
                  value={workCenter}
                  onValueChange={setWcId}
                  disabled={!o}
                  placeholder="Choose a work center"
                  options={(o?.workCenters ?? []).map((w) => ({ value: w.id, label: `${w.code} · ${w.name} (op ${w.opSeq})` }))}
                />
              )}
            </Field>
            <Field label="Note" className="col-span-2 max-md:col-span-1" hint="Optional, e.g. the machine or the crew.">
              {(control) => <Input {...control} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
            </Field>
          </div>
          <div className="mt-4 flex justify-end">
            <Button variant="primary" disabled={!woId || !workCenter || busy} onClick={() => void open()}>
              {busy ? 'Opening…' : 'Open run'}
            </Button>
          </div>
        </CardContent>
      </Card>
    </EnterprisePage>
  );
}

type Consumption = { key: string; line: string; method: string; qty: string; evidence: string };
type Scrap = { key: string; itemId: string; qty: string; reasonCode: string; note: string };
const lineKey = (m: AtWorkCenter) => `${m.itemId}|${m.lotId ?? ''}`;

function RunPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('production.run.view');
  const canRun = permissions.includes('production.run.post');
  const canPost = permissions.includes('production.posting.post');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);
  const [ask, setAsk] = React.useState<null | 'post' | 'close' | 'cancel'>(null);
  const [consumptions, setConsumptions] = React.useState<Consumption[]>([]);
  const [good, setGood] = React.useState('');
  const [ngRework, setNgRework] = React.useState('');
  const [ngReject, setNgReject] = React.useState('');
  const [scrap, setScrap] = React.useState<Scrap[]>([]);
  const [corrects, setCorrects] = React.useState('');
  const [note, setNote] = React.useState('');
  // one key per posting attempt: a retried post replays instead of posting twice (ADR-0014)
  const key = React.useRef(`prd-${crypto.randomUUID()}`);

  const run = useQuery({
    queryKey: ['master', 'production.runs', 'one', id],
    queryFn: async () => (await call(() => api.GET('/production/runs/{run}', { params: { path: { run: id } } }))) as unknown as { data: Run },
    enabled: canView,
    retry: false,
  });
  const postings = useQuery({
    queryKey: ['master', 'production.postings', 'run', id],
    queryFn: ({ signal }) => queryAll<PostingRow>('production.postings', { runId: id }, signal, 'postedAt'),
    enabled: canView && permissions.includes('production.posting.view'),
  });
  const scrapItems = useQuery({
    queryKey: ['master', 'core.items', 'scrap'],
    queryFn: ({ signal }) =>
      queryAll<{ id: string; code: string; name: string; baseUomCode: string; baseUomDecimals: number }>(
        'core.items',
        { itemType: 'SCRAP', status: 'ACTIVE' },
        signal,
      ),
    enabled: canPost,
  });
  const r = run.data?.data;

  if (me.isPending || (canView && run.isPending)) return <LoadingState title="Loading run" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Production runs are not available for your roles"
          description="Ask a tenant administrator for a role that includes production."
        />
      </div>
    );
  }
  if (!r) {
    const missing = run.error instanceof ApiError && run.error.status === 404;
    return (
      <div className="p-6 max-md:p-4">
        {missing ? (
          <EmptyState
            title="Run not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/production/runs')}>
                Back to runs
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The run could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void run.refetch()}>
                Try again
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const changed = (message: string) => {
    setNotice(message);
    setProblem(null);
    void client.invalidateQueries({ queryKey: ['master'] });
  };
  const lineOf = (k: string) => r.atWorkCenter.find((m) => lineKey(m) === k);
  const reversed = (postings.data ?? []).filter((p) => p.status === 'REVERSED');
  const scrapItem = (itemId: string) => scrapItems.data?.find((i) => i.id === itemId);

  const check = (): string | null => {
    if (r.status === 'CLOSED' && !corrects) return 'A closed run takes only the correction of a reversed posting: choose it under Corrects.';
    if (consumptions.length === 0) return 'Enter what was consumed: at least one material line, measured.';
    const seen = new Set<string>();
    for (const c of consumptions) {
      const m = lineOf(c.line);
      if (!m) return 'Choose the material and lot of each consumption line.';
      const label = `${m.itemCode}${m.lotNo ? ` ${m.lotNo}` : ''}`;
      if (seen.has(c.line)) return `${label}: enter each lot once.`;
      seen.add(c.line);
      if (!c.method) return `${label}: choose how it was measured.`;
      if (!/^\d+(\.\d+)?$/.test(c.qty)) return `${label}: enter the measured quantity.`;
      const precision = unitPrecisionError(c.qty, m.uomCode, m.uomDecimals);
      if (precision) return `${label}: ${precision}`;
      const used = c.method === 'WEIGHED_CONSUMED' ? Number(c.qty) : Number(m.qty) - Number(c.qty);
      if (used <= 0)
        return c.method === 'WEIGHED_CONSUMED'
          ? `${label}: enter the used quantity.`
          : `${label}: what is left must be less than the ${m.qty} ${m.uomCode} here.`;
      if (used > Number(m.qty)) return `${label}: only ${m.qty} ${m.uomCode} is at the work center.`;
      if (!c.evidence.trim()) return `${label}: enter the evidence (scale, counter).`;
    }
    for (const [label, v] of [
      ['Good', good],
      ['NG rework', ngRework],
      ['NG reject', ngReject],
    ] as const) {
      if (v && (!/^\d+(\.\d+)?$/.test(v) || unitPrecisionError(v, r.uomCode, r.uomDecimals))) return `${label}: enter a quantity in ${r.uomCode}.`;
    }
    for (const s of scrap) {
      const item = scrapItem(s.itemId);
      if (!item) return 'Choose the scrap item of each scrap line.';
      if (!/^\d+(\.\d+)?$/.test(s.qty) || Number(s.qty) <= 0) return `${item.code}: enter the scrap weight.`;
      if (!s.reasonCode) return `${item.code}: choose the reason.`;
      if (s.reasonCode === 'OTHER' && s.note.trim().length < 3) return `${item.code}: explain the scrap when the reason is Other.`;
    }
    if (!Number(good) && !Number(ngRework) && !Number(ngReject) && scrap.length === 0) return 'Enter what was made: good, NG or scrap.';
    return null;
  };

  const post = async () => {
    const posted = (await call(() =>
      api.POST('/production/postings', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: {
          runId: id,
          consumptions: consumptions.map((c) => {
            const m = lineOf(c.line)!;
            return { itemId: m.itemId, lotId: m.lotId, method: c.method as 'WEIGHED_REMAINING', qty: c.qty, evidence: c.evidence.trim() };
          }),
          goodQty: good || null,
          ngReworkQty: ngRework || null,
          ngRejectQty: ngReject || null,
          scrap: scrap.map((s) => ({ itemId: s.itemId, qty: s.qty, reasonCode: s.reasonCode as 'SKELETON', note: s.note.trim() || null })),
          correctsPostingId: corrects || null,
          note: note.trim() || null,
        },
      }),
    )) as { data: { docNo: string; replayed: boolean; lotNo: string | null } };
    key.current = `prd-${crypto.randomUUID()}`;
    setConsumptions([]);
    setGood('');
    setNgRework('');
    setNgReject('');
    setScrap([]);
    setCorrects('');
    setNote('');
    changed(
      posted.data.replayed
        ? `${posted.data.docNo} was already posted; nothing new was posted`
        : `${posted.data.docNo} posted${posted.data.lotNo ? `, output lot ${posted.data.lotNo}` : ''}`,
    );
  };
  const act = (action: 'close' | 'cancel', body?: { reason: string }) =>
    call(() =>
      api.POST(`/production/runs/{run}/actions/${action}` as '/production/runs/{run}/actions/close', {
        params: { path: { run: id } },
        headers: ifMatch(r.version),
        ...(body ? { body: body as never } : {}),
      }),
    ).catch((e: unknown) => {
      throw new Error(failureMessage(e, 'The run could not be changed.'));
    });

  const atColumns: EoraColumn<AtWorkCenter>[] = [
    { id: 'itemCode', header: 'Material', lead: true, cell: (m) => <span className="font-mono">{m.itemCode}</span>, sub: (m) => m.itemName },
    { id: 'lotNo', header: 'Lot', cell: (m) => m.lotNo ?? 'No lot' },
    { id: 'qty', header: 'At the work center', numeric: true, critical: true, cell: (m) => `${m.qty} ${m.uomCode}` },
  ];
  const postingColumns: EoraColumn<PostingRow>[] = [
    {
      id: 'code',
      header: 'Posting',
      lead: true,
      cell: (p) => <span className="font-mono">{p.code}</span>,
      sub: (p) => (p.correctsCode ? `Corrects ${p.correctsCode}` : p.productionDate),
    },
    { id: 'goodQty', header: 'Good', numeric: true, cell: (p) => `${p.goodQty} ${p.uomCode}` },
    { id: 'ngQty', header: 'NG', numeric: true, cell: (p) => `${Number(p.ngReworkQty) + Number(p.ngRejectQty)} ${p.uomCode}` },
    { id: 'scrapSummary', header: 'Scrap', numeric: true, cell: (p) => p.scrapSummary ?? '—' },
    { id: 'outputLotNo', header: 'Output lot', cell: (p) => p.outputLotNo ?? '—' },
    { id: 'status', header: 'Status', cell: (p) => <DomainStatusBadge domain="document" status={p.status} /> },
  ];
  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Work order', value: <span className="font-mono">{r.workOrderCode}</span> },
    { label: 'Makes', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Work center', value: `${r.workCenterCode}${r.machineCode ? ` · ${r.machineCode}` : ''} · ${r.plantCode}` },
    { label: 'Good output goes to', value: r.inspectionRequired ? 'QC area, waiting for inspection' : 'QC area, released (no inspection)' },
    { label: 'Made so far', value: `Good ${r.goodQty} · NG rework ${r.ngReworkQty} · NG reject ${r.ngRejectQty} ${r.uomCode}` },
    { label: 'Opened', value: `${when(r.openedAt)}${r.openedByName ? ` · ${r.openedByName}` : ''}` },
    ...(r.closedAt
      ? [{ label: r.status === 'CANCELLED' ? 'Cancelled' : 'Closed', value: `${when(r.closedAt)}${r.closedByName ? ` · ${r.closedByName}` : ''}` }]
      : []),
    ...(r.cancelReason ? [{ label: 'Cancelled because', value: r.cancelReason }] : []),
    ...(r.note ? [{ label: 'Note', value: r.note }] : []),
  ];
  const lineOptions = r.atWorkCenter.map((m) => ({ value: lineKey(m), label: `${m.itemCode} · ${m.lotNo ?? 'no lot'} · ${m.qty} ${m.uomCode} here` }));
  const qtyField = (label: string, value: string, set: (v: string) => void) => (
    <Field label={`${label} (${r.uomCode})`}>
      {(control) => <Input {...control} inputMode="decimal" className="tabular-nums" value={value} onChange={(e) => set(e.target.value.trim())} />}
    </Field>
  );

  return (
    <EnterprisePage
      title={r.code}
      description={`${r.workOrderCode} · ${r.itemCode} at ${r.workCenterCode}`}
      meta={<DomainStatusBadge domain="document" status={runStatus(r.status)} />}
      actions={
        <div className="flex flex-wrap gap-2">
          {r.status === 'OPEN' && canRun && r.postings > 0 ? <Button onClick={() => setAsk('close')}>Close run</Button> : null}
          {r.status === 'OPEN' && canRun && r.postings === 0 && (postings.data ?? []).length === 0 ? (
            <Button variant="ghost" onClick={() => setAsk('cancel')}>
              Cancel run
            </Button>
          ) : null}
          <Button onClick={() => router.push('/production/runs')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All runs
          </Button>
        </div>
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
        <InlineAlert tone="danger" title="Not posted" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_340px] gap-4 max-lg:grid-cols-1">
        <div className="flex min-w-0 flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>Material at the work center</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable
                label={`Material of ${r.workOrderCode} at ${r.workCenterCode}`}
                columns={atColumns}
                rows={r.atWorkCenter}
                getRowId={lineKey}
                rowLabel={(m) => `${m.itemCode} ${m.lotNo ?? 'no lot'} ${m.qty} ${m.uomCode}`}
                status="ready"
                sort={[]}
                onSortChange={() => undefined}
                density="compact"
                empty={
                  <EmptyState title="Nothing here" description="Issue the work order’s material to this work center first (Materials → Material Issue)." />
                }
              />
            </CardContent>
          </Card>
          {(r.status === 'OPEN' || (r.status === 'CLOSED' && reversed.length > 0)) && canPost ? (
            <Card>
              <CardHeader>
                <CardTitle>{r.status === 'CLOSED' ? 'Post a correction' : 'Post production'}</CardTitle>
              </CardHeader>
              <CardContent>
                <h3 className="m-0 text-[13.5px] font-semibold text-ink">Consumed (measured)</h3>
                <p className="m-0 mt-1 text-[13px] text-ink-2">
                  Weigh or count what is left, or weigh what was used. Nothing is consumed from the BOM by itself.
                </p>
                {consumptions.map((c, i) => (
                  <div key={c.key} className="mt-3 grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_120px_minmax(0,1fr)_auto] items-end gap-3 max-lg:grid-cols-2">
                    <Field label={`Material and lot ${i + 1}`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={c.line}
                          onValueChange={(v) => setConsumptions((all) => all.map((x, j) => (j === i ? { ...x, line: v } : x)))}
                          placeholder="Choose material"
                          options={lineOptions}
                        />
                      )}
                    </Field>
                    <Field label={`How measured ${i + 1}`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={c.method}
                          onValueChange={(v) => setConsumptions((all) => all.map((x, j) => (j === i ? { ...x, method: v } : x)))}
                          placeholder="Choose"
                          options={METHODS}
                        />
                      )}
                    </Field>
                    <Field label={`${c.method === 'WEIGHED_CONSUMED' ? 'Used' : 'Left'} ${i + 1}${lineOf(c.line) ? ` (${lineOf(c.line)!.uomCode})` : ''}`}>
                      {(control) => (
                        <Input
                          {...control}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={c.qty}
                          onChange={(e) => setConsumptions((all) => all.map((x, j) => (j === i ? { ...x, qty: e.target.value.trim() } : x)))}
                        />
                      )}
                    </Field>
                    <Field label={`Evidence ${i + 1}`}>
                      {(control) => (
                        <Input
                          {...control}
                          maxLength={200}
                          placeholder="Scale ID, counter"
                          value={c.evidence}
                          onChange={(e) => setConsumptions((all) => all.map((x, j) => (j === i ? { ...x, evidence: e.target.value } : x)))}
                        />
                      )}
                    </Field>
                    <Button
                      variant="ghost"
                      aria-label={`Remove consumption line ${i + 1}`}
                      onClick={() => setConsumptions((all) => all.filter((_, j) => j !== i))}
                    >
                      <Trash2 aria-hidden="true" className="size-4" />
                    </Button>
                  </div>
                ))}
                <Button
                  size="sm"
                  className="mt-3"
                  disabled={r.atWorkCenter.length === 0}
                  onClick={() => setConsumptions((all) => [...all, { key: crypto.randomUUID(), line: '', method: '', qty: '', evidence: '' }])}
                >
                  <Plus aria-hidden="true" className="size-4" />
                  Add consumed material
                </Button>

                <h3 className="m-0 mt-5 text-[13.5px] font-semibold text-ink">Made</h3>
                <div className="mt-2 grid grid-cols-3 gap-3 max-md:grid-cols-1">
                  {qtyField('Good', good, setGood)}
                  {qtyField('NG rework', ngRework, setNgRework)}
                  {qtyField('NG reject', ngReject, setNgReject)}
                </div>

                <h3 className="m-0 mt-5 text-[13.5px] font-semibold text-ink">Scrap</h3>
                {scrap.map((s, i) => (
                  <div key={s.key} className="mt-3 grid grid-cols-[minmax(0,1fr)_120px_minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-3 max-lg:grid-cols-2">
                    <Field label={`Scrap item ${i + 1}`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={s.itemId}
                          onValueChange={(v) => setScrap((all) => all.map((x, j) => (j === i ? { ...x, itemId: v } : x)))}
                          placeholder={scrapItems.isPending ? 'Loading…' : 'Choose scrap item'}
                          options={(scrapItems.data ?? []).map((it) => ({ value: it.id, label: `${it.code} · ${it.name}` }))}
                        />
                      )}
                    </Field>
                    <Field label={`Weight ${i + 1}${scrapItem(s.itemId) ? ` (${scrapItem(s.itemId)!.baseUomCode})` : ''}`}>
                      {(control) => (
                        <Input
                          {...control}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={s.qty}
                          onChange={(e) => setScrap((all) => all.map((x, j) => (j === i ? { ...x, qty: e.target.value.trim() } : x)))}
                        />
                      )}
                    </Field>
                    <Field label={`Reason ${i + 1}`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={s.reasonCode}
                          onValueChange={(v) => setScrap((all) => all.map((x, j) => (j === i ? { ...x, reasonCode: v } : x)))}
                          placeholder="Choose"
                          options={SCRAP_REASONS}
                        />
                      )}
                    </Field>
                    <Field label={`Scrap note ${i + 1}`}>
                      {(control) => (
                        <Input
                          {...control}
                          maxLength={500}
                          value={s.note}
                          onChange={(e) => setScrap((all) => all.map((x, j) => (j === i ? { ...x, note: e.target.value } : x)))}
                        />
                      )}
                    </Field>
                    <Button variant="ghost" aria-label={`Remove scrap line ${i + 1}`} onClick={() => setScrap((all) => all.filter((_, j) => j !== i))}>
                      <Trash2 aria-hidden="true" className="size-4" />
                    </Button>
                  </div>
                ))}
                <Button
                  size="sm"
                  className="mt-3"
                  onClick={() => setScrap((all) => [...all, { key: crypto.randomUUID(), itemId: '', qty: '', reasonCode: '', note: '' }])}
                >
                  <Plus aria-hidden="true" className="size-4" />
                  Add scrap
                </Button>

                <div className="mt-5 grid grid-cols-2 gap-3 max-md:grid-cols-1">
                  {reversed.length > 0 ? (
                    <Field label="Corrects" hint="Only to replace a reversed posting of this run.">
                      {(control) => (
                        <Select
                          {...control}
                          value={corrects}
                          onValueChange={setCorrects}
                          emptyLabel="Not a correction"
                          placeholder="Not a correction"
                          options={reversed.map((p) => ({ value: p.id, label: `${p.code} · ${p.productionDate}` }))}
                        />
                      )}
                    </Field>
                  ) : null}
                  <Field label="Note" hint="Optional.">
                    {(control) => <Input {...control} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />}
                  </Field>
                </div>
                <div className="mt-4 flex justify-end">
                  <Button
                    variant="primary"
                    onClick={() => {
                      const why = check();
                      setProblem(why);
                      if (!why) setAsk('post');
                    }}
                  >
                    Post production
                  </Button>
                </div>
              </CardContent>
            </Card>
          ) : null}
          <Card>
            <CardHeader>
              <CardTitle>Postings</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable
                label={`Postings of ${r.code}`}
                columns={postingColumns}
                rows={postings.data ?? []}
                getRowId={(p) => p.id}
                rowLabel={(p) => `${p.code} ${p.goodQty} ${p.uomCode}`}
                status={postings.isError ? 'error' : postings.isPending ? 'loading' : 'ready'}
                sort={[]}
                onSortChange={() => undefined}
                density="compact"
                empty={<EmptyState title="Nothing posted yet" description="Post what was consumed, made and scrapped above." />}
              />
            </CardContent>
          </Card>
        </div>
        <Card>
          <CardHeader>
            <CardTitle>Run</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="m-0 grid grid-cols-1 gap-y-1 text-[13.5px]">
              {facts.map((f) => (
                <React.Fragment key={f.label}>
                  <dt className="mt-2 text-ink-2 first:mt-0">{f.label}</dt>
                  <dd className="m-0 text-ink">{f.value}</dd>
                </React.Fragment>
              ))}
            </dl>
          </CardContent>
        </Card>
      </div>

      <ConfirmDialog
        open={ask === 'post'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Post this production"
        description={`Consumes ${consumptions.length} measured line${consumptions.length === 1 ? '' : 's'} and records good ${good || 0}, NG ${Number(ngRework || 0) + Number(ngReject || 0)} ${r.uomCode}${scrap.length ? ` and ${scrap.length} scrap line${scrap.length === 1 ? '' : 's'}` : ''}. A posted production is corrected only by its reversal.`}
        confirmLabel="Post production"
        onConfirm={async () => {
          try {
            await post();
          } catch (e) {
            setProblem(refusal(e, 'The production could not be posted.'));
          }
          setAsk(null);
        }}
      />
      <ConfirmDialog
        open={ask === 'close'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Close this run"
        description="Nothing more is posted to it. Material left at the work center stays with the work order: return it or use it in a next run."
        confirmLabel="Close run"
        onConfirm={async () => {
          await act('close');
          setAsk(null);
          changed(`${r.code} closed`);
        }}
      />
      <ConfirmDialog
        open={ask === 'cancel'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Cancel this run"
        description="It has no posting. It stays in the list as Cancelled with your reason."
        confirmLabel="Cancel run"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          await act('cancel', { reason: why ?? '' });
          setAsk(null);
          changed(`${r.code} cancelled`);
        }}
      />
    </EnterprisePage>
  );
}

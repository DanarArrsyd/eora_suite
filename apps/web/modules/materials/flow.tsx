'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, DomainStatusBadge, EnterprisePage } from '@eora/features/patterns';
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
import { MasterEditor } from '@/modules/master/editor';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll, querySource } from '@/modules/master/source';
import type { FormValues, MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';
import { unitPrecisionError } from './resources';

/*
 * M4 step 3 (doc 33 §1.3, D-110, doc 04 rows 3 and 6): Materials → Material Issue and Material Return, Inventory →
 * Material at Production, on the live API. Materials issues a released work order's materials (several lots, one work
 * center of its routing) — material moves to the work center, nothing is consumed. Production asks for a return;
 * materials receives what it weighed or counted into a raw-material rack, or refuses it with the reason.
 */

type IssueRow = {
  id: string;
  code: string;
  version: number;
  status: 'POSTED' | 'REVERSED';
  plantCode: string;
  workOrderId: string;
  workOrderCode: string;
  itemCode: string;
  itemName: string;
  workCenterCode: string;
  lines: number;
  productionDate: string;
  note: string | null;
  postedByName: string | null;
  postedAt: string;
};

type ReturnStatus = 'SUBMITTED' | 'REJECTED' | 'POSTED' | 'REVERSED';

type ReturnRow = {
  id: string;
  code: string;
  docNo: string | null;
  version: number;
  status: ReturnStatus;
  plantId: string;
  plantCode: string;
  workOrderId: string;
  workOrderCode: string;
  workCenterCode: string;
  fromLocationCode: string;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  askedQty: string;
  receivedQty: string | null;
  differenceQty: string | null;
  uomCode: string;
  uomDecimals: number;
  toLocationCode: string | null;
  note: string | null;
  effectiveDate: string;
  requestedById: string;
  requestedByName: string | null;
  submittedAt: string;
  decidedByName: string | null;
  decidedAt: string | null;
  rejectReason: string | null;
};

type AtProductionRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  workCenterId: string;
  workCenterCode: string;
  locationCode: string;
  workOrderId: string;
  workOrderCode: string;
  workOrderStatus: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string | null;
  lotNo: string | null;
  qty: string;
  uomCode: string;
  uomDecimals: number;
  lastMovedAt: string;
};

type Posted = { data: { documentId: string; docNo: string; replayed: boolean } };
type Submitted = { data: { documentId: string; replayed: boolean } };

/** The first field message of a refused request, or its explanation. */
function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

const ISSUE_STATUS = [
  { value: 'POSTED', label: 'Posted' },
  { value: 'REVERSED', label: 'Reversed' },
];

export const MATERIAL_ISSUES: MasterResource<IssueRow> = {
  id: 'material-issue',
  resource: 'materials.issues',
  permission: { view: 'materials.issue.view', create: 'materials.issue.post', label: 'material issues' },
  title: 'Material Issue',
  description:
    'Material moved from the raw-material warehouse to a work center for a released work order. It stays there as Material at Production until it is used or returned; issuing it consumes nothing.',
  itemLabel: 'issues',
  singular: 'issue',
  createLabel: 'Issue material',
  createHref: '/materials/material-issue?new=1',
  searchPlaceholder: 'Search issue or work order…',
  rowLabel: (r) => `${r.code} ${r.workOrderCode} ${r.workCenterCode}`,
  openHref: (r) => `/ppic/work-orders?order=${r.workOrderId}`,
  defaultSort: [{ id: 'postedAt', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: ISSUE_STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'postedAt',
      header: 'Issued',
      lead: true,
      sortable: true,
      cell: (r) => when(r.postedAt),
      sub: (r) => <span className="font-mono">{r.code}</span>,
    },
    { id: 'workOrderCode', header: 'Work order', sortable: true, cell: (r) => <span className="font-mono">{r.workOrderCode}</span>, sub: (r) => r.itemCode },
    { id: 'workCenterCode', header: 'Work center', cell: (r) => r.workCenterCode, sub: (r) => r.plantCode },
    { id: 'lines', header: 'Lines', numeric: true, cell: (r) => r.lines },
    { id: 'postedByName', header: 'By', cell: (r) => r.postedByName ?? '—' },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Issues are made on their own page.')),
  update: () => Promise.reject(new Error('A posted issue is corrected by its reversal.')),
  readOnly: () => 'A posted issue is corrected by its reversal.',
};

/** Materials → Material Issue: the list, or the issue page with `?new=1` (optionally `&wo=<id>`). */
export function MaterialIssueScreen() {
  const params = useSearchParams();
  const wo = params?.get('wo') ?? '';
  return params?.get('new') || wo ? <IssuePage key={wo} initialWo={wo} /> : <MasterScreen config={MATERIAL_ISSUES} />;
}

type WorkOrder = {
  id: string;
  code: string;
  status: string;
  plantId: string;
  plantCode: string;
  itemCode: string;
  itemName: string;
  qty: string;
  uomCode: string;
  dueDate: string;
  materials: {
    lineNo: number;
    itemId: string;
    itemCode: string;
    itemName: string;
    uomCode: string;
    requiredQty: string;
    issuedQty: string;
    atProductionQty: string;
  }[];
  workCenters: { id: string; code: string; name: string; opSeq: number }[];
};

type StockLine = {
  id: string;
  locationId: string;
  locationCode: string;
  lotId: string | null;
  lotNo: string | null;
  availableQty: string;
  uomCode: string;
  uomDecimals: number;
};
type Line = { key: string; stockId: string; qty: string };

function IssuePage({ initialWo }: { initialWo: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canIssue = permissions.includes('materials.issue.post') && permissions.includes('materials.issue.create');
  const router = useRouter();
  const client = useQueryClient();
  const [woId, setWoId] = React.useState(initialWo);
  const [wcId, setWcId] = React.useState('');
  const [lines, setLines] = React.useState<Record<string, Line[]>>({});
  const [note, setNote] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [confirming, setConfirming] = React.useState(false);
  // one key per issue attempt: a retried post replays instead of issuing twice (ADR-0014)
  const key = React.useRef(`iss-${crypto.randomUUID()}`);

  const released = useQuery({
    queryKey: ['master', 'planning.work_orders', 'released'],
    queryFn: ({ signal }) =>
      queryAll<{ id: string; code: string; itemCode: string; qty: string; uomCode: string; dueDate: string }>(
        'planning.work_orders',
        { status: 'RELEASED' },
        signal,
      ),
    enabled: canIssue,
  });
  const order = useQuery({
    queryKey: ['master', 'planning.work_orders', 'one', woId],
    queryFn: async () => (await call(() => api.GET('/planning/work-orders/{order}', { params: { path: { order: woId } } }))) as unknown as { data: WorkOrder },
    enabled: canIssue && !!woId,
    retry: false,
  });
  const o = order.data?.data;
  // default to the first operation's work center (D-110)
  const workCenter = wcId || o?.workCenters[0]?.id || '';
  const stock = useQuery({
    queryKey: ['master', 'inventory.stock', 'issue', o?.id],
    queryFn: async ({ signal }) => {
      const rows = await queryAll<StockLine & { itemId: string }>(
        'inventory.stock',
        { plantId: o!.plantId, itemId: o!.materials.map((m) => m.itemId), locationType: 'RM_WAREHOUSE', stockStatus: 'UNRESTRICTED' },
        signal,
        'itemCode',
      );
      return rows.reduce<Record<string, StockLine[]>>((acc, r) => ({ ...acc, [r.itemId]: [...(acc[r.itemId] ?? []), r] }), {});
    },
    enabled: !!o && o.materials.length > 0,
  });

  if (me.isPending) return <LoadingState title="Loading" />;
  if (!canIssue) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Issuing material is not available for your roles"
          description="Ask a tenant administrator for a role that issues material."
        />
      </div>
    );
  }

  const stockOf = (itemId: string) => stock.data?.[itemId] ?? [];
  const setLine = (itemId: string, i: number, patch: Partial<Line>) =>
    setLines((all) => ({ ...all, [itemId]: (all[itemId] ?? []).map((l, j) => (j === i ? { ...l, ...patch } : l)) }));
  const addLine = (itemId: string) => setLines((all) => ({ ...all, [itemId]: [...(all[itemId] ?? []), { key: crypto.randomUUID(), stockId: '', qty: '' }] }));
  const removeLine = (itemId: string, i: number) => setLines((all) => ({ ...all, [itemId]: (all[itemId] ?? []).filter((_, j) => j !== i) }));

  const chosen = Object.entries(lines).flatMap(([itemId, ls]) => ls.map((l) => ({ itemId, ...l })));
  const check = (): string | null => {
    if (!o) return 'Choose a released work order.';
    if (!workCenter) return 'Choose the work center the material goes to.';
    if (chosen.length === 0) return 'Add at least one lot to issue.';
    const seen = new Set<string>();
    for (const l of chosen) {
      const m = o.materials.find((x) => x.itemId === l.itemId)!;
      const s = stockOf(l.itemId).find((x) => x.id === l.stockId);
      if (!s) return `Choose the rack and lot for each ${m.itemCode} line.`;
      if (seen.has(l.stockId)) return `${m.itemCode}: each rack and lot once; enter its quantity on one line.`;
      seen.add(l.stockId);
      if (!/^\d+(\.\d+)?$/.test(l.qty) || Number(l.qty) <= 0) return `${m.itemCode}: enter a quantity above zero.`;
      const precision = unitPrecisionError(l.qty, s.uomCode, s.uomDecimals);
      if (precision) return `${m.itemCode}: ${precision}`;
      if (Number(l.qty) > Number(s.availableQty))
        return `${m.itemCode}: only ${s.availableQty} ${s.uomCode} is available at ${s.locationCode}${s.lotNo ? `, lot ${s.lotNo}` : ''}.`;
    }
    return null;
  };
  const wc = o?.workCenters.find((w) => w.id === workCenter);

  const issue = async () => {
    const result = (await call(() =>
      api.POST('/inventory/material-issues', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: {
          workOrderId: o!.id,
          workCenterId: workCenter,
          note: note.trim() || null,
          lines: chosen.map((l) => {
            const s = stockOf(l.itemId).find((x) => x.id === l.stockId)!;
            return { itemId: l.itemId, lotId: s.lotId, fromLocationId: s.locationId, qty: l.qty };
          }),
        },
      }),
    )) as Posted;
    key.current = `iss-${crypto.randomUUID()}`;
    setLines({});
    setNote('');
    setNotice(
      result.data.replayed
        ? `${result.data.docNo} was already issued; nothing new was posted`
        : `${result.data.docNo} issued to ${wc?.code ?? 'the work center'}`,
    );
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  return (
    <EnterprisePage
      title="Issue material"
      description="Move a released work order’s materials from raw-material racks to one work center of its routing. Nothing is consumed until production posts it."
      actions={
        <Button onClick={() => router.push('/materials/material-issue')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All issues
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
        >
          The material is listed under Material at Production for this work order.
        </InlineAlert>
      ) : null}
      {problem ? (
        <InlineAlert tone="danger" title="Not issued" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <Card className="mb-4">
        <CardHeader>
          <CardTitle>Work order</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <Field label="Released work order" required hint={o ? `${o.itemCode} · ${o.itemName} · ${o.qty} ${o.uomCode}, due ${o.dueDate}` : undefined}>
              {(control) => (
                <Select
                  {...control}
                  value={woId}
                  onValueChange={(v) => {
                    setWoId(v);
                    setWcId('');
                    setLines({});
                    setProblem(null);
                  }}
                  placeholder={released.isPending ? 'Loading…' : 'Choose a work order'}
                  options={(released.data ?? []).map((w) => ({ value: w.id, label: `${w.code} · ${w.itemCode} · ${w.qty} ${w.uomCode}` }))}
                />
              )}
            </Field>
            <Field label="To work center" required hint="Work centers of the work order’s routing, first operation first.">
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
          </div>
          {woId && order.isError ? <p className="mt-3 text-[13px] text-stat-err-fg">The work order could not be loaded. Choose it again.</p> : null}
        </CardContent>
      </Card>

      {o ? (
        <Card>
          <CardHeader>
            <CardTitle>Materials of {o.code}</CardTitle>
          </CardHeader>
          <CardContent>
            {stock.isError ? <InlineAlert tone="danger" title="Stock could not be loaded" className="mb-3" /> : null}
            <ul className="m-0 flex list-none flex-col gap-4 p-0">
              {o.materials.map((m) => {
                const options = stockOf(m.itemId).map((s) => ({
                  value: s.id,
                  label: `${s.locationCode} · ${s.lotNo ?? 'no lot'} · ${s.availableQty} ${s.uomCode} available`,
                }));
                const own = lines[m.itemId] ?? [];
                return (
                  <li key={m.lineNo} className="rounded-md border border-line p-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                      <div>
                        <span className="font-mono text-[13.5px] font-semibold text-ink">{m.itemCode}</span>
                        <span className="ml-2 text-[13px] text-ink-2">{m.itemName}</span>
                      </div>
                      <dl className="m-0 flex flex-wrap gap-x-4 text-[13px] tabular-nums">
                        <div className="flex gap-1">
                          <dt className="text-ink-2">Required</dt>
                          <dd className="m-0 text-ink">{`${m.requiredQty} ${m.uomCode}`}</dd>
                        </div>
                        <div className="flex gap-1">
                          <dt className="text-ink-2">Issued</dt>
                          <dd className="m-0 text-ink">{`${m.issuedQty} ${m.uomCode}`}</dd>
                        </div>
                        <div className="flex gap-1">
                          <dt className="text-ink-2">At production</dt>
                          <dd className="m-0 text-ink">{`${m.atProductionQty} ${m.uomCode}`}</dd>
                        </div>
                      </dl>
                    </div>
                    {own.map((l, i) => (
                      <div key={l.key} className="mt-3 grid grid-cols-[minmax(0,1fr)_160px_auto] items-end gap-3 max-md:grid-cols-[minmax(0,1fr)_auto]">
                        <Field label={`Rack and lot ${i + 1}`} className="max-md:col-span-2">
                          {(control) => (
                            <Select
                              {...control}
                              aria-label={`${m.itemCode} rack and lot ${i + 1}`}
                              value={l.stockId}
                              onValueChange={(v) => setLine(m.itemId, i, { stockId: v })}
                              placeholder={options.length ? 'Choose rack and lot' : 'No UNRESTRICTED stock in a raw-material rack'}
                              disabled={options.length === 0}
                              options={options}
                            />
                          )}
                        </Field>
                        <Field label={`Quantity (${m.uomCode})`}>
                          {(control) => (
                            <Input
                              {...control}
                              aria-label={`${m.itemCode} quantity ${i + 1} (${m.uomCode})`}
                              inputMode="decimal"
                              value={l.qty}
                              onChange={(e) => setLine(m.itemId, i, { qty: e.target.value.trim() })}
                              className="tabular-nums"
                            />
                          )}
                        </Field>
                        <Button variant="ghost" aria-label={`Remove ${m.itemCode} line ${i + 1}`} onClick={() => removeLine(m.itemId, i)}>
                          <Trash2 aria-hidden="true" className="size-4" />
                        </Button>
                      </div>
                    ))}
                    <Button size="sm" className="mt-3" onClick={() => addLine(m.itemId)} disabled={stock.isPending}>
                      <Plus aria-hidden="true" className="size-4" />
                      {`Add ${m.itemCode} lot`}
                    </Button>
                  </li>
                );
              })}
            </ul>
            <Field label="Note" className="mt-4" hint="Optional, e.g. the shift or the reason for an over-issue.">
              {(control) => <Input {...control} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />}
            </Field>
            <div className="mt-4 flex justify-end">
              <Button
                variant="primary"
                onClick={() => {
                  const why = check();
                  setProblem(why);
                  if (!why) setConfirming(true);
                }}
              >
                Issue material
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : woId && order.isPending ? (
        <LoadingState title="Loading work order" />
      ) : (
        <EmptyState
          title="Choose a work order"
          description="Only released work orders take material. Their material lines come from the BOM copied at release."
        />
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title="Issue this material"
        description={`${chosen.length} line${chosen.length === 1 ? '' : 's'} to ${wc?.code ?? 'the work center'} for ${o?.code ?? 'the work order'}. A posted issue is corrected only by its reversal.`}
        confirmLabel="Issue material"
        onConfirm={async () => {
          try {
            await issue();
            setProblem(null);
          } catch (e) {
            setProblem(refusal(e, 'The material could not be issued.'));
          }
          setConfirming(false);
        }}
      />
    </EnterprisePage>
  );
}

const RETURN_STATUS = [
  { value: 'SUBMITTED', label: 'Waiting for receipt' },
  { value: 'POSTED', label: 'Received' },
  { value: 'REJECTED', label: 'Refused' },
  { value: 'REVERSED', label: 'Reversed' },
];
const returnLabel = (r: ReturnRow) => r.docNo ?? `${r.itemCode} return request`;
const BLANK_RETURN = { lineId: '', qty: '', note: '' };

async function atProductionLine(id: string): Promise<AtProductionRow | undefined> {
  return (await queryAll<AtProductionRow>('inventory.material_at_production', { id }, undefined, 'workOrderCode'))[0];
}

export const MATERIAL_RETURNS: MasterResource<ReturnRow> = {
  id: 'material-return',
  resource: 'materials.returns',
  permission: { view: 'materials.return.view', create: 'materials.return.submit', label: 'material returns' },
  title: 'Material Return',
  description:
    'Material at Production going back to the raw-material warehouse. Production asks for it; materials weighs or counts what comes back and receives it into a rack, or refuses it with the reason.',
  itemLabel: 'returns',
  singular: 'return',
  createLabel: 'Ask for a return',
  searchPlaceholder: 'Search return, work order or material…',
  rowLabel: (r) => `${returnLabel(r)} ${r.workOrderCode} ${r.itemCode} ${r.lotNo ?? 'no lot'} ${r.askedQty} ${r.uomCode}`,
  openHref: (r) => `/materials/material-return?request=${r.id}`,
  defaultSort: [{ id: 'submittedAt', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: RETURN_STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'submittedAt',
      header: 'Asked',
      lead: true,
      sortable: true,
      cell: (r) => when(r.submittedAt),
      sub: (r) => (r.docNo ? <span className="font-mono">{r.docNo}</span> : (r.requestedByName ?? undefined)),
    },
    {
      id: 'workOrderCode',
      header: 'Work order',
      sortable: true,
      cell: (r) => <span className="font-mono">{r.workOrderCode}</span>,
      sub: (r) => r.workCenterCode,
    },
    { id: 'itemCode', header: 'Material', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.lotNo ?? 'No lot' },
    { id: 'askedQty', header: 'Asked', numeric: true, cell: (r) => `${r.askedQty} ${r.uomCode}` },
    { id: 'receivedQty', header: 'Received', numeric: true, critical: true, cell: (r) => (r.receivedQty ? `${r.receivedQty} ${r.uomCode}` : '—') },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [
    {
      key: 'lineId',
      label: 'Material at Production',
      kind: 'select',
      required: true,
      options: 'materialAtProduction',
      hint: 'Work order, work center, material and lot, with what is there now.',
    },
    {
      key: 'qty',
      label: 'Quantity',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: 'What you send back, in the material’s unit. Materials weighs or counts it on receipt.',
    },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500, hint: 'Optional, e.g. coil end, job finished.' },
  ],
  blank: BLANK_RETURN,
  toForm: () => BLANK_RETURN,
  check: async (v): Promise<Record<string, string>> => {
    const line = v.lineId ? await atProductionLine(v.lineId) : undefined;
    if (!line) return { lineId: 'That material is no longer at the work center. Choose it again.' };
    const precision = v.qty ? unitPrecisionError(v.qty, line.uomCode, line.uomDecimals) : undefined;
    if (precision) return { qty: precision };
    if (v.qty && Number(v.qty) > Number(line.qty)) return { qty: `${line.workOrderCode} has ${line.qty} ${line.uomCode} of it at ${line.workCenterCode}.` };
    return {};
  },
  confirm: (v) => ({
    title: 'Ask for this return',
    description: `${v.qty ?? ''} goes back once materials receives it. Nothing moves until then.`,
    confirmLabel: 'Ask for return',
  }),
  savedMessage: (result) =>
    (result as Submitted).data.replayed ? 'This return was already asked; it is waiting for materials' : 'Return asked; it waits for materials to receive it',
  savedNote: 'It is listed as Waiting for receipt until materials receives or refuses it.',
  create: async (v, ctx) => {
    const line = await atProductionLine(v.lineId!);
    if (!line) throw new Error('That material is no longer at the work center. Choose it again.');
    return call(() =>
      api.POST('/inventory/material-returns', {
        params: { header: { 'Idempotency-Key': ctx.idempotencyKey } },
        body: { workOrderId: line.workOrderId, workCenterId: line.workCenterId, itemId: line.itemId, lotId: line.lotId, qty: v.qty!, note: v.note || null },
      }),
    );
  },
  update: () => Promise.reject(new Error('A return request is received or refused, never edited.')),
};

/** Materials → Material Return: the list, or one return's page with `?request=<id>`. */
export function MaterialReturnScreen() {
  const request = useSearchParams()?.get('request');
  return request ? <ReturnPage key={request} id={request} /> : <MasterScreen config={MATERIAL_RETURNS} />;
}

function ReturnPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('materials.return.view');
  const canReceive = permissions.includes('materials.return.post');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [receiving, setReceiving] = React.useState(false);
  const [refusing, setRefusing] = React.useState(false);

  const row = useQuery({
    queryKey: ['master', 'materials.returns', 'one', id],
    queryFn: async ({ signal }) =>
      (await querySource<ReturnRow>('materials.returns').list({ filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' }, signal)).rows[0] ??
      null,
    enabled: canView,
  });

  if (me.isPending || (canView && row.isPending)) return <LoadingState title="Loading return" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Material returns are not available for your roles"
          description="Ask a tenant administrator for a role that includes material returns."
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
            title="The return could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void row.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Return not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/materials/material-return')}>
                Back to returns
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const mine = me.data?.user.id === r.requestedById;
  const changed = (message: string) => {
    setNotice(message);
    void client.invalidateQueries({ queryKey: ['master'] });
  };
  const receiveConfig: MasterResource<ReturnRow> = {
    ...MATERIAL_RETURNS,
    fields: [
      {
        key: 'toLocationId',
        label: 'Into rack',
        kind: 'select',
        required: true,
        options: 'rmLocations',
        optionFilter: () => ({ plantId: r.plantId }),
        hint: 'A raw-material location of the plant.',
      },
      {
        key: 'qty',
        label: `Received quantity (${r.uomCode})`,
        kind: 'number',
        required: true,
        decimals: r.uomDecimals,
        positive: true,
        hint: `Weighed or counted. ${r.askedQty} ${r.uomCode} was asked; both are kept.`,
      },
    ],
    toForm: () => ({ toLocationId: '', qty: r.askedQty }),
    rowAction: { label: 'Receive', permission: 'materials.return.post', deniedReason: 'Your roles do not receive returns.' },
    drawerTitle: () => `Receive ${returnLabel(r)}`,
    check: async (v): Promise<Record<string, string>> => {
      const precision = v.qty ? unitPrecisionError(v.qty, r.uomCode, r.uomDecimals) : undefined;
      return precision ? { qty: precision } : {};
    },
    confirm: (v) => ({
      title: 'Receive this return',
      description: `${v.qty ?? ''} ${r.uomCode} of ${r.itemCode} goes back to the rack as UNRESTRICTED stock. A received return is corrected only by its reversal.`,
      confirmLabel: 'Receive',
    }),
    savedMessage: (result) => {
      const d = (result as Posted).data;
      return d.replayed ? `${d.docNo} was already received; nothing new was posted` : `${d.docNo} received`;
    },
    update: (_row: ReturnRow, v: FormValues, ctx) =>
      call(() =>
        api.POST('/inventory/material-returns/{return}/receive', {
          params: { path: { return: id }, header: { 'Idempotency-Key': ctx.idempotencyKey } },
          body: { toLocationId: v.toLocationId!, qty: v.qty! },
        }),
      ),
  };
  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Work order', value: <span className="font-mono">{r.workOrderCode}</span> },
    { label: 'From', value: `${r.workCenterCode} · ${r.fromLocationCode} · ${r.plantCode}` },
    { label: 'Material', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ?? 'No lot' },
    { label: 'Asked', value: <span className="tabular-nums">{`${r.askedQty} ${r.uomCode}`}</span> },
    ...(r.receivedQty
      ? [
          { label: 'Received', value: <span className="font-semibold tabular-nums">{`${r.receivedQty} ${r.uomCode} into ${r.toLocationCode}`}</span> },
          { label: 'Difference', value: <span className="tabular-nums">{`${r.differenceQty} ${r.uomCode}`}</span> },
        ]
      : []),
    { label: 'Note', value: r.note ?? '—' },
    { label: 'Asked by', value: `${r.requestedByName ?? '—'} · ${when(r.submittedAt)}` },
    ...(r.decidedAt ? [{ label: r.status === 'REJECTED' ? 'Refused by' : 'Received by', value: `${r.decidedByName ?? '—'} · ${when(r.decidedAt)}` }] : []),
    ...(r.status === 'REJECTED' ? [{ label: 'Refused because', value: r.rejectReason ?? '—' }] : []),
  ];

  return (
    <EnterprisePage
      title={returnLabel(r)}
      description={`${r.askedQty} ${r.uomCode} of ${r.itemCode} asked back from ${r.workCenterCode} for ${r.workOrderCode}.`}
      meta={<DomainStatusBadge domain="document" status={r.status} />}
      actions={
        <div className="flex flex-wrap gap-2">
          {r.status === 'SUBMITTED' && canReceive && !mine ? (
            <>
              <Button onClick={() => setRefusing(true)}>Refuse</Button>
              <Button variant="primary" onClick={() => setReceiving(true)}>
                Receive
              </Button>
            </>
          ) : null}
          <Button onClick={() => router.push('/materials/material-return')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All returns
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
      {r.status === 'SUBMITTED' && mine ? (
        <InlineAlert tone="info" title="You asked for this return" className="mb-4">
          Materials receives it with what it weighs or counts, or refuses it.
        </InlineAlert>
      ) : null}
      <Card>
        <CardHeader>
          <CardTitle>Return</CardTitle>
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
      <MasterEditor
        config={receiveConfig}
        row={r}
        canEdit={canReceive}
        open={receiving}
        onOpenChange={setReceiving}
        onSaved={(message) => {
          setReceiving(false);
          changed(message);
        }}
      />
      <ConfirmDialog
        open={refusing}
        onOpenChange={setRefusing}
        title="Refuse this return"
        description="Nothing moves. Production sees your reason and can ask again."
        confirmLabel="Refuse"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          try {
            await call(() => api.POST('/inventory/material-returns/{return}/refuse', { params: { path: { return: id } }, body: { reason: why ?? '' } }));
          } catch (e) {
            throw new Error(failureMessage(e, 'The return could not be refused.'));
          }
          setRefusing(false);
          changed(`${returnLabel(r)} refused`);
        }}
      />
    </EnterprisePage>
  );
}

export const MATERIAL_AT_PRODUCTION: MasterResource<AtProductionRow> = {
  id: 'material-at-production',
  resource: 'inventory.material_at_production',
  permission: {
    view: 'inventory.stock.view',
    create: 'materials.issue.post',
    label: 'Material at Production',
    alsoView: ['materials.issue.view', 'materials.return.view'],
  },
  noCreate: true,
  title: 'Material at Production',
  description:
    'Material issued to a work center for a work order and not yet used or returned. It is physical stock at the work center, kept per work order and lot.',
  itemLabel: 'lines',
  singular: 'line',
  searchPlaceholder: 'Search work order or material…',
  rowLabel: (r) => `${r.workOrderCode} ${r.workCenterCode} ${r.itemCode} ${r.lotNo ?? 'no lot'} ${r.qty} ${r.uomCode}`,
  openHref: (r) => `/ppic/work-orders?order=${r.workOrderId}`,
  defaultSort: [{ id: 'workCenterCode', desc: false }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'workCenterCode',
      header: 'Work center',
      lead: true,
      sortable: true,
      cell: (r) => r.workCenterCode,
      sub: (r) => `${r.plantCode} · ${r.locationCode}`,
    },
    {
      id: 'workOrderCode',
      header: 'Work order',
      sortable: true,
      cell: (r) => <span className="font-mono">{r.workOrderCode}</span>,
      sub: (r) => r.workOrderStatus.charAt(0) + r.workOrderStatus.slice(1).toLowerCase().replace(/_/g, ' '),
    },
    { id: 'itemCode', header: 'Material', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    { id: 'lotNo', header: 'Lot', sortable: true, cell: (r) => r.lotNo ?? 'No lot' },
    { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
    { id: 'lastMovedAt', header: 'Last moved', sortable: true, cell: (r) => when(r.lastMovedAt) },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Material comes here by an issue.')),
  update: () => Promise.reject(new Error('Material leaves by a return or a production posting.')),
  readOnly: () => 'Material leaves by a return or a production posting.',
};

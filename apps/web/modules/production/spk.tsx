'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, DataTable, EnterprisePage, type EoraColumn, type FilterField } from '@eora/features/patterns';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  DatePicker,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  InlineAlert,
  Input,
  LoadingState,
  PermissionDeniedState,
  Select,
  type IsoDate,
} from '@eora/ui';
import { formatDay, formatMoment, formatNumber } from '@/components/format';
import { Quantity } from '@/components/quantity';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryOptions } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';

/*
 * SPK per Line (doc 38, D-138), slice 1. PPIC → SPK Line: the planner drafts one SPK per line and production day, plans
 * released work-order operations on the line's machines, watches the load per machine against the line's hours per shift,
 * and releases it; a released SPK changes through a revision. Production → SPK Line: production reads the released SPKs.
 * The SPK never posts stock.
 */

type Mode = 'plan' | 'production';
const BASE: Record<Mode, string> = { plan: '/ppic/spk-line', production: '/production/spk-line' };
const STATUS: Record<string, { label: string; tone: 'neutral' | 'info' | 'success' | 'warning' }> = {
  DRAFT: { label: 'Draft', tone: 'neutral' },
  RELEASED: { label: 'Released', tone: 'info' },
  CLOSED: { label: 'Closed', tone: 'success' },
  CANCELLED: { label: 'Cancelled', tone: 'neutral' },
  SUPERSEDED: { label: 'Superseded', tone: 'warning' },
};
const statusBadge = (s: string) => <Badge tone={STATUS[s]?.tone ?? 'neutral'}>{STATUS[s]?.label ?? s}</Badge>;

type SpkRow = {
  id: string;
  code: string;
  version: number;
  status: string;
  plantId: string;
  plantCode: string;
  workCenterId: string;
  workCenterCode: string;
  workCenterName: string;
  productionDate: string;
  revision: number;
  previousId: string | null;
  reference: string | null;
  note: string | null;
  revisionReason: string | null;
  lineCount: number;
  releasedAt: string | null;
  releasedByName: string | null;
};
type SpkLine = {
  id: string;
  lineNo: number;
  workOrderId: string;
  workOrderCode: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  dueDate: string;
  routingOperationId: string;
  opSeq: number;
  opCount: number;
  processName: string;
  machineId: string;
  machineCode: string;
  machineName: string;
  shiftId: string | null;
  shiftCode: string | null;
  plannedQty: string;
  priority: number;
  setupMinutes: string;
  qcSampleMinutes: string;
  note: string | null;
  sph: string | null;
  hours: string | null;
};
type MachineLoad = {
  machineId: string;
  machineCode: string;
  machineName: string;
  hours: string;
  notCalculableLines: number;
  capacityHours: string | null;
  balanceHours: string | null;
  overloaded: boolean;
  /** One entry per shift of the machine (lines without a shift share one); the machine is overloaded when one shift is. */
  shifts: { shiftId: string | null; shiftCode: string | null; hours: string; capacityHours: string | null; balanceHours: string | null; overloaded: boolean }[];
};
type SpkDetail = SpkRow & {
  lines: SpkLine[];
  machineLoad: MachineLoad[];
  hoursPerShift: string | null;
  revisions: { id: string; revision: number; status: string; reason: string | null }[];
};
type Operation = {
  workOrderId: string;
  workOrderCode: string;
  itemCode: string;
  itemName: string;
  qty: string;
  uomCode: string;
  dueDate: string;
  operationId: string;
  opSeq: number;
  processName: string;
  setupMinutes: string;
  sph: string | null;
};
type Options = { operations: Operation[]; machines: { id: string; code: string; name: string }[]; shifts: { id: string; code: string; name: string }[] };
type Draft = {
  key: string;
  operation: string;
  machineId: string;
  shiftId: string;
  plannedQty: string;
  priority: string;
  setupMinutes: string;
  qcSampleMinutes: string;
  note: string;
};
/** One select value for a WO operation: the WO and its routing operation. */
const opKey = (wo: string, op: string) => `${wo}|${op}`;

const FILTERS: FilterField[] = [
  PLANT_FILTER,
  {
    key: 'workCenterId',
    type: 'entity',
    entity: 'machine',
    label: 'Line',
    loadOptions: (_deps, signal) => queryOptions('core.work_centers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
  },
  { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: Object.entries(STATUS).map(([value, s]) => ({ value, label: s.label })) },
];
// production reads released and closed SPKs only: its Status filter offers just those and narrows within them
const PRODUCTION_STATUSES = ['RELEASED', 'CLOSED'];
const PRODUCTION_FILTERS: FilterField[] = FILTERS.map((f) =>
  f.key === 'status' && f.type === 'multiselect' && f.source === 'static'
    ? { ...f, options: f.options.filter((o) => PRODUCTION_STATUSES.includes(o.value)) }
    : f,
);
const productionStatus = (filters: Record<string, string | string[]>) => {
  const picked = ([] as string[]).concat(filters.status ?? []).filter((s) => PRODUCTION_STATUSES.includes(s));
  return { ...filters, status: picked.length ? picked : PRODUCTION_STATUSES };
};

function listConfig(mode: Mode): MasterResource<SpkRow> {
  return {
    id: mode === 'plan' ? 'spk-line' : 'production-spk-line',
    resource: 'production.spk',
    permission: { view: 'production.spk.view', create: 'production.spk.create', label: 'SPK planning' },
    noCreate: mode === 'production',
    createLabel: 'New SPK',
    createHref: `${BASE.plan}?new=1`,
    title: 'SPK Line',
    description: mode === 'plan' ? 'Daily production plan per line, released to production.' : 'The plan per line and day released by PPIC.',
    itemLabel: 'SPKs',
    singular: 'SPK',
    searchPlaceholder: 'Search SPK number or line…',
    rowLabel: (r) => r.code ?? `Draft ${r.workCenterCode} ${r.productionDate}`,
    defaultSort: [{ id: 'productionDate', desc: true }],
    filters: mode === 'production' ? PRODUCTION_FILTERS : FILTERS,
    filterPermissions: { plantId: 'master.org.view', workCenterId: 'master.org.view' },
    ...(mode === 'production' ? { mapFilters: productionStatus } : {}),
    columns: [
      {
        id: 'code',
        header: 'SPK',
        lead: true,
        sortable: true,
        cell: (r) => <span className="font-mono">{r.code ?? 'Draft'}</span>,
        sub: (r) => (r.revision > 1 ? `Revision ${r.revision}` : undefined),
      },
      { id: 'workCenterCode', header: 'Line', sortable: true, cell: (r) => r.workCenterCode, sub: (r) => r.workCenterName },
      { id: 'productionDate', header: 'Production date', sortable: true, cell: (r) => formatDay(r.productionDate) },
      { id: 'status', header: 'Status', sortable: true, critical: true, cell: (r) => statusBadge(r.status) },
      { id: 'lineCount', header: 'Lines', numeric: true, cell: (r) => String(r.lineCount) },
      {
        id: 'releasedAt',
        header: 'Released',
        sortable: true,
        cell: (r) => (r.releasedAt ? formatMoment(r.releasedAt) : '—'),
        sub: (r) => r.releasedByName ?? undefined,
      },
    ],
    openHref: (r) => `${BASE[mode]}?spk=${r.id}`,
    fields: [],
    blank: {},
    toForm: () => ({}),
    create: () => Promise.reject(new Error('An SPK is created on its own page.')),
    update: () => Promise.reject(new Error('An SPK is edited on its own page.')),
  };
}

/** PPIC → SPK Line (`mode="plan"`) and Production → SPK Line (`mode="production"`). */
export function SpkLineScreen({ mode }: { mode: Mode }) {
  const params = useSearchParams();
  const spk = params?.get('spk');
  if (spk) return <SpkPage key={spk} id={spk} mode={mode} />;
  if (mode === 'plan' && params?.get('new')) return <NewSpk />;
  return <MasterScreen config={listConfig(mode)} />;
}

function NewSpk() {
  const me = useMe();
  const router = useRouter();
  const permissions = me.data?.permissions ?? [];
  const plant = me.data?.activePlantId || me.data?.plants[0]?.id || '';
  const tenant = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId);
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: tenant?.timezone ?? 'Asia/Jakarta' }).format(new Date());
  const [line, setLine] = React.useState('');
  const [date, setDate] = React.useState(today);
  const [reference, setReference] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const lines = useQuery({
    queryKey: ['spk', 'lines', plant],
    queryFn: ({ signal }) => queryOptions('core.work_centers', (r) => `${String(r.code)} · ${String(r.name)}`, { plantId: plant, status: 'ACTIVE' }, signal),
    enabled: !!plant,
  });
  if (me.isPending) return <LoadingState title="Loading" />;
  if (!permissions.includes('production.spk.create')) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Planning an SPK is not available for your roles" description="Ask a tenant administrator for the planner role." />
      </div>
    );
  }
  const create = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setProblem(null);
    try {
      const res = (await call(() =>
        api.POST('/production/spk', { body: { plantId: plant, workCenterId: line, productionDate: date, reference: reference || null } }),
      )) as unknown as { data: SpkRow };
      router.replace(`${BASE.plan}?spk=${res.data.id}`);
    } catch (error) {
      setProblem(failureMessage(error));
      setBusy(false);
    }
  };
  return (
    <EnterprisePage title="New SPK" description="Generate the day's SPKs from Daily Recovery, or start an empty SPK for one line.">
      <GenerateSpk plant={plant} today={today} canGenerate={permissions.includes('planning.wo.create') && permissions.includes('planning.wo.submit')} />
      <Card className="max-w-xl">
        <CardHeader>
          <CardTitle>Empty SPK for one line</CardTitle>
        </CardHeader>
        <CardContent>
          <form onSubmit={create} className="flex flex-col gap-4">
            <Field label="Line" required>
              {(c) => (
                <Select {...c} value={line} onValueChange={setLine} options={lines.data ?? []} placeholder={lines.isPending ? 'Loading…' : 'Choose a line'} />
              )}
            </Field>
            <Field label="Production date" required>
              {(c) => <DatePicker {...c} value={date as IsoDate} min={today as IsoDate} label="Production date" onChange={(v) => v && setDate(v)} />}
            </Field>
            <Field label="Factory reference" hint="Optional, e.g. the number on the paper form.">
              {(c) => <Input {...c} value={reference} maxLength={60} onChange={(e) => setReference(e.target.value)} />}
            </Field>
            {problem ? (
              <InlineAlert tone="danger" title="SPK not created">
                {problem}
              </InlineAlert>
            ) : null}
            <div className="flex gap-2">
              <Button type="submit" variant="primary" disabled={busy || !line}>
                Create SPK
              </Button>
              <Button type="button" onClick={() => router.push(BASE.plan)}>
                Cancel
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </EnterprisePage>
  );
}

type Generated = {
  productionDate: string;
  spks: { id: string; workCenterCode: string; created: boolean; linesAdded: number }[];
  workOrders: { id: string; code: string; itemCode: string; itemName: string; customerCode: string; qty: string }[];
  overtime: {
    machineCode: string;
    workCenterCode: string;
    shiftCode: string | null;
    hours: string;
    qty: string;
    workOrderCode: string;
    itemCode: string;
    customerCode: string | null;
    opSeq: number;
    overLimit: boolean;
    limitHours: string | null;
  }[];
  materialShort: { itemCode: string; itemName: string; customerCode: string; needQty: string; plannedQty: string; shortQty: string }[];
  skipped: { itemCode?: string; workOrderCode?: string; customerCode?: string; opSeq?: number; reason: string }[];
};

/** Generate SPK (doc 38 §12, D-139): the day's draft SPKs from the Daily Recovery shortfall, with what it could not plan. */
function GenerateSpk({ plant, today, canGenerate }: { plant: string; today: string; canGenerate: boolean }) {
  const client = useQueryClient();
  const [date, setDate] = React.useState(today);
  const [ask, setAsk] = React.useState(false);
  const [result, setResult] = React.useState<Generated | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);
  const run = async () => {
    setProblem(null);
    try {
      const res = (await call(() => api.POST('/production/spk/generate', { body: { plantId: plant, productionDate: date } }))) as unknown as {
        data: Generated;
      };
      setResult(res.data);
      void client.invalidateQueries({ queryKey: ['master'] });
    } catch (error) {
      setProblem(failureMessage(error));
    } finally {
      setAsk(false);
    }
  };
  const nothing =
    result && !result.spks.length && !result.workOrders.length && !result.overtime.length && !result.materialShort.length && !result.skipped.length;
  return (
    <Card className="mb-4 max-w-3xl">
      <CardHeader>
        <CardTitle>Generate SPK</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <p className="m-0 text-[13px] text-ink-2">
          Plans the Daily Recovery shortfall plus safety stock for the day: creates and releases the work orders it needs, then fills each line&apos;s draft SPK
          over the preferred and alternative machines. Hours that fit nowhere are proposed as overtime. Production sees nothing until you release each SPK.
        </p>
        {!canGenerate ? (
          <InlineAlert tone="info" title="Generating needs the work order planner role">
            Ask a tenant administrator for permission to create and submit work orders at this plant.
          </InlineAlert>
        ) : (
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Production date" required>
              {(c) => <DatePicker {...c} value={date as IsoDate} min={today as IsoDate} label="Production date" onChange={(v) => v && setDate(v)} />}
            </Field>
            <Button variant="primary" onClick={() => setAsk(true)} disabled={!plant}>
              Generate SPK
            </Button>
          </div>
        )}
        {problem ? (
          <InlineAlert tone="danger" title="SPK not generated">
            {problem}
          </InlineAlert>
        ) : null}
        {nothing ? (
          <InlineAlert tone="info" title="Nothing to plan">
            Every need of {formatDay(result.productionDate)} is covered and every released work order is already on an SPK.
          </InlineAlert>
        ) : null}
        {result && !nothing ? (
          <div className="flex flex-col gap-4 text-[13px]" aria-label="Generate result">
            {result.spks.length ? (
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Draft SPKs for {formatDay(result.productionDate)}</h3>
                <ul className="m-0 list-none p-0">
                  {result.spks.map((x) => (
                    <li key={x.id}>
                      <a className="text-accent underline" href={`${BASE.plan}?spk=${x.id}`}>
                        {x.workCenterCode}
                      </a>{' '}
                      · {x.linesAdded} line{x.linesAdded === 1 ? '' : 's'} added{x.created ? ' (new draft)' : ''}
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
            {result.workOrders.length ? (
              <section>
                <h3 className="m-0 mb-1 text-sm font-semibold">Work orders created and released</h3>
                <ul className="m-0 list-none p-0">
                  {result.workOrders.map((w) => (
                    <li key={w.id}>
                      <span className="font-mono">{w.code}</span> · {w.itemCode} · {w.customerCode} · {formatNumber(w.qty, 'PCS')}
                    </li>
                  ))}
                </ul>
              </section>
            ) : null}
            {result.overtime.length ? (
              <InlineAlert tone="warning" title="Proposed overtime">
                <ul className="m-0 list-none p-0">
                  {result.overtime.map((o, i) => (
                    <li key={i}>
                      {o.workCenterCode} {o.machineCode}
                      {o.shiftCode ? ` (${o.shiftCode})` : ''}: +{o.hours} h for {o.workOrderCode} · {o.itemCode}
                      {o.customerCode ? ` · ${o.customerCode}` : ''} · op {o.opSeq}
                      {o.overLimit ? ` — over the plant limit of ${o.limitHours} h` : ''}
                    </li>
                  ))}
                </ul>
              </InlineAlert>
            ) : null}
            {result.materialShort.length ? (
              <InlineAlert tone="warning" title="Material short">
                <ul className="m-0 list-none p-0">
                  {result.materialShort.map((m, i) => (
                    <li key={i}>
                      {m.itemCode} · {m.customerCode}: needs {formatNumber(m.needQty, 'PCS')}, planned {formatNumber(m.plannedQty, 'PCS')}, short{' '}
                      {formatNumber(m.shortQty, 'PCS')}
                    </li>
                  ))}
                </ul>
              </InlineAlert>
            ) : null}
            {result.skipped.length ? (
              <InlineAlert tone="info" title="Not planned">
                <ul className="m-0 list-none p-0">
                  {result.skipped.map((x, i) => (
                    <li key={i}>
                      {[x.workOrderCode, x.itemCode, x.customerCode, x.opSeq ? `op ${x.opSeq}` : null].filter(Boolean).join(' · ')}: {x.reason}
                    </li>
                  ))}
                </ul>
              </InlineAlert>
            ) : null}
          </div>
        ) : null}
      </CardContent>
      <ConfirmDialog
        open={ask}
        onOpenChange={setAsk}
        title="Generate SPK"
        description={`Work orders the plan needs for ${formatDay(date)} are created and released in your name, and lines are added to each line's draft SPK. Nothing is released to production.`}
        confirmLabel="Generate"
        onConfirm={run}
      />
    </Card>
  );
}

function SpkPage({ id, mode }: { id: string; mode: Mode }) {
  const me = useMe();
  const router = useRouter();
  const pathname = usePathname();
  const client = useQueryClient();
  const permissions = me.data?.permissions ?? [];
  const may = (p: string) => permissions.includes(p);
  const detail = useQuery({
    queryKey: ['spk', id],
    queryFn: async ({ signal }) =>
      (await call(() => api.GET('/production/spk/{spk}', { params: { path: { spk: id } }, signal }))) as unknown as { data: SpkDetail },
    retry: false,
  });
  const s = detail.data?.data;
  const editable = mode === 'plan' && s?.status === 'DRAFT' && may('production.spk.create');
  const options = useQuery({
    queryKey: ['spk', id, 'options'],
    queryFn: async ({ signal }) =>
      (await call(() => api.GET('/production/spk/{spk}/options', { params: { path: { spk: id } }, signal }))) as unknown as { data: Options },
    enabled: editable,
  });
  const [ask, setAsk] = React.useState<'release' | 'cancel' | 'revise' | 'close' | null>(null);
  // unsaved line edits: releasing would send the saved plan, not the one on screen (Codex on #97)
  const [dirty, setDirty] = React.useState(false);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);

  if (me.isPending || detail.isPending) return <LoadingState title="Loading SPK" />;
  if (detail.isError || !s) {
    return (
      <div className="p-6 max-md:p-4">
        <ErrorState
          title="SPK not found"
          description={failureMessage(detail.error)}
          action={
            <Button size="sm" onClick={() => detail.refetch()}>
              Try again
            </Button>
          }
        />
      </div>
    );
  }
  const refresh = async () => {
    await client.invalidateQueries({ queryKey: ['spk', id] });
    await client.invalidateQueries({ queryKey: ['master', 'production.spk'] });
  };
  const act = async (action: 'release' | 'cancel' | 'close' | 'revise', reason?: string) => {
    setProblem(null);
    const res = (await call(() =>
      api.POST(`/production/spk/{spk}/actions/${action}` as '/production/spk/{spk}/actions/release', {
        params: { path: { spk: id } },
        headers: { 'If-Match': `"${s.version}"` },
        ...(reason ? { body: { reason } as never } : {}),
      }),
    )) as unknown as { data: SpkDetail };
    await refresh();
    return res.data;
  };
  const title = s.code ? `${s.code}${s.revision > 1 ? ` rev ${s.revision}` : ''}` : `Draft SPK${s.revision > 1 ? ` rev ${s.revision}` : ''}`;
  const actions = mode === 'plan' && (
    <div className="flex flex-wrap gap-2">
      {s.status === 'DRAFT' && may('production.spk.release') ? (
        <Button
          variant="primary"
          onClick={() => setAsk('release')}
          disabled={!s.lines.length || dirty}
          title={dirty ? 'Save the SPK before releasing it' : undefined}
        >
          Release to production
        </Button>
      ) : null}
      {s.status === 'DRAFT' && may('production.spk.create') ? <Button onClick={() => setAsk('cancel')}>Cancel SPK</Button> : null}
      {s.status === 'RELEASED' && may('production.spk.create') ? <Button onClick={() => setAsk('revise')}>Revise</Button> : null}
      {s.status === 'RELEASED' && may('production.spk.close') ? <Button onClick={() => setAsk('close')}>Close SPK</Button> : null}
    </div>
  );
  return (
    <EnterprisePage title={title} description={`${s.workCenterCode} · ${s.workCenterName} · ${formatDay(s.productionDate)}`} actions={actions || undefined}>
      <div className="mb-4">
        <Button variant="ghost" size="sm" onClick={() => router.push(pathname ?? BASE[mode])}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All SPKs
        </Button>
      </div>
      {notice ? <InlineAlert tone="success" title={notice} className="mb-4" /> : null}
      {dirty ? (
        <InlineAlert tone="info" title="Unsaved changes" className="mb-4">
          Save the SPK to release it; release sends the saved plan.
        </InlineAlert>
      ) : null}
      {problem ? (
        <InlineAlert tone="danger" title="Not done" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      {s.status === 'SUPERSEDED' ? (
        <InlineAlert tone="warning" title="This SPK was replaced by a revision" className="mb-4">
          {s.revisions
            .filter((r) => r.revision > s.revision)
            .map((r) => (
              <Button key={r.id} variant="link" onClick={() => router.push(`${BASE[mode]}?spk=${r.id}`)}>
                Open revision {r.revision}
              </Button>
            ))}
        </InlineAlert>
      ) : null}
      <dl className="mb-6 grid grid-cols-2 gap-x-6 gap-y-3 text-[13.5px] md:grid-cols-4">
        <Fact label="Status" value={statusBadge(s.status)} />
        <Fact label="Line" value={`${s.workCenterCode} · ${s.workCenterName}`} />
        <Fact label="Production date" value={formatDay(s.productionDate)} />
        <Fact label="Hours per shift" value={s.hoursPerShift ? `${s.hoursPerShift} h` : 'Not configured'} />
        {s.reference ? <Fact label="Factory reference" value={s.reference} /> : null}
        {s.releasedAt ? <Fact label="Released" value={`${formatMoment(s.releasedAt)}${s.releasedByName ? ` by ${s.releasedByName}` : ''}`} /> : null}
        {s.revisionReason ? <Fact label="Why revised" value={s.revisionReason} /> : null}
      </dl>
      <MachineLoadPanel load={s.machineLoad} />
      {editable ? (
        <LinesEditor
          key={`${s.id}-${s.version}`}
          spk={s}
          options={options.data?.data}
          loading={options.isPending}
          onSaved={async () => {
            await refresh();
            setNotice('SPK saved');
          }}
          onDirtyChange={setDirty}
        />
      ) : (
        <LinesTable spk={s} />
      )}
      <ConfirmDialog
        open={ask === 'release'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Release to production"
        description={`Production of ${s.workCenterCode} sees this SPK and is told. Changes after release make a revision.`}
        confirmLabel="Release"
        onConfirm={async () => {
          const r = await act('release');
          setAsk(null);
          setNotice(`${r.code} released to production`);
        }}
      />
      <ConfirmDialog
        open={ask === 'cancel'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Cancel this SPK"
        description={s.previousId ? 'The released SPK it would replace stays in force.' : 'The draft is kept as cancelled and cannot be released.'}
        confirmLabel="Cancel SPK"
        tone="danger"
        onConfirm={async () => {
          await act('cancel');
          setAsk(null);
          setNotice('SPK cancelled');
        }}
      />
      <ConfirmDialog
        open={ask === 'revise'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Revise the released SPK"
        description="A draft revision with the same lines opens. The released SPK stays in force until you release the revision; production is told then."
        confirmLabel="Draft revision"
        reason={{ label: 'Why it changes', minLength: 3 }}
        onConfirm={async (reason) => {
          const r = await act('revise', reason);
          setAsk(null);
          router.push(`${BASE.plan}?spk=${r.id}`);
        }}
      />
      <ConfirmDialog
        open={ask === 'close'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Close this SPK"
        description="The day's plan is finished. A closed SPK cannot be revised."
        confirmLabel="Close SPK"
        onConfirm={async () => {
          await act('close');
          setAsk(null);
          setNotice('SPK closed');
        }}
      />
    </EnterprisePage>
  );
}

function Fact({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <dt className="text-ink-2">{label}</dt>
      <dd className="m-0 font-medium text-ink">{value}</dd>
    </div>
  );
}

/** Hours planned per machine against the line's hours per shift (doc 38 §3): over capacity warns, never blocks. */
function MachineLoadPanel({ load }: { load: MachineLoad[] }) {
  if (!load.length) return null;
  return (
    <Card className="mb-6">
      <CardHeader>
        <CardTitle>Machine load</CardTitle>
      </CardHeader>
      <CardContent>
        <ul className="m-0 flex list-none flex-col gap-3 p-0" aria-label="Machine load">
          {load.map((m) => {
            const capacity = m.capacityHours ? Number(m.capacityHours) : null;
            const pct = capacity ? Math.min(100, (Number(m.hours) / capacity) * 100) : 0;
            // the day's balance keeps its own sign; an over-full shift is named on its own, so spare hours of another shift never read as excess
            const overShifts = m.shifts.filter((x) => x.overloaded && x.balanceHours);
            const dayBalance = m.balanceHours === null ? null : Number(m.balanceHours);
            return (
              <li key={m.machineId} className="grid grid-cols-[160px_1fr_auto] items-center gap-3 text-[13px] max-md:grid-cols-1">
                <span className="font-medium">
                  {m.machineCode} <span className="text-ink-2">· {m.machineName}</span>
                </span>
                <span className="h-2.5 overflow-hidden rounded-full bg-line" aria-hidden="true">
                  <span className={`block h-full ${m.overloaded ? 'bg-stat-err' : 'bg-accent'}`} style={{ width: `${capacity ? pct : 0}%` }} />
                </span>
                <span className={`tabular-nums ${m.overloaded ? 'font-semibold text-stat-err-fg' : 'text-ink'}`}>
                  {m.hours} h{capacity ? ` of ${m.capacityHours} h` : ''}
                  {dayBalance !== null
                    ? dayBalance < 0
                      ? ` · ${String(Math.abs(dayBalance))} h over`
                      : ` · ${m.balanceHours} h left`
                    : ' · hours per shift not configured'}
                  {dayBalance !== null && dayBalance >= 0 && overShifts.length
                    ? ` · ${overShifts.map((x) => `${x.shiftCode ?? 'No shift'} ${String(Math.abs(Number(x.balanceHours)))} h over`).join(', ')}`
                    : ''}
                  {m.notCalculableLines ? ` · ${m.notCalculableLines} line${m.notCalculableLines === 1 ? '' : 's'} without SPH` : ''}
                </span>
                {m.shifts.length > 1 || m.shifts.some((x) => x.shiftCode) ? (
                  <ul
                    className="col-span-3 m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-xs max-md:col-span-1"
                    aria-label={`${m.machineCode} per shift`}
                  >
                    {m.shifts.map((x) => (
                      <li key={x.shiftId ?? 'none'} className={x.overloaded ? 'font-semibold text-stat-err-fg' : 'text-ink-2'}>
                        {x.shiftCode ?? 'No shift'}: {x.hours} h{x.capacityHours ? ` of ${x.capacityHours} h` : ''}
                        {x.overloaded && x.balanceHours ? ` · ${String(Math.abs(Number(x.balanceHours)))} h over` : ''}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </li>
            );
          })}
        </ul>
      </CardContent>
    </Card>
  );
}

const LINE_COLUMNS: EoraColumn<SpkLine>[] = [
  { id: 'priority', header: 'Priority', numeric: true, cell: (l) => String(l.priority), minWidth: 72 },
  {
    id: 'workOrderCode',
    header: 'Work order',
    lead: true,
    cell: (l) => <span className="font-mono">{l.workOrderCode}</span>,
    sub: (l) => `${l.itemCode} · ${l.itemName}`,
  },
  { id: 'processName', header: 'Process', cell: (l) => l.processName, sub: (l) => `Op ${l.opSeq} · ${l.opCount} operations in the routing` },
  { id: 'machineCode', header: 'Machine', cell: (l) => l.machineCode, sub: (l) => l.machineName },
  { id: 'shiftCode', header: 'Shift', cell: (l) => l.shiftCode ?? '—' },
  { id: 'plannedQty', header: 'Planned', numeric: true, critical: true, cell: (l) => <Quantity value={l.plannedQty} uom={l.uomCode} /> },
  { id: 'sph', header: 'SPH', numeric: true, cell: (l) => l.sph ?? 'Not calculable' },
  {
    id: 'hours',
    header: 'Hours',
    numeric: true,
    critical: true,
    cell: (l) => (l.hours ? `${l.hours} h` : 'Not calculable'),
    sub: (l) => `Setup ${l.setupMinutes} min · QC ${l.qcSampleMinutes} min`,
  },
  // the planner's instruction for this line: production reads it on the released SPK
  { id: 'note', header: 'Note', cell: (l) => l.note ?? '—', minWidth: 160 },
];

function LinesTable({ spk }: { spk: SpkDetail }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Lines ({spk.lines.length})</CardTitle>
      </CardHeader>
      <CardContent>
        <DataTable
          label={`Lines of ${spk.code ?? 'the draft SPK'}`}
          columns={LINE_COLUMNS}
          rows={spk.lines}
          getRowId={(l) => l.id}
          rowLabel={(l) => `${l.workOrderCode} ${l.processName} ${l.machineCode}`}
          status="ready"
          sort={[]}
          onSortChange={() => undefined}
          density="comfortable"
          empty={<EmptyState title="No lines" description="The planner has not planned any work on this line yet." />}
        />
      </CardContent>
    </Card>
  );
}

function LinesEditor({
  spk,
  options,
  loading,
  onSaved,
  onDirtyChange,
}: {
  spk: SpkDetail;
  options?: Options;
  loading: boolean;
  onSaved: () => Promise<void>;
  onDirtyChange: (dirty: boolean) => void;
}) {
  const blank = (): Draft => ({
    key: crypto.randomUUID(),
    operation: '',
    machineId: '',
    shiftId: '',
    plannedQty: '',
    priority: '1',
    setupMinutes: '',
    qcSampleMinutes: '5',
    note: '',
  });
  const [rows, setRows] = React.useState<Draft[]>(() =>
    spk.lines.length
      ? spk.lines.map((l) => ({
          key: l.id,
          operation: opKey(l.workOrderId, l.routingOperationId),
          machineId: l.machineId,
          shiftId: l.shiftId ?? '',
          plannedQty: l.plannedQty,
          priority: String(l.priority),
          setupMinutes: l.setupMinutes,
          qcSampleMinutes: l.qcSampleMinutes,
          note: l.note ?? '',
        }))
      : [blank()],
  );
  const [busy, setBusy] = React.useState(false);
  const [problem, setProblem] = React.useState<string | null>(null);
  const operations = options?.operations ?? [];
  const opOptions = operations.map((o) => ({
    value: opKey(o.workOrderId, o.operationId),
    label: `${o.workOrderCode} · ${o.itemCode} · ${o.processName} (op ${o.opSeq})`,
    description: `${o.itemName} · due ${formatDay(o.dueDate)} · ${o.sph ? `${o.sph} pcs/h` : 'no SPH'}`,
  }));
  const machineOptions = (options?.machines ?? []).map((m) => ({ value: m.id, label: `${m.code} · ${m.name}` }));
  const shiftOptions = (options?.shifts ?? []).map((s) => ({ value: s.id, label: `${s.code} · ${s.name}` }));
  const edit = (change: (all: Draft[]) => Draft[]) => {
    onDirtyChange(true);
    setRows(change);
  };
  const set = (i: number, patch: Partial<Draft>) => edit((all) => all.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const save = async () => {
    setBusy(true);
    setProblem(null);
    try {
      await call(() =>
        api.PUT('/production/spk/{spk}', {
          params: { path: { spk: spk.id } },
          headers: { 'If-Match': `"${spk.version}"` },
          body: {
            reference: spk.reference,
            note: spk.note,
            lines: rows
              .filter((r) => r.operation)
              .map((r) => {
                const [workOrderId, routingOperationId] = r.operation.split('|') as [string, string];
                return {
                  workOrderId,
                  routingOperationId,
                  machineId: r.machineId,
                  shiftId: r.shiftId || null,
                  plannedQty: r.plannedQty,
                  priority: Number(r.priority) || 1,
                  setupMinutes: r.setupMinutes === '' ? null : Number(r.setupMinutes),
                  qcSampleMinutes: r.qcSampleMinutes === '' ? null : Number(r.qcSampleMinutes),
                  note: r.note || null,
                };
              }),
          } as never,
        }),
      );
      onDirtyChange(false);
      await onSaved();
    } catch (error) {
      setProblem(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Card>
      <CardHeader>
        <CardTitle>Lines</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {!loading && !operations.length ? (
          <InlineAlert tone="info" title="No released work order runs on this line">
            Release a work order whose routing has an operation on {spk.workCenterCode} first.
          </InlineAlert>
        ) : null}
        {/* no edits while a save and its refresh run: the request carries the rows as they were, and the refresh replaces them */}
        <fieldset disabled={busy} aria-busy={busy} className="m-0 flex min-w-0 flex-col gap-4 border-0 p-0">
          <ol className="m-0 flex list-none flex-col gap-4 p-0" aria-label="SPK lines">
            {rows.map((r, i) => {
              const op = operations.find((o) => opKey(o.workOrderId, o.operationId) === r.operation);
              return (
                <li key={r.key} className="rounded-lg border border-line p-4">
                  <fieldset className="m-0 grid grid-cols-6 gap-3 border-0 p-0 max-lg:grid-cols-2 max-sm:grid-cols-1">
                    <legend className="mb-2 text-[13px] font-semibold">Line {i + 1}</legend>
                    <div className="col-span-3 max-lg:col-span-2 max-sm:col-span-1">
                      <Field label="Work order operation" required>
                        {(c) => (
                          <Select
                            {...c}
                            value={r.operation}
                            onValueChange={(v) => {
                              const o = operations.find((x) => opKey(x.workOrderId, x.operationId) === v);
                              set(i, { operation: v, setupMinutes: r.setupMinutes === '' && o ? o.setupMinutes : r.setupMinutes });
                            }}
                            options={opOptions}
                            placeholder={loading ? 'Loading…' : 'Choose a work order operation'}
                          />
                        )}
                      </Field>
                    </div>
                    <Field label="Machine" required>
                      {(c) => (
                        <Select {...c} value={r.machineId} onValueChange={(v) => set(i, { machineId: v })} options={machineOptions} placeholder="Choose" />
                      )}
                    </Field>
                    <Field label="Shift">
                      {(c) => (
                        <Select
                          {...c}
                          value={r.shiftId}
                          onValueChange={(v) => set(i, { shiftId: v })}
                          options={shiftOptions}
                          emptyLabel="Any shift"
                          placeholder="Any shift"
                        />
                      )}
                    </Field>
                    <Field label={`Planned qty${op ? ` (${op.uomCode})` : ''}`} required>
                      {(c) => <Input {...c} inputMode="decimal" value={r.plannedQty} onChange={(e) => set(i, { plannedQty: e.target.value })} />}
                    </Field>
                    <Field label="Priority">
                      {(c) => <Input {...c} inputMode="numeric" value={r.priority} onChange={(e) => set(i, { priority: e.target.value })} />}
                    </Field>
                    <Field label="Setup (min)" hint="From the routing.">
                      {(c) => <Input {...c} inputMode="decimal" value={r.setupMinutes} onChange={(e) => set(i, { setupMinutes: e.target.value })} />}
                    </Field>
                    <Field label="QC sample (min)">
                      {(c) => <Input {...c} inputMode="decimal" value={r.qcSampleMinutes} onChange={(e) => set(i, { qcSampleMinutes: e.target.value })} />}
                    </Field>
                    <div className="col-span-2 max-sm:col-span-1">
                      <Field label="Note">{(c) => <Input {...c} value={r.note} maxLength={300} onChange={(e) => set(i, { note: e.target.value })} />}</Field>
                    </div>
                    <div className="flex items-end">
                      <IconButton aria-label={`Remove line ${i + 1}`} onClick={() => edit((all) => all.filter((_, j) => j !== i))}>
                        <Trash2 aria-hidden="true" />
                      </IconButton>
                    </div>
                  </fieldset>
                  {op ? (
                    <p className="m-0 mt-2 text-xs text-ink-2">
                      {op.itemName} · WO {op.qty} {op.uomCode} due {formatDay(op.dueDate)} · {op.sph ? `${op.sph} pcs/h` : 'no SPH: hours not calculable'}
                    </p>
                  ) : null}
                </li>
              );
            })}
          </ol>
          {problem ? (
            <InlineAlert tone="danger" title="SPK not saved">
              {problem}
            </InlineAlert>
          ) : null}
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => edit((all) => [...all, blank()])}>
              <Plus aria-hidden="true" className="size-4" />
              Add line
            </Button>
            <Button variant="primary" onClick={save} disabled={busy}>
              {busy ? 'Saving…' : 'Save SPK'}
            </Button>
          </div>
        </fieldset>
        <p className="m-0 text-xs text-ink-2">Save to see the hours and the machine load. Hours = planned qty ÷ SPH + setup + QC sample.</p>
      </CardContent>
    </Card>
  );
}

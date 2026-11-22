'use client';

import * as React from 'react';
import Link from 'next/link';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { ClipboardList, RefreshCw } from 'lucide-react';
import { api, call, failureMessage, useMe } from '@eora/features/auth';
import { useDensityPreference, usePageSizePreference, type FilterValues, type SortRule } from '@eora/features/data';
import {
  ColumnSettings,
  DataTable,
  DensityToggle,
  EnterprisePage,
  FilterBar,
  FilterToggle,
  ListSearch,
  ListWorkspace,
  Pagination,
  SavedViewsMenu,
  useFilterPanel,
  type ColumnGroup,
  type ColumnVisibility,
  type EoraColumn,
  statusEntry,
  type FilterField,
  type StatusOf,
} from '@eora/features/patterns';
import { Badge, Button, DatePicker, EmptyState, Field, InlineAlert, Input, LoadingState, PermissionDeniedState, Textarea, type IsoDate } from '@eora/ui';
import { formatDay, formatMoment, formatQty } from '@/components/format';
import { FormLayer } from '@/modules/master/form-layer';
import { useFormParam } from '@/modules/master/form-url';
import { Quantity } from '@/components/quantity';
import { useSavedViews } from '@/components/use-saved-views';
import { queryOptions } from '@/modules/master/source';

type Lot = {
  id: string;
  lot_id: string | null;
  lot_no: string | null;
  stock_status: string;
  qty: string;
  allocated: string;
  location_code: string;
  uom: string;
  /** customer that owns the lot (D-135); none: company stock */
  owner_code?: string | null;
};
type SourceWo = { id: string; wo_no: string; status: string; due: string; remaining: string };
type Production = { wo_no: string; work_order_id: string; lot_no: string | null; shift_code: string | null; qty: string; doc_no: string };
type Demand = { id: string; due: string; outstanding: string; protected_qty: string };
export type RecoveryRow = {
  id: string;
  planId: string | null;
  revision: number;
  version: number;
  customerId: string;
  customerCode: string;
  customerName: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  partNo: string;
  requiredQty: string;
  backlogQty: string;
  shippedTodayQty: string;
  fgQty: string;
  beforeCheckQty: string;
  acceptedQty: string;
  allocated: { fg: string; beforeCheck: string; accepted: string; wo: string };
  materialCapacityQty: string | null;
  minimumQty: string;
  batchQty: string | null;
  bufferQty: string;
  shift1Qty: string;
  shift2Qty: string;
  shift3Qty: string;
  reason: string | null;
  netQty: string;
  proposedQty: string | null;
  planQty: string;
  positionQty: string;
  workOrderId: string | null;
  workOrderCode: string | null;
  workOrderStatus: string | null;
  status: string;
  inputsChanged: boolean;
  lots: Lot[];
  demands: Demand[];
  workOrders: SourceWo[];
  production: Production[];
};
type Result = {
  data: RecoveryRow[];
  meta: { total: number; page: number; perPage: number; today: string; targetDate: string; asOf: string; fingerprint: string; formulaCode: string };
};
const STATUSES: Record<string, string> = {
  WAIT_QC: 'Waiting for QC',
  COVERED: 'Covered',
  BATCH_MISSING: 'Enter pcs per batch',
  BOM_MISSING: 'No BOM in force',
  MATERIAL_SHORT: 'Material short',
  PLAN_REQUIRED: 'Plan needed',
  WO_CREATED: 'Work order created',
};
const STATUS_OPTIONS = Object.entries(STATUSES).map(([value, label]) => ({ value, label }));
/** Supply positions and plan figures are grouped under one header each (EORA-COMPONENT-STANDARDS §5 grouped columns). */
const GROUPS: ColumnGroup[] = [
  { id: 'supply', label: 'Supply covering the demand', title: 'Each position is counted once: FG stock, Pending QC, QC released and remaining WO output' },
  { id: 'plan', label: 'Work order plan' },
];
/** Shown on demand from Column settings (the detail drawer always lists them). */
const DEFAULT_HIDDEN: ColumnVisibility = { acceptedQty: false, wo: false, material: false, planQty: false };
const pcs = (value: string | null) => (value === null ? <span className="text-ink-2">Not calculated</span> : <Quantity value={value} uom="PCS" />);
const zero = (value: string | null) => value !== null && Number(value) === 0;

export function DailyRecoveryScreen() {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('planning.recovery.view');
  const canEdit = permissions.includes('planning.recovery.create');
  const canCreateWo = canEdit && permissions.includes('planning.wo.create');
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const client = useQueryClient();
  const panel = useFilterPanel();
  const [density, setDensity] = useDensityPreference();
  const [prefSize, setPrefSize] = usePageSizePreference();
  const [visibility, setVisibility] = React.useState<ColumnVisibility>(DEFAULT_HIDDEN);
  const saved = useSavedViews('daily-recovery');
  // the plan under review is a form page named in the URL (D-140): `?form=review&row=<id>`
  const formUrl = useFormParam(['row']);
  const detailId = formUrl.form === 'review' ? formUrl.get('row') : null;
  const review = (id: string) => formUrl.open('review', { row: id });
  const [notice, setNotice] = React.useState<string | null>(null);
  const plant = params.get('plantId') || me.data?.activePlantId || me.data?.plants[0]?.id || '';
  const tenant = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId);
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: tenant?.timezone ?? 'Asia/Jakarta' }).format(new Date());
  const tomorrow = new Date(`${today}T00:00:00Z`);
  tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
  const targetDate = params.get('targetDate') || tomorrow.toISOString().slice(0, 10);
  const customer = params.get('customerId') || '';
  const status = (params.get('status') || '').split(',').filter((s) => s in STATUSES);
  const search = params.get('q') || '';
  const sortParam = params.get('sort') || '';
  const sort: SortRule[] = sortParam ? [{ id: sortParam.replace(/^-/, ''), desc: sortParam.startsWith('-') }] : [];
  const page = Math.max(1, Number(params.get('page')) || 1);
  const size = Math.min(100, Math.max(1, Number(params.get('perPage')) || Number(prefSize) || 20));
  const setParams = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
    if (!('page' in changes)) next.delete('page');
    next.delete('form');
    next.delete('row');
    router.replace(`${pathname}?${next}`);
  };
  const fields: FilterField[] = [
    {
      key: 'plantId',
      type: 'entity',
      entity: 'plant',
      label: 'Plant',
      loadOptions: async () => (me.data?.plants ?? []).map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` })),
    },
    {
      key: 'customerId',
      type: 'entity',
      entity: 'customer',
      label: 'Customer',
      loadOptions: (_deps, signal) => queryOptions('core.customers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
    },
    { key: 'status', type: 'multiselect', source: 'static', label: 'Next step', options: STATUS_OPTIONS },
  ];
  const query = useQuery({
    queryKey: ['daily-recovery', plant, targetDate, customer, status.join(','), search, sortParam, page, size],
    queryFn: async ({ signal }) =>
      (await call(() =>
        api.GET('/planning/daily-recovery', {
          params: {
            query: {
              plantId: plant,
              targetDate,
              customerId: customer || undefined,
              // Laravel reads repeated `status[]` keys as a list
              'status[]': (status.length ? status : undefined) as never,
              sort: (sortParam || undefined) as never,
              q: search || undefined,
              page,
              perPage: size,
            },
          },
          signal,
        }),
      )) as unknown as Result,
    enabled: canView && !!plant,
    retry: false,
  });
  const rows = query.data?.data ?? [];
  const selected = rows.find((row) => row.id === detailId);
  const columns: EoraColumn<RecoveryRow>[] = [
    {
      id: 'part',
      header: 'Part',
      lead: true,
      textCol: true,
      critical: true,
      sortable: true,
      cell: (r) => (
        <button type="button" className="text-left font-medium text-link hover:underline" onClick={() => review(r.id)}>
          {r.itemName}
        </button>
      ),
      sub: (r) => r.partNo,
      title: (r) => `${r.itemName} · ${r.partNo}`,
    },
    { id: 'customer', header: 'Customer', sortable: true, cell: (r) => r.customerCode, title: (r) => r.customerName, minWidth: 84 },
    {
      id: 'status',
      header: 'Next step',
      critical: true,
      sortable: true,
      cell: (r) => <Badge tone={r.status === 'WAIT_QC' || r.status === 'MATERIAL_SHORT' ? 'warning' : 'neutral'}>{STATUSES[r.status] ?? r.status}</Badge>,
      minWidth: 140,
    },
    { id: 'requiredQty', header: 'Demand to target', numeric: true, critical: true, sortable: true, cell: (r) => pcs(r.requiredQty), minWidth: 124 },
    { id: 'fgQty', header: 'FG stock', group: 'supply', numeric: true, cell: (r) => pcs(r.fgQty), emptyOnPhone: (r) => zero(r.fgQty), minWidth: 92 },
    {
      id: 'beforeCheckQty',
      header: 'Pending QC',
      group: 'supply',
      numeric: true,
      critical: true,
      cell: (r) => pcs(r.beforeCheckQty),
      emptyOnPhone: (r) => zero(r.beforeCheckQty),
      minWidth: 92,
    },
    {
      id: 'acceptedQty',
      header: 'QC released',
      group: 'supply',
      numeric: true,
      cell: (r) => pcs(r.acceptedQty),
      emptyOnPhone: (r) => zero(r.acceptedQty),
      minWidth: 92,
    },
    {
      id: 'wo',
      header: 'Remaining WO',
      group: 'supply',
      numeric: true,
      cell: (r) => pcs(r.allocated.wo),
      emptyOnPhone: (r) => zero(r.allocated.wo),
      minWidth: 112,
    },
    { id: 'netQty', header: 'Net need', group: 'plan', numeric: true, critical: true, sortable: true, cell: (r) => pcs(r.netQty), minWidth: 92 },
    { id: 'proposedQty', header: 'Proposed', group: 'plan', numeric: true, critical: true, sortable: true, cell: (r) => pcs(r.proposedQty), minWidth: 92 },
    { id: 'material', header: 'Material capacity', group: 'plan', numeric: true, cell: (r) => pcs(r.materialCapacityQty), minWidth: 130 },
    { id: 'planQty', header: 'Planned', group: 'plan', numeric: true, cell: (r) => pcs(r.planQty), emptyOnPhone: (r) => zero(r.planQty), minWidth: 92 },
  ];
  if (me.isPending) return <LoadingState title="Loading Daily Recovery" />;
  if (!canView)
    return (
      <div className="p-6">
        <PermissionDeniedState title="Daily Recovery is not available for your role" description="Ask your administrator for planning access at this plant." />
      </div>
    );
  if (!plant)
    return (
      <div className="p-6">
        <EmptyState title="No plant available" description="Ask your administrator for access to a plant." />
      </div>
    );

  const total = query.data?.meta.total;
  const filtersApplied = (customer ? 1 : 0) + (status.length ? 1 : 0);
  return (
    <EnterprisePage
      title="Daily Recovery Planner"
      description="Cover delivery demand and plan production per shift."
      actions={
        <Button variant="secondary" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RefreshCw className="size-4" aria-hidden="true" />
          Refresh
        </Button>
      }
    >
      {notice ? <InlineAlert tone="success" title={notice} className="mb-4" /> : null}
      <ListWorkspace
        label="Daily Recovery"
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <div className="w-44 max-md:w-full">
                <DatePicker
                  value={targetDate as IsoDate}
                  min={today as IsoDate}
                  label="Target production / delivery date"
                  onChange={(value) => value && setParams({ targetDate: value })}
                />
              </div>
              <ListSearch label="Search customer or part" placeholder="Search customer, part or part no…" value={search} onChange={(q) => setParams({ q })} />
              <FilterToggle panel={panel} count={filtersApplied} controlsId="daily-recovery-filters" />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{total ?? '…'}</b> {total === 1 ? 'part' : 'parts'}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <SavedViewsMenu
                views={saved.views}
                onApply={(v) => {
                  const pick = (key: string) => {
                    const value = v.filters[key];
                    return Array.isArray(value) ? value.join(',') : (value ?? '');
                  };
                  const rule = v.sort?.[0];
                  setParams({
                    customerId: pick('customerId'),
                    status: pick('status'),
                    q: v.search ?? '',
                    sort: rule ? `${rule.desc ? '-' : ''}${rule.id}` : '',
                  });
                  if (v.columns) setVisibility(v.columns);
                }}
                onSave={(name) =>
                  saved.save(name, {
                    search,
                    filters: { customerId: customer || undefined, status: status.length ? status : undefined },
                    sort,
                    columns: visibility,
                  })
                }
                onDelete={saved.remove}
              />
              <DensityToggle value={density} onChange={setDensity} />
              <ColumnSettings columns={columns} value={visibility} onChange={setVisibility} />
            </div>
          </>
        }
        filterBar={
          <FilterBar
            id="daily-recovery-filters"
            fields={fields}
            values={{ plantId: plant, customerId: customer, status }}
            panel={panel}
            onApply={(values: FilterValues) =>
              setParams({
                plantId: String(values.plantId || plant),
                customerId: String(values.customerId || ''),
                status: Array.isArray(values.status) ? values.status.join(',') : String(values.status || ''),
              })
            }
          />
        }
        pagination={
          total ? (
            <Pagination
              page={page}
              pageSize={size}
              total={total}
              itemLabel="parts"
              onPageChange={(p) => setParams({ page: String(p) })}
              onPageSizeChange={(s) => {
                setPrefSize(String(s));
                setParams({ perPage: String(s) });
              }}
            />
          ) : undefined
        }
      >
        <DataTable
          label="Daily Recovery by part and customer"
          columns={columns}
          groups={GROUPS}
          rows={rows}
          getRowId={(r) => r.id}
          rowLabel={(r) => `${r.customerCode} ${r.itemName}`}
          status={query.isError ? 'error' : query.isPending ? 'loading' : 'ready'}
          error={query.error}
          onRetry={() => query.refetch()}
          isFetching={query.isFetching && !query.isPending}
          sort={sort}
          onSortChange={(rules) => setParams({ sort: rules[0] ? `${rules[0].desc ? '-' : ''}${rules[0].id}` : '' })}
          density={density}
          columnVisibility={visibility}
          onColumnVisibilityChange={setVisibility}
          empty={
            filtersApplied || search ? (
              <EmptyState
                title="No parts match these filters"
                description="Nothing in this plant matches the search and filters for the target date."
                action={
                  <Button size="sm" onClick={() => setParams({ customerId: '', status: '', q: '' })}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState title="No parts to plan" description="Add an active customer part and a delivery schedule for this plant." />
            )
          }
          rowActions={{ primary: (r) => ({ id: 'review', label: 'Review', icon: ClipboardList, onSelect: () => review(r.id) }) }}
        />
      </ListWorkspace>
      {query.data ? (
        <p className="mt-3 text-xs text-ink-2">
          Position as of {formatMoment(query.data.meta.asOf)} · production date {formatDay(query.data.meta.today)} · {query.data.meta.formulaCode}
        </p>
      ) : null}
      <FormLayer
        open={!!selected}
        title={selected ? `${selected.customerCode} · ${selected.itemName}` : 'Review plan'}
        back={{ label: 'Back to Daily Recovery', href: formUrl.closeHref }}
        facts={
          selected
            ? [
                { label: 'Part', value: selected.partNo },
                { label: 'Target', value: formatDay(targetDate) },
              ]
            : undefined
        }
        onClose={formUrl.close}
      >
        {(guard) =>
          selected ? (
            <RecoveryDetail
              key={`${selected.id}-${selected.version}`}
              row={selected}
              plantId={plant}
              targetDate={targetDate}
              canEdit={canEdit}
              canCreateWo={canCreateWo}
              onDirtyChange={guard.setDirty}
              onSaved={async () => {
                await client.invalidateQueries({ queryKey: ['daily-recovery'] });
                setNotice('Shift plan saved');
              }}
              onCreated={(id) => router.push(`/ppic/work-orders?order=${id}`)}
            />
          ) : null
        }
      </FormLayer>
    </EnterprisePage>
  );
}

function RecoveryDetail({
  row,
  plantId,
  targetDate,
  canEdit,
  canCreateWo,
  onDirtyChange,
  onSaved,
  onCreated,
}: {
  row: RecoveryRow;
  plantId: string;
  targetDate: string;
  canEdit: boolean;
  canCreateWo: boolean;
  onDirtyChange: (dirty: boolean) => void;
  onSaved: () => Promise<void>;
  onCreated: (id: string) => void;
}) {
  const additional = row.workOrderId !== null;
  const [values, setValues] = React.useState({
    minimumQty: row.minimumQty,
    batchQty: row.batchQty ?? '',
    bufferQty: row.bufferQty,
    shift1Qty: additional ? (row.proposedQty ?? '0') : row.shift1Qty,
    shift2Qty: additional ? '0' : row.shift2Qty,
    shift3Qty: additional ? '0' : row.shift3Qty,
    reason: additional ? '' : (row.reason ?? ''),
  });
  const [busy, setBusy] = React.useState(false);
  const [problem, setProblem] = React.useState<string | null>(null);
  const [dirty, setDirty] = React.useState(false);
  React.useEffect(() => onDirtyChange(dirty), [dirty, onDirtyChange]);
  const set = (key: keyof typeof values, value: string) => {
    setDirty(true);
    setValues((old) => ({ ...old, [key]: value }));
  };
  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setProblem(null);
    try {
      await call(() =>
        api.PUT('/planning/daily-recovery', {
          headers: { 'If-Match': `"${row.version}"` },
          body: {
            planId: row.planId,
            plantId,
            targetDate,
            customerId: row.customerId,
            itemId: row.itemId,
            minimumQty: Number(values.minimumQty),
            batchQty: values.batchQty ? Number(values.batchQty) : null,
            bufferQty: Number(values.bufferQty),
            shift1Qty: Number(values.shift1Qty),
            shift2Qty: Number(values.shift2Qty),
            shift3Qty: Number(values.shift3Qty),
            reason: values.reason,
          },
        }),
      );
      setDirty(false);
      await onSaved();
    } catch (error) {
      setProblem(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };
  const create = async () => {
    if (!row.planId) return;
    setBusy(true);
    setProblem(null);
    try {
      const response = (await call(() =>
        api.POST('/planning/daily-recovery/{plan}/actions/create-work-order', {
          params: { path: { plan: row.planId! } },
          headers: { 'If-Match': `"${row.version}"` },
        }),
      )) as unknown as { data: { workOrderId: string } };
      onCreated(response.data.workOrderId);
    } catch (error) {
      setProblem(failureMessage(error));
    } finally {
      setBusy(false);
    }
  };
  const numbers: { key: keyof typeof values; label: string; hint?: string }[] = [
    { key: 'minimumQty', label: 'Minimum work order (pcs)' },
    { key: 'batchQty', label: 'Pcs per material batch', hint: 'Example: 22 pcs per sheet rounds a minimum of 500 up to 506.' },
    { key: 'bufferQty', label: 'Buffer (pcs)' },
    { key: 'shift1Qty', label: 'Shift I plan (pcs)' },
    { key: 'shift2Qty', label: 'Shift II plan (pcs)' },
    { key: 'shift3Qty', label: 'Shift III plan (pcs)' },
  ];
  return (
    <div role="region" aria-label="Production plan detail" className="flex flex-col gap-5 text-[13px]">
      <p className="text-ink-2">
        {row.partNo} · Target {targetDate} · Backlog {pcs(row.backlogQty)} · Shipped today {pcs(row.shippedTodayQty)}
      </p>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Supply used for this demand</h3>
        <dl className="grid grid-cols-2 gap-2">
          {Object.entries({
            'FG allocated / staged': row.allocated.fg,
            'Pending QC (projected)': row.allocated.beforeCheck,
            'QC released, awaiting FG receipt': row.allocated.accepted,
            'Remaining WO output before target': row.allocated.wo,
            'Net work order need': row.netQty,
            'Proposed (whole batches)': row.proposedQty,
            'Material capacity (estimate)': row.materialCapacityQty,
          }).map(([label, qty]) => (
            <React.Fragment key={label}>
              <dt className="text-ink-2">{label}</dt>
              <dd className="text-right tabular-nums">{pcs(qty)}</dd>
            </React.Fragment>
          ))}
        </dl>
      </section>
      {row.inputsChanged && !additional ? (
        <InlineAlert tone="warning" title="Position changed since the plan was saved">
          Check the new figures and save again before creating the work order.
        </InlineAlert>
      ) : null}
      {row.status === 'MATERIAL_SHORT' ? (
        <InlineAlert tone="warning" title="Material capacity is below the proposal">
          Check material lots and inbound dates before releasing the work order.
        </InlineAlert>
      ) : null}
      {additional ? (
        <InlineAlert tone="info" title={`The earlier plan became ${row.workOrderCode}`}>
          <Link className="text-link underline" href={`/ppic/work-orders?order=${row.workOrderId}`}>
            Open that work order
          </Link>
          . Save the quantities below as an additional plan with a new reason.
        </InlineAlert>
      ) : null}
      <form onSubmit={save} className="space-y-4">
        <h3 className="text-sm font-semibold">{additional ? 'Additional plan' : 'Production plan per shift'}</h3>
        <fieldset disabled={!canEdit || busy} className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
          <legend className="sr-only">Planning parameters and quantities</legend>
          {numbers.map(({ key, label, hint }) => (
            <Field key={key} label={label} hint={hint}>
              {(control) => (
                <Input
                  {...control}
                  type="number"
                  min={key === 'batchQty' ? 1 : 0}
                  max={1000000000}
                  step="1"
                  value={values[key]}
                  onChange={(e) => set(key, e.target.value)}
                  required={key !== 'batchQty'}
                />
              )}
            </Field>
          ))}
          <Field label="Reason" required className="col-span-2 max-sm:col-span-1">
            {(control) => (
              <Textarea {...control} value={values.reason} onChange={(e) => set('reason', e.target.value)} minLength={3} maxLength={500} required />
            )}
          </Field>
        </fieldset>
        {problem ? (
          <InlineAlert tone="danger" title="Plan not saved">
            {problem}
          </InlineAlert>
        ) : null}
        <div className="flex flex-wrap gap-2">
          <Button type="submit" variant="primary" disabled={!canEdit || busy}>
            {busy ? 'Saving…' : additional ? 'Save additional plan' : 'Save shift plan'}
          </Button>
          {!additional ? (
            <Button
              type="button"
              variant="secondary"
              onClick={create}
              disabled={!canCreateWo || busy || dirty || !row.planId || row.inputsChanged || Number(row.planQty) <= 0}
            >
              Create draft work order
            </Button>
          ) : null}
        </div>
        <p className="text-xs text-ink-2">Save the plan first. The draft work order then follows the normal release approval.</p>
      </form>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Lots and positions</h3>
        <div tabIndex={0} role="region" aria-label="Balances by lot" className="overflow-x-auto rounded-md border border-line">
          <table className="w-full text-left text-xs">
            <thead className="bg-subtle text-ink-2">
              <tr>
                <th className="p-2">Lot / location</th>
                <th className="p-2">Status</th>
                <th className="p-2 text-right">Qty</th>
              </tr>
            </thead>
            <tbody>
              {row.lots.map((lot) => (
                <tr className="border-t border-line" key={lot.id}>
                  <td className="p-2">
                    {lot.lot_no ?? 'No lot'}
                    <br />
                    {lot.location_code} · {lot.owner_code ? `Owner ${lot.owner_code}` : 'Company stock'}
                  </td>
                  <td className="p-2">
                    {lot.stock_status === 'PENDING_QC' ? 'Pending QC (Before Check)' : statusEntry('stock', lot.stock_status as StatusOf<'stock'>).label}
                  </td>
                  <td className="p-2 text-right">{formatQty(lot.qty, lot.uom)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!row.lots.length ? <p className="mt-2 text-ink-2">No lot balance for this part.</p> : null}
      </section>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Production reported today</h3>
        {row.production.length ? (
          row.production.map((p, index) => (
            <p key={`${p.doc_no}-${index}`} className="mb-2">
              {p.wo_no} · {p.lot_no ?? 'No lot'} · {p.shift_code ?? 'No shift'} · {pcs(p.qty)}
            </p>
          ))
        ) : (
          <p className="text-ink-2">No production reported for this production date.</p>
        )}
      </section>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Remaining work orders and demand</h3>
        {row.workOrders.map((w) => (
          <p className="mb-2" key={w.id}>
            <Link className="text-link underline" href={`/ppic/work-orders?order=${w.id}`}>
              {w.wo_no}
            </Link>{' '}
            · {w.status} · due {w.due} · remaining {pcs(w.remaining)}
          </p>
        ))}
        {row.demands.map((d) => (
          <p className="mb-1 text-ink-2" key={d.id}>
            Delivery {d.due} · outstanding {pcs(d.outstanding)}
          </p>
        ))}
      </section>
    </div>
  );
}

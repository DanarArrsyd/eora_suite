'use client';

import * as React from 'react';
import Link from 'next/link';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { ClipboardList, RefreshCw } from 'lucide-react';
import { api, call, failureMessage, useMe } from '@eora/features/auth';
import { useDensityPreference, type FilterValues } from '@eora/features/data';
import {
  DataTable,
  DensityToggle,
  EnterprisePage,
  FilterBar,
  FilterToggle,
  ListSearch,
  ListWorkspace,
  Pagination,
  useFilterPanel,
  type EoraColumn,
  statusEntry,
  type FilterField,
  type StatusOf,
} from '@eora/features/patterns';
import {
  Badge,
  Button,
  DatePicker,
  Drawer,
  DrawerContent,
  EmptyState,
  Field,
  InlineAlert,
  Input,
  LoadingState,
  PermissionDeniedState,
  Textarea,
  type IsoDate,
} from '@eora/ui';
import { QuantityWithUom } from '@/components/quantity';
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
const pcs = (value: string | null) =>
  value === null ? <span className="text-ink-2">Not calculated</span> : <QuantityWithUom value={Number(value)} uom="pcs" />;

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
  const [detailId, setDetailId] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const plant = params.get('plantId') || me.data?.activePlantId || me.data?.plants[0]?.id || '';
  const tenant = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId);
  const today = new Intl.DateTimeFormat('en-CA', { timeZone: tenant?.timezone ?? 'Asia/Jakarta' }).format(new Date());
  const tomorrow = new Date(`${today}T00:00:00Z`);
  tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
  const targetDate = params.get('targetDate') || tomorrow.toISOString().slice(0, 10);
  const customer = params.get('customerId') || '';
  const search = params.get('q') || '';
  const page = Math.max(1, Number(params.get('page')) || 1);
  const size = Math.min(100, Math.max(1, Number(params.get('perPage')) || 25));
  const setParams = (changes: Record<string, string>) => {
    const next = new URLSearchParams(params);
    Object.entries(changes).forEach(([key, value]) => (value ? next.set(key, value) : next.delete(key)));
    if (!('page' in changes)) next.delete('page');
    setDetailId(null);
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
  ];
  const query = useQuery({
    queryKey: ['daily-recovery', plant, targetDate, customer, search, page, size],
    queryFn: async ({ signal }) =>
      (await call(() =>
        api.GET('/planning/daily-recovery', {
          params: { query: { plantId: plant, targetDate, customerId: customer || undefined, q: search || undefined, page, perPage: size } },
          signal,
        }),
      )) as unknown as Result,
    enabled: canView && !!plant,
    retry: false,
  });
  const rows = query.data?.data ?? [];
  const selected = rows.find((row) => row.id === detailId);
  const columns: EoraColumn<RecoveryRow>[] = [
    { id: 'customer', header: 'Customer', cell: (r) => r.customerCode, minWidth: 100 },
    {
      id: 'part',
      header: 'Part Name',
      lead: true,
      textCol: true,
      critical: true,
      cell: (r) => (
        <button type="button" className="text-left font-medium text-link hover:underline" onClick={() => setDetailId(r.id)}>
          {r.itemName}
        </button>
      ),
      title: (r) => r.itemName,
    },
    { id: 'partNo', header: 'Part No.', cell: (r) => r.partNo, minWidth: 170 },
    { id: 'requiredQty', header: 'Demand up to target', cell: (r) => pcs(r.requiredQty), numeric: true, minWidth: 160 },
    { id: 'fgQty', header: 'FG warehouse stock', cell: (r) => pcs(r.fgQty), numeric: true, minWidth: 120 },
    { id: 'beforeCheckQty', header: 'Pending QC (Before Check)', cell: (r) => pcs(r.beforeCheckQty), numeric: true, critical: true, minWidth: 130 },
    { id: 'acceptedQty', header: 'QC released, awaiting FG receipt', cell: (r) => pcs(r.acceptedQty), numeric: true, minWidth: 175 },
    { id: 'wo', header: 'Remaining WO output', cell: (r) => pcs(r.allocated.wo), numeric: true, minWidth: 175 },
    { id: 'netQty', header: 'Net work order need', cell: (r) => pcs(r.netQty), numeric: true, critical: true, minWidth: 150 },
    { id: 'proposedQty', header: 'Proposed (whole batches)', cell: (r) => pcs(r.proposedQty), numeric: true, critical: true, minWidth: 160 },
    { id: 'material', header: 'Material capacity (estimate)', cell: (r) => pcs(r.materialCapacityQty), numeric: true, minWidth: 180 },
    { id: 'planQty', header: 'Planned total', cell: (r) => pcs(r.planQty), numeric: true, minWidth: 135 },
    {
      id: 'status',
      header: 'Action',
      cell: (r) => <Badge tone={r.status === 'WAIT_QC' || r.status === 'MATERIAL_SHORT' ? 'warning' : 'neutral'}>{STATUSES[r.status] ?? r.status}</Badge>,
      minWidth: 155,
    },
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
      <div className="mb-4 flex flex-wrap items-end justify-between gap-4 rounded-lg border border-line bg-surface p-4">
        <Field label="Target production / delivery date">
          {(control) => (
            <DatePicker
              {...control}
              value={targetDate as IsoDate}
              min={today as IsoDate}
              label="Target production / delivery date"
              onChange={(value) => value && setParams({ targetDate: value })}
            />
          )}
        </Field>
        <p className="max-w-xl text-xs leading-5 text-ink-2">
          Pending QC (Before Check) counts as projected supply for planning only. Delivery uses released FG stock. Shared stock is allocated across customers
          once, before filters.
        </p>
      </div>
      <ListWorkspace
        label="Daily Recovery"
        toolbar={
          <>
            <div className="flex flex-wrap items-center gap-3">
              <ListSearch label="Search customer or part" placeholder="Search customer, part or part no…" value={search} onChange={(q) => setParams({ q })} />
              <span className="text-xs text-ink-2">{query.data?.meta.total ?? '…'} part</span>
            </div>
            <div className="flex items-center gap-2">
              <FilterToggle panel={panel} count={customer ? 2 : 1} controlsId="daily-recovery-filters" />
              <DensityToggle value={density} onChange={setDensity} />
            </div>
          </>
        }
        filterBar={
          <FilterBar
            id="daily-recovery-filters"
            fields={fields}
            values={{ plantId: plant, customerId: customer }}
            panel={panel}
            onApply={(values: FilterValues) => setParams({ plantId: String(values.plantId || plant), customerId: String(values.customerId || '') })}
          />
        }
        pagination={
          query.data?.meta.total ? (
            <Pagination
              page={page}
              pageSize={size}
              total={query.data.meta.total}
              itemLabel="part"
              onPageChange={(p) => setParams({ page: String(p) })}
              onPageSizeChange={(s) => setParams({ perPage: String(s) })}
            />
          ) : undefined
        }
      >
        <DataTable
          label="Daily Recovery by customer and part"
          columns={columns}
          rows={rows}
          getRowId={(r) => r.id}
          rowLabel={(r) => `${r.customerCode} ${r.itemName}`}
          status={query.isError ? 'error' : query.isPending ? 'loading' : 'ready'}
          error={query.error}
          onRetry={() => query.refetch()}
          isFetching={query.isFetching && !query.isPending}
          sort={[]}
          onSortChange={() => undefined}
          density={density}
          empty={
            <EmptyState
              title="No parts match these filters"
              description="Add an active customer part and a delivery schedule, or change the customer filter."
            />
          }
          rowActions={{ primary: (r) => ({ id: 'review', label: 'Review plan', icon: ClipboardList, onSelect: () => setDetailId(r.id) }) }}
        />
      </ListWorkspace>
      <p className="mt-3 text-xs text-ink-2">Scroll the table right for QC, remaining WO, material capacity and the plan.</p>
      {query.data ? (
        <p className="mt-3 text-xs text-ink-2">
          Live position {query.data.meta.asOf} · Production date {query.data.meta.today} · {query.data.meta.formulaCode} (in force from 18 Nov 2026)
        </p>
      ) : null}
      <Drawer open={!!selected} onOpenChange={(open) => !open && setDetailId(null)}>
        <DrawerContent title={selected ? `${selected.customerCode} · ${selected.itemName}` : 'Review plan'} closeLabel="Close review">
          {selected ? (
            <RecoveryDetail
              key={`${selected.id}-${selected.version}`}
              row={selected}
              plantId={plant}
              targetDate={targetDate}
              canEdit={canEdit}
              canCreateWo={canCreateWo}
              onSaved={async () => {
                await client.invalidateQueries({ queryKey: ['daily-recovery'] });
                setNotice('Shift plan saved');
              }}
              onCreated={(id) => router.push(`/ppic/work-orders?order=${id}`)}
            />
          ) : null}
        </DrawerContent>
      </Drawer>
    </EnterprisePage>
  );
}

function RecoveryDetail({
  row,
  plantId,
  targetDate,
  canEdit,
  canCreateWo,
  onSaved,
  onCreated,
}: {
  row: RecoveryRow;
  plantId: string;
  targetDate: string;
  canEdit: boolean;
  canCreateWo: boolean;
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
    <div tabIndex={0} role="region" aria-label="Production plan detail" className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5 text-[13px]">
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
                    {lot.location_code}
                  </td>
                  <td className="p-2">
                    {lot.stock_status === 'PENDING_QC' ? 'Pending QC (Before Check)' : statusEntry('stock', lot.stock_status as StatusOf<'stock'>).label}
                  </td>
                  <td className="p-2 text-right">
                    {lot.qty} {lot.uom}
                  </td>
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

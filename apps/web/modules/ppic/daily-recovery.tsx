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
  type FilterField,
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
  WAIT_QC: 'Kejar QC',
  COVERED: 'Tercakup',
  BATCH_MISSING: 'Isi konversi batch',
  BOM_MISSING: 'BOM belum tersedia',
  MATERIAL_SHORT: 'Material kurang',
  PLAN_REQUIRED: 'Perlu rencana',
  WO_CREATED: 'SPK dibuat',
};
const pcs = (value: string | null) =>
  value === null ? <span className="text-ink-2">Belum terhitung</span> : <QuantityWithUom value={Number(value)} uom="pcs" />;

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
    { id: 'requiredQty', header: 'Kebutuhan s.d. target', cell: (r) => pcs(r.requiredQty), numeric: true, minWidth: 160 },
    { id: 'fgQty', header: 'FG Gudang', cell: (r) => pcs(r.fgQty), numeric: true, minWidth: 120 },
    { id: 'beforeCheckQty', header: 'Before Check', cell: (r) => pcs(r.beforeCheckQty), numeric: true, critical: true, minWidth: 130 },
    { id: 'acceptedQty', header: 'QC Accepted, belum FG', cell: (r) => pcs(r.acceptedQty), numeric: true, minWidth: 175 },
    { id: 'wo', header: 'Sisa WO untuk kebutuhan', cell: (r) => pcs(r.allocated.wo), numeric: true, minWidth: 175 },
    { id: 'netQty', header: 'Kebutuhan SPK net', cell: (r) => pcs(r.netQty), numeric: true, critical: true, minWidth: 150 },
    { id: 'proposedQty', header: 'Usulan sesuai batch', cell: (r) => pcs(r.proposedQty), numeric: true, critical: true, minWidth: 160 },
    { id: 'material', header: 'Estimasi kapasitas bahan', cell: (r) => pcs(r.materialCapacityQty), numeric: true, minWidth: 180 },
    { id: 'planQty', header: 'Total plan edit', cell: (r) => pcs(r.planQty), numeric: true, minWidth: 135 },
    {
      id: 'status',
      header: 'Tindakan',
      cell: (r) => <Badge tone={r.status === 'WAIT_QC' || r.status === 'MATERIAL_SHORT' ? 'warning' : 'neutral'}>{STATUSES[r.status] ?? r.status}</Badge>,
      minWidth: 155,
    },
  ];
  if (me.isPending) return <LoadingState title="Memuat Daily Recovery" />;
  if (!canView)
    return (
      <div className="p-6">
        <PermissionDeniedState
          title="Daily Recovery belum tersedia untuk role ini"
          description="Akses membutuhkan role Planner atau pembaca planning pada plant terkait."
        />
      </div>
    );
  if (!plant)
    return (
      <div className="p-6">
        <EmptyState title="Pilih plant untuk planning" description="Akun ini perlu akses ke plant terlebih dahulu." />
      </div>
    );

  return (
    <EnterprisePage
      title="Daily Recovery Planner"
      description="Review kebutuhan kirim, hasil yang menunggu QC, dan rencana produksi per shift."
      actions={
        <Button variant="secondary" onClick={() => query.refetch()} disabled={query.isFetching}>
          <RefreshCw className="size-4" aria-hidden="true" />
          Refresh posisi
        </Button>
      }
    >
      {notice ? <InlineAlert tone="success" title={notice} className="mb-4" /> : null}
      <div className="mb-4 flex flex-wrap items-end justify-between gap-4 rounded-lg border border-line bg-surface p-4">
        <Field label="Tanggal target produksi / kirim">
          {(control) => (
            <DatePicker
              {...control}
              value={targetDate as IsoDate}
              min={today as IsoDate}
              label="Tanggal target produksi / kirim"
              onChange={(value) => value && setParams({ targetDate: value })}
            />
          )}
        </Field>
        <p className="max-w-xl text-xs leading-5 text-ink-2">
          Before Check dihitung sebagai suplai proyeksi untuk planning. Pengiriman mengikuti FG yang siap pakai. Qty stok per item dapat dipakai bersama
          beberapa customer. Alokasi kebutuhan dihitung sekali sebelum filter.
        </p>
      </div>
      <ListWorkspace
        label="Daily Recovery"
        toolbar={
          <>
            <div className="flex flex-wrap items-center gap-3">
              <ListSearch label="Cari customer atau part" placeholder="Cari customer, part, atau part no…" value={search} onChange={(q) => setParams({ q })} />
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
          label="Daily Recovery per customer dan part"
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
              title="Belum ada part untuk filter ini"
              description="Siapkan mapping customer–part aktif dan jadwal delivery, atau sesuaikan filter customer."
            />
          }
          rowActions={{ primary: (r) => ({ id: 'review', label: 'Review / Plan', icon: ClipboardList, onSelect: () => setDetailId(r.id) }) }}
        />
      </ListWorkspace>
      <p className="mt-3 text-xs text-ink-2">Geser tabel ke kanan untuk melihat posisi QC, sisa WO, kapasitas bahan, dan rencana produksi.</p>
      {query.data ? (
        <p className="mt-3 text-xs text-ink-2">
          Posisi live {query.data.meta.asOf} · Aktual tanggal produksi {query.data.meta.today} · {query.data.meta.formulaCode} (berlaku 18 Nov 2026)
        </p>
      ) : null}
      <Drawer open={!!selected} onOpenChange={(open) => !open && setDetailId(null)}>
        <DrawerContent title={selected ? `${selected.customerCode} · ${selected.itemName}` : 'Review planning'} closeLabel="Tutup review">
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
                setNotice('Rencana shift tersimpan');
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
    { key: 'minimumQty', label: 'Minimum SPK (pcs)' },
    { key: 'batchQty', label: 'Pcs per batch material', hint: 'Contoh 22 pcs/sheet membulatkan minimum 500 menjadi 506.' },
    { key: 'bufferQty', label: 'Tambahan target buffer (pcs)' },
    { key: 'shift1Qty', label: 'Plan Shift I (pcs)' },
    { key: 'shift2Qty', label: 'Plan Shift II (pcs)' },
    { key: 'shift3Qty', label: 'Plan Shift III (pcs)' },
  ];
  return (
    <div tabIndex={0} role="region" aria-label="Detail rencana produksi" className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5 text-[13px]">
      <p className="text-ink-2">
        {row.partNo} · Target {targetDate} · Backlog {pcs(row.backlogQty)} · Pengiriman hari ini {pcs(row.shippedTodayQty)}
      </p>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Suplai yang dipakai untuk kebutuhan ini</h3>
        <dl className="grid grid-cols-2 gap-2">
          {Object.entries({
            'FG dialokasikan / staging': row.allocated.fg,
            'Before Check proyeksi': row.allocated.beforeCheck,
            'QC Accepted, belum FG': row.allocated.accepted,
            'Sisa WO sebelum target': row.allocated.wo,
            'Kebutuhan SPK net': row.netQty,
            'Usulan sesuai batch': row.proposedQty,
            'Estimasi kapasitas bahan': row.materialCapacityQty,
          }).map(([label, qty]) => (
            <React.Fragment key={label}>
              <dt className="text-ink-2">{label}</dt>
              <dd className="text-right tabular-nums">{pcs(qty)}</dd>
            </React.Fragment>
          ))}
        </dl>
      </section>
      {row.inputsChanged && !additional ? (
        <InlineAlert tone="warning" title="Posisi berubah sejak rencana disimpan">
          Review angka terbaru dan simpan ulang sebelum membuat SPK.
        </InlineAlert>
      ) : null}
      {row.status === 'MATERIAL_SHORT' ? (
        <InlineAlert tone="warning" title="Estimasi material di bawah usulan">
          Review lot bahan dan jadwal kedatangannya sebelum melanjutkan release WO.
        </InlineAlert>
      ) : null}
      {additional ? (
        <InlineAlert tone="info" title={`Rencana sebelumnya sudah menjadi ${row.workOrderCode}`}>
          <Link className="text-link underline" href={`/ppic/work-orders?order=${row.workOrderId}`}>
            Buka SPK sebelumnya
          </Link>
          . Qty di bawah dapat disimpan sebagai rencana tambahan dengan alasan baru.
        </InlineAlert>
      ) : null}
      <form onSubmit={save} className="space-y-4">
        <h3 className="text-sm font-semibold">{additional ? 'Rencana tambahan' : 'Rencana produksi per shift'}</h3>
        <fieldset disabled={!canEdit || busy} className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
          <legend className="sr-only">Parameter dan jumlah produksi</legend>
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
          <Field label="Alasan / penyesuaian Planner" required className="col-span-2 max-sm:col-span-1">
            {(control) => (
              <Textarea {...control} value={values.reason} onChange={(e) => set('reason', e.target.value)} minLength={3} maxLength={500} required />
            )}
          </Field>
        </fieldset>
        {problem ? (
          <InlineAlert tone="danger" title="Rencana belum diproses">
            {problem}
          </InlineAlert>
        ) : null}
        <div className="flex flex-wrap gap-2">
          <Button type="submit" variant="primary" disabled={!canEdit || busy}>
            {busy ? 'Memproses…' : additional ? 'Simpan rencana tambahan' : 'Simpan rencana shift'}
          </Button>
          {!additional ? (
            <Button
              type="button"
              variant="secondary"
              onClick={create}
              disabled={!canCreateWo || busy || dirty || !row.planId || row.inputsChanged || Number(row.planQty) <= 0}
            >
              Buat SPK Draft
            </Button>
          ) : null}
        </div>
        <p className="text-xs text-ink-2">
          Simpan rencana sebelum membuat SPK. Qty dapat disesuaikan dengan alasan. SPK berikutnya mengikuti alur persetujuan WO.
        </p>
      </form>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Lot & posisi barang</h3>
        <div tabIndex={0} role="region" aria-label="Sumber saldo per lot" className="overflow-x-auto rounded-md border border-line">
          <table className="w-full text-left text-xs">
            <thead className="bg-subtle text-ink-2">
              <tr>
                <th className="p-2">Lot / lokasi</th>
                <th className="p-2">Status</th>
                <th className="p-2 text-right">Qty</th>
              </tr>
            </thead>
            <tbody>
              {row.lots.map((lot) => (
                <tr className="border-t border-line" key={lot.id}>
                  <td className="p-2">
                    {lot.lot_no ?? 'Tanpa lot'}
                    <br />
                    {lot.location_code}
                  </td>
                  <td className="p-2">{lot.stock_status === 'PENDING_QC' ? 'Before Check' : lot.stock_status}</td>
                  <td className="p-2 text-right">
                    {lot.qty} {lot.uom}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!row.lots.length ? <p className="mt-2 text-ink-2">Belum ada saldo lot untuk part ini.</p> : null}
      </section>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Hasil aktual produksi hari ini, per item</h3>
        {row.production.length ? (
          row.production.map((p, index) => (
            <p key={`${p.doc_no}-${index}`} className="mb-2">
              {p.wo_no} · {p.lot_no ?? 'Tanpa lot'} · {p.shift_code ?? 'Shift belum ditetapkan'} · {pcs(p.qty)}
            </p>
          ))
        ) : (
          <p className="text-ink-2">Belum ada laporan hasil produksi pada tanggal produksi ini.</p>
        )}
      </section>
      <section>
        <h3 className="mb-2 text-sm font-semibold">Sisa WO & sumber kebutuhan</h3>
        {row.workOrders.map((w) => (
          <p className="mb-2" key={w.id}>
            <Link className="text-link underline" href={`/ppic/work-orders?order=${w.id}`}>
              {w.wo_no}
            </Link>{' '}
            · {w.status} · target {w.due} · sisa {pcs(w.remaining)}
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

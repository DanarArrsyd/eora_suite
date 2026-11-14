'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, DomainStatusBadge, EnterprisePage } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState, Field, InlineAlert, Input, LoadingState, PermissionDeniedState, Select } from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M4 step 7b (doc 33 §1.7, D-115, D-116, doc 04 row 11, BR-FG-03, BR-DEM-10): Delivery → Shipments on the live API.
 * Delivery or the FG warehouse ships what is staged for one customer — several schedule lines, possibly of different
 * orders — with the vehicle and driver. Delivered follows the posted shipment; a wrong one is corrected by its reversal.
 */

type ShipmentRow = {
  id: string;
  code: string;
  version: number;
  status: 'POSTED' | 'REVERSED';
  plantCode: string;
  customerCode: string;
  customerName: string;
  vehicleNo: string | null;
  driverName: string | null;
  lines: number;
  orders: string | null;
  shipped: string | null;
  note: string | null;
  postedByName: string | null;
  postedAt: string;
};

type Staged = {
  scheduleLineId: string;
  soNo: string;
  lineNo: number;
  scheduleNo: number;
  requirementDate: string;
  customerPoNo: string | null;
  itemCode: string;
  itemName: string;
  stockLineId: string;
  locationCode: string;
  lotNo: string | null;
  stagedQty: string;
  outstandingQty: string;
  uomCode: string;
  uomDecimals: number;
};

type Posted = { data: { docNo: string; replayed: boolean } };

const SHIPMENT_STATUS = [
  { value: 'POSTED', label: 'Posted' },
  { value: 'REVERSED', label: 'Reversed' },
];

/** A quantity as an integer at the ledger's scale (numeric 20,6): comparisons without float drift. */
const scaled = (qty: string): bigint => {
  const [whole = '0', fraction = ''] = qty.split('.');
  return BigInt(whole + fraction.padEnd(6, '0').slice(0, 6));
};
const keyOf = (r: Staged) => `${r.scheduleLineId}|${r.stockLineId}`;

export const SHIPMENTS: MasterResource<ShipmentRow> = {
  id: 'shipments',
  resource: 'delivery.shipments',
  permission: { view: 'delivery.shipment.view', create: 'delivery.shipment.post', label: 'shipments' },
  title: 'Shipments',
  description:
    'Staged goods shipped to customers. Delivered quantities and schedule-line status follow the posted shipments; a wrong shipment is corrected by its reversal.',
  itemLabel: 'shipments',
  singular: 'shipment',
  createLabel: 'Ship',
  createHref: '/delivery/shipments?new=1',
  searchPlaceholder: 'Search shipment…',
  rowLabel: (r) => `${r.code} ${r.customerCode}`,
  defaultSort: [{ id: 'postedAt', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: SHIPMENT_STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'postedAt', header: 'Shipped', lead: true, sortable: true, cell: (r) => when(r.postedAt), sub: (r) => <span className="font-mono">{r.code}</span> },
    { id: 'customerCode', header: 'Customer', cell: (r) => r.customerCode, sub: (r) => r.orders ?? undefined },
    { id: 'vehicleNo', header: 'Vehicle', cell: (r) => r.vehicleNo ?? '—', sub: (r) => r.driverName ?? undefined },
    { id: 'lines', header: 'Lines', numeric: true, cell: (r) => r.lines },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  facts: (r) => [
    { label: 'Shipped', value: when(r.postedAt) },
    { label: 'Customer', value: `${r.customerCode} · ${r.customerName}` },
    { label: 'Plant', value: r.plantCode },
    { label: 'Vehicle', value: r.vehicleNo ?? '—' },
    { label: 'Driver', value: r.driverName ?? '—' },
    { label: 'Shipped goods', value: r.shipped ?? '—' },
    { label: 'Note', value: r.note ?? '—' },
    { label: 'By', value: r.postedByName ?? '—' },
    { label: 'Status', value: <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Shipments are made on their own page.')),
  update: () => Promise.reject(new Error('A posted shipment is corrected by its reversal.')),
  readOnly: () => 'A posted shipment is corrected by its reversal.',
};

/** Delivery → Shipments: the list, or the ship page with `?new=1`. */
export function ShipmentsScreen() {
  return useSearchParams()?.get('new') ? <ShipPage /> : <MasterScreen config={SHIPMENTS} />;
}

function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

function ShipPage() {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canShip = permissions.includes('delivery.shipment.post');
  const router = useRouter();
  const client = useQueryClient();
  const plants = me.data?.plants ?? [];
  const [plantId, setPlantId] = React.useState('');
  const [customerId, setCustomerId] = React.useState('');
  const [qty, setQty] = React.useState<Record<string, string> | null>(null);
  const [vehicle, setVehicle] = React.useState('');
  const [driver, setDriver] = React.useState('');
  const [note, setNote] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [confirming, setConfirming] = React.useState(false);
  // one key per shipment attempt: a retried post replays instead of shipping twice (ADR-0014)
  const key = React.useRef(`shp-${crypto.randomUUID()}`);
  const plant = plantId || me.data?.activePlantId || plants[0]?.id || '';

  const customers = useQuery({
    queryKey: ['master', 'core.customers', 'active'],
    queryFn: ({ signal }) => queryAll<{ id: string; code: string; name: string }>('core.customers', { status: 'ACTIVE' }, signal, 'code'),
    enabled: canShip,
  });
  const staged = useQuery({
    queryKey: ['master', 'delivery.staged', plant, customerId],
    queryFn: async () =>
      (await call(() => api.GET('/delivery/staged', { params: { query: { plantId: plant, customerId } } }))) as unknown as { data: Staged[] },
    enabled: canShip && !!plant && !!customerId,
  });

  if (me.isPending) return <LoadingState title="Loading" />;
  if (!canShip) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Shipping is not available for your roles" description="Ask a tenant administrator for a delivery or FG warehouse role." />
      </div>
    );
  }

  const rows = staged.data?.data ?? [];
  // what is staged is proposed in full; change it for a partial shipment
  const values = qty ?? Object.fromEntries(rows.map((r) => [keyOf(r), r.stagedQty]));
  const chosen = rows.filter((r) => (values[keyOf(r)] ?? '') !== '');
  const check = (): string | null => {
    if (!customerId) return 'Choose the customer.';
    if (chosen.length === 0) return 'Enter the quantity of at least one staged line.';
    for (const r of chosen) {
      const q = values[keyOf(r)]!;
      const name = `${r.soNo} ${r.lineNo}/${r.scheduleNo} ${r.lotNo ?? 'no lot'}`;
      if (!/^\d+(\.\d+)?$/.test(q) || scaled(q) <= 0n) return `${name}: enter a quantity above zero.`;
      const precision = unitPrecisionError(q, r.uomCode, r.uomDecimals);
      if (precision) return `${name}: ${precision}`;
      if (scaled(q) > scaled(r.stagedQty)) return `${name}: only ${r.stagedQty} ${r.uomCode} is staged for it.`;
    }
    return null;
  };
  const ship = async () => {
    const r = (await call(() =>
      api.POST('/delivery/shipments', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: {
          plantId: plant,
          customerId,
          vehicleNo: vehicle.trim() || null,
          driverName: driver.trim() || null,
          note: note.trim() || null,
          lines: chosen.map((x) => ({ scheduleLineId: x.scheduleLineId, stockLineId: x.stockLineId, qty: values[keyOf(x)]! })),
        },
      }),
    )) as Posted;
    key.current = `shp-${crypto.randomUUID()}`;
    setQty(null);
    setVehicle('');
    setDriver('');
    setNote('');
    setProblem(null);
    setNotice(r.data.replayed ? `${r.data.docNo} was already shipped; nothing new was posted` : `${r.data.docNo} shipped`);
    void client.invalidateQueries({ queryKey: ['master'] });
  };
  const customer = (customers.data ?? []).find((c) => c.id === customerId);

  return (
    <EnterprisePage
      title="Ship"
      description="Ship what is staged for one customer. Every schedule line of that customer with staged goods is listed; change a quantity for a partial shipment."
      actions={
        <Button onClick={() => router.push('/delivery/shipments')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All shipments
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
          Delivered quantities of the schedule lines now include it.
        </InlineAlert>
      ) : null}
      {problem ? (
        <InlineAlert tone="danger" title="Not shipped" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <Card className="mb-4">
        <CardHeader>
          <CardTitle>Shipment</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            {plants.length > 1 ? (
              <Field label="Plant" required>
                {(control) => (
                  <Select
                    {...control}
                    value={plant}
                    onValueChange={(v) => {
                      setPlantId(v);
                      setQty(null);
                    }}
                    options={plants.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))}
                  />
                )}
              </Field>
            ) : null}
            <Field label="Customer" required>
              {(control) => (
                <Select
                  {...control}
                  value={customerId}
                  onValueChange={(v) => {
                    setCustomerId(v);
                    setQty(null);
                    setProblem(null);
                  }}
                  placeholder={customers.isPending ? 'Loading…' : 'Choose a customer'}
                  options={(customers.data ?? []).map((c) => ({ value: c.id, label: `${c.code} · ${c.name}` }))}
                />
              )}
            </Field>
            <Field label="Vehicle number">
              {(control) => <Input {...control} maxLength={30} value={vehicle} onChange={(e) => setVehicle(e.target.value)} />}
            </Field>
            <Field label="Driver">{(control) => <Input {...control} maxLength={80} value={driver} onChange={(e) => setDriver(e.target.value)} />}</Field>
            <Field label="Note" className="col-span-2 max-md:col-span-1">
              {(control) => <Input {...control} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />}
            </Field>
          </div>
        </CardContent>
      </Card>

      {customerId ? (
        <Card>
          <CardHeader>
            <CardTitle>Staged for {customer?.code ?? 'the customer'}</CardTitle>
          </CardHeader>
          <CardContent>
            {staged.isError ? <InlineAlert tone="danger" title="Staged goods could not be loaded" className="mb-3" /> : null}
            {staged.isPending ? (
              <LoadingState title="Loading staged goods" />
            ) : rows.length === 0 ? (
              <EmptyState
                title="Nothing is staged for this customer"
                description="Allocate and pick finished goods for its schedule lines first (Allocation & Pick)."
              />
            ) : (
              <ul className="m-0 flex list-none flex-col divide-y divide-line p-0">
                {rows.map((r) => (
                  <li key={keyOf(r)} className="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_160px] items-center gap-3 py-3 first:pt-0 max-md:grid-cols-1">
                    <div className="min-w-0 text-[13.5px]">
                      <span className="font-mono text-ink">{`${r.soNo} · ${r.lineNo}/${r.scheduleNo}`}</span>
                      <div className="text-[13px] text-ink-2">
                        {r.itemCode} · due {r.requirementDate}
                        {r.customerPoNo ? ` · PO ${r.customerPoNo}` : ''}
                      </div>
                    </div>
                    <div className="text-[13px] text-ink-2 tabular-nums">
                      {r.locationCode} · <span className="font-mono text-ink">{r.lotNo ?? 'no lot'}</span> · {r.stagedQty} {r.uomCode} staged,{' '}
                      {r.outstandingQty} to deliver
                    </div>
                    <Field label={`Ship (${r.uomCode})`}>
                      {(control) => (
                        <Input
                          {...control}
                          aria-label={`Ship ${r.soNo} ${r.lineNo}/${r.scheduleNo} ${r.lotNo ?? 'no lot'} (${r.uomCode})`}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={values[keyOf(r)] ?? ''}
                          onChange={(e) => setQty({ ...values, [keyOf(r)]: e.target.value.trim() })}
                        />
                      )}
                    </Field>
                  </li>
                ))}
              </ul>
            )}
            <div className="mt-4 flex justify-end">
              <Button
                variant="primary"
                disabled={rows.length === 0}
                onClick={() => {
                  const why = check();
                  setProblem(why);
                  if (!why) setConfirming(true);
                }}
              >
                Ship
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <ConfirmDialog
        open={confirming}
        onOpenChange={(v) => (v ? undefined : setConfirming(false))}
        title="Ship these goods"
        description={`${chosen.map((r) => `${r.soNo} ${r.lineNo}/${r.scheduleNo} ${values[keyOf(r)]} ${r.uomCode}`).join(', ')} to ${customer?.code ?? 'the customer'}${vehicle.trim() ? ` on ${vehicle.trim()}` : ''}. A posted shipment is corrected only by its reversal.`}
        confirmLabel="Ship"
        onConfirm={async () => {
          try {
            await ship();
          } catch (e) {
            setProblem(refusal(e, 'The shipment could not be posted.'));
          }
          setConfirming(false);
        }}
      />
    </EnterprisePage>
  );
}

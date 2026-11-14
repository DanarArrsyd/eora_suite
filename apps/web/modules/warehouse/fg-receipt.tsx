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
 * M4 step 6 (doc 33 §1.6, D-114, doc 04 row 10, BR-FG-01): Warehouse → FG Receipt on the live API. The FG warehouse
 * receives QC-released finished goods from the QC area into the FG rack it chooses, as unrestricted stock: the counted
 * quantity of each lot (the rest stays QC released), several lots in one receipt, posted directly.
 */

type ReceiptRow = {
  id: string;
  code: string;
  version: number;
  status: 'POSTED' | 'REVERSED';
  plantCode: string;
  locationCode: string | null;
  lines: number;
  parts: string | null;
  received: string | null;
  note: string | null;
  postedByName: string | null;
  postedAt: string;
};

type Location = { id: string; code: string; name: string; plantId: string; plantCode: string };

type Released = {
  id: string;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  qty: string;
  uomCode: string;
  uomDecimals: number;
  updatedAt: string;
};

type Posted = { data: { documentId: string; docNo: string; replayed: boolean } };

const RECEIPT_STATUS = [
  { value: 'POSTED', label: 'Posted' },
  { value: 'REVERSED', label: 'Reversed' },
];

/** A quantity as an integer at the ledger's scale (numeric 20,6): comparisons without float drift. */
const scaled = (qty: string): bigint => {
  const [whole = '0', fraction = ''] = qty.split('.');
  return BigInt(whole + fraction.padEnd(6, '0').slice(0, 6));
};

export const FG_RECEIPTS: MasterResource<ReceiptRow> = {
  id: 'fg-receipt',
  resource: 'warehouse.fg_receipts',
  permission: { view: 'warehouse.fg_receipt.view', create: 'warehouse.fg_receipt.post', label: 'FG receipts' },
  title: 'FG Receipt',
  description:
    'QC-released finished goods received from the QC area into the FG warehouse. Only a receipt changes FG stock; a quality release alone does not. A wrong receipt is corrected by its reversal.',
  itemLabel: 'receipts',
  singular: 'receipt',
  createLabel: 'Receive FG',
  createHref: '/warehouse/fg-receipt?new=1',
  searchPlaceholder: 'Search receipt…',
  rowLabel: (r) => `${r.code} ${r.locationCode ?? ''}`,
  defaultSort: [{ id: 'postedAt', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: RECEIPT_STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'postedAt', header: 'Received', lead: true, sortable: true, cell: (r) => when(r.postedAt), sub: (r) => <span className="font-mono">{r.code}</span> },
    { id: 'locationCode', header: 'FG location', cell: (r) => r.locationCode ?? '—', sub: (r) => r.plantCode },
    { id: 'received', header: 'Received', cell: (r) => r.parts ?? '—', sub: (r) => r.received ?? undefined },
    { id: 'lines', header: 'Lines', numeric: true, cell: (r) => r.lines },
    { id: 'postedByName', header: 'By', cell: (r) => r.postedByName ?? '—' },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  facts: (r) => [
    { label: 'Received', value: when(r.postedAt) },
    { label: 'Plant', value: r.plantCode },
    { label: 'FG location', value: r.locationCode ?? '—' },
    { label: 'Lots', value: r.received ?? '—' },
    { label: 'Note', value: r.note ?? '—' },
    { label: 'By', value: r.postedByName ?? '—' },
    { label: 'Status', value: <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Receipts are made on their own page.')),
  update: () => Promise.reject(new Error('A posted receipt is corrected by its reversal.')),
  readOnly: () => 'A posted receipt is corrected by its reversal.',
};

/** Warehouse → FG Receipt: the list, or the receive page with `?new=1`. */
export function FgReceiptScreen() {
  const params = useSearchParams();
  return params?.get('new') ? <ReceivePage /> : <MasterScreen config={FG_RECEIPTS} />;
}

function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

function ReceivePage() {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canReceive = permissions.includes('warehouse.fg_receipt.post');
  const router = useRouter();
  const client = useQueryClient();
  const [locationId, setLocationId] = React.useState('');
  const [qty, setQty] = React.useState<Record<string, string>>({});
  const [note, setNote] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [confirming, setConfirming] = React.useState(false);
  // one key per receipt attempt: a retried post replays instead of receiving twice (ADR-0014)
  const key = React.useRef(`fgr-${crypto.randomUUID()}`);

  const locations = useQuery({
    queryKey: ['master', 'core.locations', 'fg'],
    queryFn: ({ signal }) => queryAll<Location>('core.locations', { locationType: ['FG_WAREHOUSE'], status: 'ACTIVE' }, signal, 'code'),
    enabled: canReceive,
  });
  // the first FG location is proposed (D-114); FGW may choose another
  const location = (locations.data ?? []).find((l) => l.id === locationId) ?? locations.data?.[0];
  const released = useQuery({
    queryKey: ['master', 'inventory.stock', 'qc-released', location?.plantId],
    queryFn: ({ signal }) =>
      queryAll<Released>(
        'inventory.stock',
        { plantId: location!.plantId, locationType: ['QC_AREA'], stockStatus: ['QC_RELEASED'], itemType: ['FINISHED'] },
        signal,
        'itemCode',
      ),
    enabled: !!location,
  });

  if (me.isPending || (canReceive && locations.isPending)) return <LoadingState title="Loading" />;
  if (!canReceive) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Receiving FG is not available for your roles" description="Ask a tenant administrator for the FG warehouse role." />
      </div>
    );
  }

  const rows = released.data ?? [];
  const chosen = rows.filter((r) => (qty[r.id] ?? '') !== '');
  const check = (): string | null => {
    if (!location) return 'Choose the FG location to receive into.';
    if (chosen.length === 0) return 'Enter the counted quantity of at least one lot.';
    for (const r of chosen) {
      const q = qty[r.id]!;
      const name = `${r.itemCode}${r.lotNo ? ` ${r.lotNo}` : ''}`;
      if (!/^\d+(\.\d+)?$/.test(q) || scaled(q) <= 0n) return `${name}: enter a quantity above zero.`;
      const precision = unitPrecisionError(q, r.uomCode, r.uomDecimals);
      if (precision) return `${name}: ${precision}`;
      if (scaled(q) > scaled(r.qty)) return `${name}: only ${r.qty} ${r.uomCode} is QC released.`;
    }
    return null;
  };

  const receive = async () => {
    const result = (await call(() =>
      api.POST('/warehouse/fg-receipts', {
        params: { header: { 'Idempotency-Key': key.current } },
        body: {
          plantId: location!.plantId,
          locationId: location!.id,
          note: note.trim() || null,
          lines: chosen.map((r) => ({ stockLineId: r.id, qty: qty[r.id]! })),
        },
      }),
    )) as Posted;
    key.current = `fgr-${crypto.randomUUID()}`;
    setQty({});
    setNote('');
    setProblem(null);
    setNotice(
      result.data.replayed ? `${result.data.docNo} was already received; nothing new was posted` : `${result.data.docNo} received into ${location!.code}`,
    );
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  return (
    <EnterprisePage
      title="Receive FG"
      description="Move QC-released finished goods from the QC area into an FG rack. Enter what you counted; what you do not receive stays QC released."
      actions={
        <Button onClick={() => router.push('/warehouse/fg-receipt')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All receipts
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
          The goods are unrestricted FG stock now, available to allocate and ship.
        </InlineAlert>
      ) : null}
      {problem ? (
        <InlineAlert tone="danger" title="Not received" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <Card className="mb-4">
        <CardHeader>
          <CardTitle>Into</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <Field label="FG location" required hint="An active FG warehouse location; the first is proposed.">
              {(control) => (
                <Select
                  {...control}
                  value={location?.id ?? ''}
                  onValueChange={(v) => {
                    setLocationId(v);
                    setQty({});
                    setProblem(null);
                  }}
                  placeholder={(locations.data ?? []).length ? 'Choose an FG location' : 'No active FG location'}
                  disabled={(locations.data ?? []).length === 0}
                  options={(locations.data ?? []).map((l) => ({ value: l.id, label: `${l.plantCode} / ${l.code} · ${l.name}` }))}
                />
              )}
            </Field>
            <Field label="Note">{(control) => <Input {...control} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />}</Field>
          </div>
        </CardContent>
      </Card>

      {location ? (
        <Card>
          <CardHeader>
            <CardTitle>QC released at {location.plantCode}</CardTitle>
          </CardHeader>
          <CardContent>
            {released.isError ? <InlineAlert tone="danger" title="Released goods could not be loaded" className="mb-3" /> : null}
            {released.isPending ? (
              <LoadingState title="Loading released goods" />
            ) : rows.length === 0 ? (
              <EmptyState
                title="Nothing waits to be received"
                description="Finished goods appear here once quality releases them (or when they need no inspection)."
              />
            ) : (
              <ul className="m-0 flex list-none flex-col divide-y divide-line p-0">
                {rows.map((r) => (
                  <li key={r.id} className="grid grid-cols-[minmax(0,1fr)_minmax(0,160px)_180px] items-center gap-3 py-3 first:pt-0 max-md:grid-cols-1">
                    <div className="min-w-0">
                      <span className="font-mono text-[13.5px] font-semibold text-ink">{r.itemCode}</span>
                      <span className="ml-2 text-[13px] text-ink-2">{r.itemName}</span>
                    </div>
                    <div className="text-[13px] text-ink-2 tabular-nums">
                      {r.lotNo ? <span className="font-mono text-ink">{r.lotNo}</span> : 'No lot'} · {r.qty} {r.uomCode} released
                    </div>
                    <Field label={`Receive (${r.uomCode})`}>
                      {(control) => (
                        <Input
                          {...control}
                          aria-label={`Receive ${r.itemCode}${r.lotNo ? ` ${r.lotNo}` : ''} (${r.uomCode})`}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={qty[r.id] ?? ''}
                          onChange={(e) => setQty((all) => ({ ...all, [r.id]: e.target.value.trim() }))}
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
                Receive FG
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <ConfirmDialog
        open={confirming}
        onOpenChange={(v) => (v ? undefined : setConfirming(false))}
        title="Receive these goods"
        description={`${chosen.map((r) => `${r.itemCode}${r.lotNo ? ` ${r.lotNo}` : ''} ${qty[r.id]} ${r.uomCode}`).join(', ')} into ${location?.code ?? ''}. A posted receipt is corrected only by its reversal.`}
        confirmLabel="Receive FG"
        onConfirm={async () => {
          try {
            await receive();
          } catch (e) {
            setProblem(refusal(e, 'The receipt could not be posted.'));
          }
          setConfirming(false);
        }}
      />
    </EnterprisePage>
  );
}

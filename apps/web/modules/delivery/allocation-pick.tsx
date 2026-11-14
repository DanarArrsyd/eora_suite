'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, EnterprisePage } from '@eora/features/patterns';
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
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M4 step 7a (doc 33 §1.7, D-115, doc 04 rows 2 and 11, BR-FG-02, BR-DEM-10): Delivery → Allocation & Pick on the live
 * API. Delivery allocates FG to a firm schedule line from a FIFO proposal (oldest lot first) and may release what is
 * allocated, with the reason; the FG warehouse picks allocated FG to staging (part or all) and puts staged goods back
 * into an FG rack (unpick). On-hand stays until shipment; allocation lowers what is available to others.
 */

type OpenLine = {
  id: string;
  code: string;
  version: number;
  plantCode: string;
  customerCode: string;
  customerName: string;
  itemCode: string;
  itemName: string;
  lineNo: number;
  scheduleNo: number;
  customerPoNo: string | null;
  requirementDate: string;
  requiredQty: string;
  allocatedQty: string;
  stagedQty: string;
  deliveredQty: string;
  toAllocateQty: string;
  uomCode: string;
};

type LineDetail = {
  id: string;
  status: string;
  plantId: string;
  plantCode: string;
  soNo: string;
  lineNo: number;
  scheduleNo: number;
  customerPoNo: string | null;
  customerCode: string;
  customerName: string;
  itemCode: string;
  itemName: string;
  requirementDate: string;
  requiredQty: string;
  allocatedQty: string;
  stagedQty: string;
  deliveredQty: string;
  toAllocateQty: string;
  uomCode: string;
  uomDecimals: number;
  allocations: { id: string; stockLineId: string; locationCode: string; lotNo: string | null; qty: string }[];
  staged: { stockLineId: string; locationCode: string; lotNo: string | null; qty: string }[];
};

type Proposal = {
  toAllocate: string;
  lines: { stockLineId: string; locationCode: string; lotNo: string | null; availableQty: string; proposedQty: string }[];
};

type Location = { id: string; code: string; name: string; plantId: string };
type Posted = { data: { docNo: string; replayed: boolean } };

/** A quantity as an integer at the ledger's scale (numeric 20,6): sums and comparisons without float drift. */
const scaled = (qty: string): bigint => {
  const [whole = '0', fraction = ''] = qty.split('.');
  return BigInt(whole + fraction.padEnd(6, '0').slice(0, 6));
};
const isQty = (q: string) => /^\d+(\.\d+)?$/.test(q) && scaled(q) > 0n;
const lotOf = (lotNo: string | null) => lotNo ?? 'no lot';

export const OPEN_DEMAND: MasterResource<OpenLine> = {
  id: 'allocation-pick',
  resource: 'delivery.schedule_lines',
  permission: { view: 'delivery.shipment.view', create: 'delivery.shipment.create', label: 'deliveries' },
  noCreate: true,
  title: 'Allocation & Pick',
  description:
    'Firm schedule lines still to deliver. Delivery allocates finished goods to a line (oldest lot first); the FG warehouse picks what is allocated to staging. Nothing leaves stock until shipment.',
  itemLabel: 'schedule lines',
  singular: 'schedule line',
  searchPlaceholder: 'Search sales order…',
  rowLabel: (r) => `${r.code} line ${r.lineNo}/${r.scheduleNo} ${r.itemCode}`,
  openHref: (r) => `/delivery/allocation-pick?line=${r.id}`,
  defaultSort: [{ id: 'requirementDate', desc: false }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'requirementDate', header: 'Due', lead: true, sortable: true, cell: (r) => r.requirementDate, sub: (r) => r.plantCode },
    {
      id: 'code',
      header: 'Sales order',
      sortable: true,
      cell: (r) => <span className="font-mono">{`${r.code} · ${r.lineNo}/${r.scheduleNo}`}</span>,
      sub: (r) => r.customerCode,
    },
    { id: 'itemCode', header: 'Part', cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    { id: 'requiredQty', header: 'Required', numeric: true, cell: (r) => `${r.requiredQty} ${r.uomCode}` },
    { id: 'allocatedQty', header: 'Allocated', numeric: true, cell: (r) => r.allocatedQty },
    { id: 'stagedQty', header: 'Staged', numeric: true, cell: (r) => r.stagedQty },
    { id: 'toAllocateQty', header: 'To allocate', numeric: true, critical: true, cell: (r) => r.toAllocateQty },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Demand comes from sales orders.')),
  update: () => Promise.reject(new Error('Schedule lines are changed on their sales order.')),
  readOnly: () => 'Schedule lines are changed on their sales order.',
};

/** Delivery → Allocation & Pick: open demand, or one schedule line's page with `?line=<id>`. */
export function AllocationPickScreen() {
  const line = useSearchParams()?.get('line');
  return line ? <LinePage key={line} id={line} /> : <MasterScreen config={OPEN_DEMAND} />;
}

function refusal(e: unknown, fallback: string): string {
  if (e instanceof ApiError) {
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, fallback);
}

function LinePage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('delivery.shipment.view');
  const canAllocate = permissions.includes('delivery.shipment.create');
  const canPick = permissions.includes('delivery.shipment.post');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);
  const [allocQty, setAllocQty] = React.useState<Record<string, string> | null>(null);
  const [pickQty, setPickQty] = React.useState<Record<string, string>>({});
  const [backQty, setBackQty] = React.useState<Record<string, string>>({});
  const [stagingId, setStagingId] = React.useState('');
  const [fgId, setFgId] = React.useState('');
  const [confirm, setConfirm] = React.useState<null | 'allocate' | 'pick' | 'unpick'>(null);
  const [releasing, setReleasing] = React.useState<string | null>(null);
  // one key per attempt: a retried write replays instead of writing twice (ADR-0014)
  const key = React.useRef(`dlv-${crypto.randomUUID()}`);
  const fresh = () => {
    key.current = `dlv-${crypto.randomUUID()}`;
  };

  const detail = useQuery({
    queryKey: ['master', 'delivery.schedule_lines', 'one', id],
    queryFn: async () => (await call(() => api.GET('/delivery/schedule-lines/{line}', { params: { path: { line: id } } }))) as unknown as { data: LineDetail },
    enabled: canView,
    retry: false,
  });
  const l = detail.data?.data;
  const proposal = useQuery({
    queryKey: ['master', 'delivery.schedule_lines', 'proposal', id, l?.toAllocateQty],
    queryFn: async () =>
      (await call(() => api.GET('/delivery/schedule-lines/{line}/proposal', { params: { path: { line: id } } }))) as unknown as { data: Proposal },
    enabled: canAllocate && !!l && scaled(l.toAllocateQty) > 0n,
  });
  const locations = useQuery({
    queryKey: ['master', 'core.locations', 'delivery', l?.plantId],
    queryFn: ({ signal }) =>
      queryAll<Location & { locationType: string }>(
        'core.locations',
        { plantId: l!.plantId, locationType: ['STAGING', 'FG_WAREHOUSE'], status: 'ACTIVE' },
        signal,
        'code',
      ),
    enabled: canPick && !!l,
  });

  if (me.isPending || (canView && detail.isPending)) return <LoadingState title="Loading schedule line" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Delivery is not available for your roles" description="Ask a tenant administrator for a delivery or FG warehouse role." />
      </div>
    );
  }
  if (detail.isError || !l) {
    return (
      <div className="p-6 max-md:p-4">
        <ErrorState
          title="The schedule line could not be loaded"
          description="It does not exist, is outside the plants you can see, or the connection failed."
          action={
            <Button size="sm" onClick={() => router.push('/delivery/allocation-pick')}>
              Back to open demand
            </Button>
          }
        />
      </div>
    );
  }

  const proposed = proposal.data?.data.lines ?? [];
  // the FIFO proposal fills the form until delivery changes it
  const alloc = allocQty ?? Object.fromEntries(proposed.map((p) => [p.stockLineId, p.proposedQty === '0' ? '' : p.proposedQty]));
  const stagings = (locations.data ?? []).filter((x) => x.locationType === 'STAGING');
  const fgRacks = (locations.data ?? []).filter((x) => x.locationType === 'FG_WAREHOUSE');
  const staging = stagings.find((x) => x.id === stagingId) ?? stagings[0];
  const fgRack = fgRacks.find((x) => x.id === fgId) ?? fgRacks[0];
  const unit = l.uomCode;
  const done = (message: string) => {
    fresh();
    setProblem(null);
    setNotice(message);
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  const allocLines = proposed.filter((p) => (alloc[p.stockLineId] ?? '') !== '');
  const pickLines = l.allocations.filter((a) => (pickQty[a.id] ?? '') !== '');
  const backLines = l.staged.filter((s) => (backQty[s.stockLineId] ?? '') !== '');
  const check = (kind: 'allocate' | 'pick' | 'unpick'): string | null => {
    const rows =
      kind === 'allocate'
        ? allocLines.map((p) => ({ name: `${p.locationCode} ${lotOf(p.lotNo)}`, q: alloc[p.stockLineId]!, max: p.availableQty, what: 'available there' }))
        : kind === 'pick'
          ? pickLines.map((a) => ({ name: `${a.locationCode} ${lotOf(a.lotNo)}`, q: pickQty[a.id]!, max: a.qty, what: 'allocated' }))
          : backLines.map((s) => ({ name: `${s.locationCode} ${lotOf(s.lotNo)}`, q: backQty[s.stockLineId]!, max: s.qty, what: 'staged for this line' }));
    if (rows.length === 0) return 'Enter the quantity of at least one line.';
    let total = 0n;
    for (const r of rows) {
      if (!isQty(r.q)) return `${r.name}: enter a quantity above zero.`;
      const precision = unitPrecisionError(r.q, unit, l.uomDecimals);
      if (precision) return `${r.name}: ${precision}`;
      if (scaled(r.q) > scaled(r.max)) return `${r.name}: only ${r.max} ${unit} is ${r.what}.`;
      total += scaled(r.q);
    }
    if (kind === 'allocate' && total > scaled(l.toAllocateQty)) return `The line needs only ${l.toAllocateQty} ${unit} more.`;
    if (kind === 'pick' && !staging) return 'The plant has no active staging location.';
    if (kind === 'unpick' && !fgRack) return 'The plant has no active FG warehouse location.';
    return null;
  };
  const ask = (kind: 'allocate' | 'pick' | 'unpick') => {
    const why = check(kind);
    setProblem(why);
    if (!why) setConfirm(kind);
  };
  const run = async () => {
    if (confirm === 'allocate') {
      await call(() =>
        api.POST('/delivery/allocations', {
          params: { header: { 'Idempotency-Key': key.current } },
          body: { scheduleLineId: l.id, lines: allocLines.map((p) => ({ stockLineId: p.stockLineId, qty: alloc[p.stockLineId]! })) },
        }),
      );
      setAllocQty(null);
      done('Allocated');
    } else if (confirm === 'pick') {
      const r = (await call(() =>
        api.POST('/delivery/picks', {
          params: { header: { 'Idempotency-Key': key.current } },
          body: { plantId: l.plantId, stagingLocationId: staging!.id, note: null, lines: pickLines.map((a) => ({ allocationId: a.id, qty: pickQty[a.id]! })) },
        }),
      )) as Posted;
      setPickQty({});
      done(r.data.replayed ? `${r.data.docNo} was already picked; nothing new was posted` : `${r.data.docNo} picked to ${staging!.code}`);
    } else if (confirm === 'unpick') {
      const r = (await call(() =>
        api.POST('/delivery/unpicks', {
          params: { header: { 'Idempotency-Key': key.current } },
          body: {
            plantId: l.plantId,
            locationId: fgRack!.id,
            note: null,
            lines: backLines.map((s) => ({ scheduleLineId: l.id, stockLineId: s.stockLineId, qty: backQty[s.stockLineId]! })),
          },
        }),
      )) as Posted;
      setBackQty({});
      done(r.data.replayed ? `${r.data.docNo} was already put back; nothing new was posted` : `${r.data.docNo} put back into ${fgRack!.code}`);
    }
  };

  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Customer', value: `${l.customerCode} · ${l.customerName}` },
    { label: 'Customer PO', value: l.customerPoNo ?? '—' },
    { label: 'Part', value: `${l.itemCode} · ${l.itemName}` },
    { label: 'Due', value: l.requirementDate },
  ];
  const figures: [string, string][] = [
    ['Required', l.requiredQty],
    ['Allocated', l.allocatedQty],
    ['Staged', l.stagedQty],
    ['Delivered', l.deliveredQty],
    ['To allocate', l.toAllocateQty],
  ];

  return (
    <EnterprisePage
      title={`${l.soNo} · ${l.lineNo}/${l.scheduleNo}`}
      description={`${l.itemCode} for ${l.customerCode}, due ${l.requirementDate} at ${l.plantCode}.`}
      actions={
        <Button onClick={() => router.push('/delivery/allocation-pick')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          Open demand
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
        <InlineAlert tone="danger" title="Not done" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}

      <Card className="mb-4">
        <CardContent className="pt-5">
          <dl className="m-0 grid grid-cols-5 gap-3 max-md:grid-cols-2" aria-label="Quantities">
            {figures.map(([label, value]) => (
              <div key={label} className="rounded-md border border-line px-3 py-2">
                <dt className="text-[12.5px] text-ink-2">{label}</dt>
                <dd className="m-0 text-[17px] font-semibold text-ink tabular-nums">
                  {value} <span className="text-[12.5px] font-normal text-ink-2">{unit}</span>
                </dd>
              </div>
            ))}
          </dl>
          <dl className="m-0 mt-4 grid grid-cols-[140px_minmax(0,1fr)] gap-x-4 gap-y-2 text-[13.5px] max-sm:grid-cols-1 max-sm:gap-y-1">
            {facts.map((f) => (
              <React.Fragment key={f.label}>
                <dt className="text-ink-2 max-sm:mt-2">{f.label}</dt>
                <dd className="m-0 text-ink">{f.value}</dd>
              </React.Fragment>
            ))}
          </dl>
        </CardContent>
      </Card>

      {canAllocate && scaled(l.toAllocateQty) > 0n ? (
        <Card className="mb-4">
          <CardHeader>
            <CardTitle>Allocate</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="m-0 mb-3 text-[13px] text-ink-2">
              Proposed oldest lot first for the {l.toAllocateQty} {unit} still needed. Change the quantities if needed, then allocate.
            </p>
            {proposal.isPending ? (
              <LoadingState title="Loading available FG" />
            ) : proposed.length === 0 ? (
              <EmptyState title="No FG available" description="Receive finished goods into the FG warehouse first, or release them from another line." />
            ) : (
              <ul className="m-0 flex list-none flex-col divide-y divide-line p-0">
                {proposed.map((p) => (
                  <li key={p.stockLineId} className="grid grid-cols-[minmax(0,1fr)_180px] items-center gap-3 py-3 first:pt-0 max-md:grid-cols-1">
                    <div className="text-[13.5px]">
                      <span className="text-ink">{p.locationCode}</span> · <span className="font-mono text-ink">{lotOf(p.lotNo)}</span>
                      <span className="ml-2 text-[13px] text-ink-2 tabular-nums">
                        {p.availableQty} {unit} available
                      </span>
                    </div>
                    <Field label={`Allocate (${unit})`}>
                      {(control) => (
                        <Input
                          {...control}
                          aria-label={`Allocate from ${p.locationCode} ${lotOf(p.lotNo)} (${unit})`}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={alloc[p.stockLineId] ?? ''}
                          onChange={(e) => setAllocQty({ ...alloc, [p.stockLineId]: e.target.value.trim() })}
                        />
                      )}
                    </Field>
                  </li>
                ))}
              </ul>
            )}
            <div className="mt-4 flex justify-end">
              <Button variant="primary" disabled={proposed.length === 0} onClick={() => ask('allocate')}>
                Allocate
              </Button>
            </div>
          </CardContent>
        </Card>
      ) : null}

      <Card className="mb-4">
        <CardHeader>
          <CardTitle>Allocated</CardTitle>
        </CardHeader>
        <CardContent>
          {l.allocations.length === 0 ? (
            <p className="m-0 text-[13px] text-ink-2">Nothing is allocated to this line now.</p>
          ) : (
            <>
              {canPick ? (
                <div className="mb-3 grid grid-cols-2 gap-4 max-md:grid-cols-1">
                  <Field label="Pick to" hint="An active staging location of the plant; the first is proposed.">
                    {(control) => (
                      <Select
                        {...control}
                        value={staging?.id ?? ''}
                        onValueChange={setStagingId}
                        placeholder={stagings.length ? 'Choose staging' : 'No active staging location'}
                        disabled={stagings.length === 0}
                        options={stagings.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` }))}
                      />
                    )}
                  </Field>
                </div>
              ) : null}
              <ul className="m-0 flex list-none flex-col divide-y divide-line p-0">
                {l.allocations.map((a) => (
                  <li key={a.id} className="grid grid-cols-[minmax(0,1fr)_180px_auto] items-center gap-3 py-3 first:pt-0 max-md:grid-cols-1">
                    <div className="text-[13.5px]">
                      <span className="text-ink">{a.locationCode}</span> · <span className="font-mono text-ink">{lotOf(a.lotNo)}</span>
                      <span className="ml-2 text-[13px] text-ink-2 tabular-nums">
                        {a.qty} {unit} allocated
                      </span>
                    </div>
                    {canPick ? (
                      <Field label={`Pick (${unit})`}>
                        {(control) => (
                          <Input
                            {...control}
                            aria-label={`Pick from ${a.locationCode} ${lotOf(a.lotNo)} (${unit})`}
                            inputMode="decimal"
                            className="tabular-nums"
                            value={pickQty[a.id] ?? ''}
                            onChange={(e) => setPickQty({ ...pickQty, [a.id]: e.target.value.trim() })}
                          />
                        )}
                      </Field>
                    ) : (
                      <div />
                    )}
                    {canAllocate ? (
                      <Button size="sm" onClick={() => setReleasing(a.id)} aria-label={`Release ${a.locationCode} ${lotOf(a.lotNo)}`}>
                        Release
                      </Button>
                    ) : (
                      <div />
                    )}
                  </li>
                ))}
              </ul>
              {canPick ? (
                <div className="mt-4 flex justify-end">
                  <Button variant="primary" onClick={() => ask('pick')}>
                    Pick to staging
                  </Button>
                </div>
              ) : null}
            </>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Staged</CardTitle>
        </CardHeader>
        <CardContent>
          {l.staged.length === 0 ? (
            <p className="m-0 text-[13px] text-ink-2">Nothing is picked for this line.</p>
          ) : (
            <>
              {canPick ? (
                <div className="mb-3 grid grid-cols-2 gap-4 max-md:grid-cols-1">
                  <Field label="Put back into" hint="An active FG warehouse location; the goods return unallocated.">
                    {(control) => (
                      <Select
                        {...control}
                        value={fgRack?.id ?? ''}
                        onValueChange={setFgId}
                        placeholder={fgRacks.length ? 'Choose an FG rack' : 'No active FG location'}
                        disabled={fgRacks.length === 0}
                        options={fgRacks.map((x) => ({ value: x.id, label: `${x.code} · ${x.name}` }))}
                      />
                    )}
                  </Field>
                </div>
              ) : null}
              <ul className="m-0 flex list-none flex-col divide-y divide-line p-0">
                {l.staged.map((s) => (
                  <li key={s.stockLineId} className="grid grid-cols-[minmax(0,1fr)_180px] items-center gap-3 py-3 first:pt-0 max-md:grid-cols-1">
                    <div className="text-[13.5px]">
                      <span className="text-ink">{s.locationCode}</span> · <span className="font-mono text-ink">{lotOf(s.lotNo)}</span>
                      <span className="ml-2 text-[13px] text-ink-2 tabular-nums">
                        {s.qty} {unit} staged
                      </span>
                    </div>
                    {canPick ? (
                      <Field label={`Put back (${unit})`}>
                        {(control) => (
                          <Input
                            {...control}
                            aria-label={`Put back from ${s.locationCode} ${lotOf(s.lotNo)} (${unit})`}
                            inputMode="decimal"
                            className="tabular-nums"
                            value={backQty[s.stockLineId] ?? ''}
                            onChange={(e) => setBackQty({ ...backQty, [s.stockLineId]: e.target.value.trim() })}
                          />
                        )}
                      </Field>
                    ) : null}
                  </li>
                ))}
              </ul>
              {canPick ? (
                <div className="mt-4 flex justify-end">
                  <Button onClick={() => ask('unpick')}>Put back</Button>
                </div>
              ) : null}
            </>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirm !== null}
        onOpenChange={(v) => (v ? undefined : setConfirm(null))}
        title={confirm === 'allocate' ? 'Allocate these goods' : confirm === 'pick' ? 'Pick to staging' : 'Put back into the FG rack'}
        description={
          confirm === 'allocate'
            ? `${allocLines.map((p) => `${lotOf(p.lotNo)} ${alloc[p.stockLineId]}`).join(', ')} ${unit} reserved for this line; on-hand stays.`
            : confirm === 'pick'
              ? `${pickLines.map((a) => `${lotOf(a.lotNo)} ${pickQty[a.id]}`).join(', ')} ${unit} to ${staging?.code ?? ''}. A posted pick is corrected by putting it back.`
              : `${backLines.map((s) => `${lotOf(s.lotNo)} ${backQty[s.stockLineId]}`).join(', ')} ${unit} back to ${fgRack?.code ?? ''}, unallocated.`
        }
        confirmLabel={confirm === 'allocate' ? 'Allocate' : confirm === 'pick' ? 'Pick to staging' : 'Put back'}
        onConfirm={async () => {
          try {
            await run();
          } catch (e) {
            setProblem(refusal(e, 'It could not be done.'));
          }
          setConfirm(null);
        }}
      />
      <ConfirmDialog
        open={releasing !== null}
        onOpenChange={(v) => (v ? undefined : setReleasing(null))}
        title="Release this allocation"
        description="The goods become available again; nothing moves."
        confirmLabel="Release"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (reason) => {
          try {
            await call(() =>
              api.POST('/delivery/allocations/{allocation}/release', { params: { path: { allocation: releasing! } }, body: { reason: reason! } }),
            );
            done('Allocation released');
          } catch (e) {
            setProblem(refusal(e, 'The allocation could not be released.'));
          }
          setReleasing(null);
        }}
      />
    </EnterprisePage>
  );
}

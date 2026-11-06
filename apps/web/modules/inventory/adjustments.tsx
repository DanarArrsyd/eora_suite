'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, EnterprisePage, STATUS_CATALOGUE } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState, ErrorState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll, querySource } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { ApprovalPanel, when } from '@/modules/master/version-ui';
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M3 step 6 (doc 32 §1.6, doc 04 row 12, D-060, D-100): Inventory → Control → Adjustments on the live API. Inventory
 * control requests one stock line plus or minus with a reason code; a second person approves (posts) or rejects it on
 * the request's page. Nothing moves before approval.
 */

type Status = 'SUBMITTED' | 'REJECTED' | 'POSTED' | 'REVERSED';
type StockStatus = keyof (typeof STATUS_CATALOGUE)['stock'];

export type AdjustmentRow = {
  id: string;
  code: string | null;
  version: number;
  status: Status;
  plantId: string;
  plantCode: string;
  effectiveDate: string;
  reasonCode: string;
  note: string | null;
  direction: 'PLUS' | 'MINUS';
  itemId: string;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  locationCode: string;
  stockStatus: StockStatus;
  qty: string;
  uomCode: string;
  requestedById: string;
  requestedByName: string | null;
  submittedAt: string;
  decidedByName: string | null;
  decidedAt: string | null;
  rejectReason: string | null;
};

type StockLine = {
  id: string;
  plantId: string;
  locationId: string;
  itemId: string;
  lotId: string | null;
  stockStatus: StockStatus;
  qty: string;
  uomCode: string;
  uomDecimals: number;
};

/** D-100: the M3 reason codes; Other needs a note. */
export const REASONS: Option[] = [
  { value: 'COUNT_CORRECTION', label: 'Count correction' },
  { value: 'DAMAGE', label: 'Damage' },
  { value: 'FOUND', label: 'Found stock' },
  { value: 'OTHER', label: 'Other (explain in the note)' },
];
const DIRECTIONS: Option[] = [
  { value: 'MINUS', label: 'Remove from stock' },
  { value: 'PLUS', label: 'Add to stock' },
];
const STATUS_OPTIONS: Option[] = [
  { value: 'SUBMITTED', label: 'Submitted (waiting for approval)' },
  { value: 'POSTED', label: 'Posted' },
  { value: 'REJECTED', label: 'Rejected' },
  { value: 'REVERSED', label: 'Reversed' },
];
const reason = (code: string) => REASONS.find((r) => r.value === code)?.label ?? code;
const signed = (r: AdjustmentRow) => `${r.direction === 'PLUS' ? '+' : '−'}${r.qty} ${r.uomCode}`;
const label = (r: AdjustmentRow) => r.code ?? `${r.itemCode} adjustment request`;

async function stockLine(id: string): Promise<StockLine | undefined> {
  return (await queryAll<StockLine>('inventory.stock', { id }, undefined, 'itemCode'))[0];
}

type Submitted = { data: { documentId: string; replayed: boolean } };

const STOCK_STATUS_OPTIONS: Option[] = Object.entries(STATUS_CATALOGUE.stock).map(([value, e]) => ({ value, label: e.label }));
const BLANK = { stockLineId: '', locationId: '', itemId: '', lotId: '', stockStatus: 'UNRESTRICTED', direction: 'MINUS', qty: '', reasonCode: '', note: '' };

type Target = {
  plantId: string;
  locationId: string;
  itemId: string;
  lotId: string | null;
  stockStatus: StockStatus;
  uomCode: string;
  uomDecimals: number;
  /** On hand on a chosen stock line; null for a new line (nothing recorded there). */
  onHand: string | null;
};

/**
 * What the request corrects: a chosen stock line, or — to add found stock where none is recorded — a location, item,
 * lot and status named one by one. A field and its message when the form does not name one completely.
 */
async function resolveTarget(v: Record<string, string>): Promise<Target | { field: string; message: string }> {
  if (v.stockLineId) {
    const line = await stockLine(v.stockLineId);
    if (!line) return { field: 'stockLineId', message: 'That stock line is no longer there. Choose it again.' };
    return { ...line, onHand: line.qty };
  }
  if (v.direction !== 'PLUS') return { field: 'stockLineId', message: 'Choose the stock line to remove from.' };
  if (!v.locationId) return { field: 'locationId', message: 'Choose a stock line, or the location where the stock was found.' };
  if (!v.itemId) return { field: 'itemId', message: 'Choose the item that was found.' };
  const [location] = await queryAll<{ id: string; plantId: string }>('core.locations', { id: v.locationId });
  const [item] = await queryAll<{ id: string; lotControlled: boolean; baseUomCode: string; baseUomDecimals: number }>('core.items', { id: v.itemId });
  if (!location) return { field: 'locationId', message: 'That location is no longer there. Choose it again.' };
  if (!item) return { field: 'itemId', message: 'That item is no longer there. Choose it again.' };
  let lotId: string | null = null;
  if (item.lotControlled) {
    const [lot] = v.lotId ? await queryAll<{ id: string; itemId: string }>('inventory.lots', { id: v.lotId }) : [];
    if (!lot || lot.itemId !== item.id) return { field: 'lotId', message: 'This item is tracked by lot: choose one of its lots.' };
    lotId = lot.id;
  } else if (v.lotId) {
    return { field: 'lotId', message: 'This item is not tracked by lot. Leave the lot empty.' };
  }
  return {
    plantId: location.plantId,
    locationId: location.id,
    itemId: item.id,
    lotId,
    stockStatus: (v.stockStatus || 'UNRESTRICTED') as StockStatus,
    uomCode: item.baseUomCode,
    uomDecimals: item.baseUomDecimals,
    onHand: null,
  };
}

export const ADJUSTMENTS: MasterResource<AdjustmentRow & { code: string }> = {
  id: 'adjustments',
  resource: 'inventory.adjustments',
  permission: {
    view: 'inventory.adjustment.view',
    create: 'inventory.adjustment.create',
    label: 'stock adjustments',
    alsoView: ['inventory.adjustment.approve'],
  },
  title: 'Adjustments',
  description:
    'Corrections of stock on hand, each with its reason. Inventory control requests one; a second person approves it, and only then is the stock changed. Unexplained differences are investigated, not adjusted away.',
  itemLabel: 'adjustments',
  singular: 'adjustment request',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.code ?? 'adjustment request'} ${r.itemCode} ${r.lotNo ?? 'no lot'} ${signed(r)}`,
  openHref: (r) => `/inventory/adjustments?request=${r.id}`,
  defaultSort: [{ id: 'submittedAt', desc: true }],
  filters: [
    PLANT_FILTER,
    { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS },
    { key: 'reasonCode', type: 'multiselect', source: 'static', label: 'Reason', options: REASONS },
  ],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'submittedAt',
      header: 'Requested',
      lead: true,
      sortable: true,
      cell: (r) => when(r.submittedAt),
      sub: (r) => (r.code ? <span className="font-mono">{r.code}</span> : (r.requestedByName ?? undefined)),
    },
    { id: 'itemCode', header: 'Item', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.lotNo ?? 'No lot' },
    {
      id: 'locationCode',
      header: 'Location',
      cell: (r) => r.locationCode,
      sub: (r) => `${r.plantCode} · ${STATUS_CATALOGUE.stock[r.stockStatus]?.label ?? r.stockStatus}`,
    },
    { id: 'qty', header: 'Change', numeric: true, critical: true, cell: signed },
    { id: 'reasonCode', header: 'Reason', cell: (r) => reason(r.reasonCode) },
    {
      id: 'status',
      header: 'Status',
      sortable: true,
      cell: (r) => <DomainStatusBadge domain="document" status={r.status} />,
    },
  ],
  fields: [
    {
      key: 'stockLineId',
      label: 'Stock line',
      kind: 'select',
      options: 'stockLines',
      hint: 'Item, lot, place and status to correct, with what is on hand now. Leave it empty to add found stock where none is recorded.',
    },
    // found stock where no line is recorded (D-100): its place, item and lot, added only
    { key: 'locationId', label: 'Location (new line)', kind: 'select', options: 'physicalLocations', hint: 'Only when no stock line is chosen.' },
    { key: 'itemId', label: 'Item (new line)', kind: 'select', options: 'items' },
    { key: 'lotId', label: 'Lot (new line)', kind: 'select', options: 'lots', hint: 'For an item tracked by lot: one of its lots.' },
    { key: 'stockStatus', label: 'Stock status (new line)', kind: 'select', options: STOCK_STATUS_OPTIONS },
    { key: 'direction', label: 'Change', kind: 'select', required: true, options: DIRECTIONS },
    { key: 'qty', label: 'Quantity', kind: 'number', required: true, decimals: 6, positive: true, hint: 'In the item’s base unit.' },
    { key: 'reasonCode', label: 'Reason', kind: 'select', required: true, options: REASONS },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500, hint: 'What was found, e.g. the count sheet or the damage. Required for Other.' },
  ],
  blank: BLANK,
  toForm: () => BLANK,
  check: async (v) => {
    const problems: Record<string, string> = {};
    if (v.reasonCode === 'OTHER' && (v.note ?? '').trim().length < 3) problems.note = 'Explain the adjustment when the reason is Other.';
    const target = await resolveTarget(v);
    if ('field' in target) return { ...problems, [target.field]: target.message };
    const precision = v.qty ? unitPrecisionError(v.qty, target.uomCode, target.uomDecimals) : undefined;
    if (precision) problems.qty = precision;
    else if (target.onHand !== null && v.direction === 'MINUS' && v.qty && Number(v.qty) > Number(target.onHand)) {
      problems.qty = `Only ${target.onHand} ${target.uomCode} is on hand there.`;
    }
    return problems;
  },
  confirm: (v) => ({
    title: 'Request this adjustment',
    description: `${v.direction === 'PLUS' ? 'Add' : 'Remove'} ${v.qty ?? ''} in the item’s base unit once a second person approves it. Nothing changes in stock until then.`,
    confirmLabel: 'Request adjustment',
  }),
  savedMessage: (result) =>
    (result as Submitted).data.replayed
      ? 'This request was already sent; it is waiting for approval'
      : 'Adjustment requested; it waits for a second person to approve it',
  savedNote: 'It is listed as Submitted until a second person approves or rejects it.',
  create: async (v, ctx) => {
    const target = await resolveTarget(v);
    if ('field' in target) throw new Error(target.message);
    return call(() =>
      api.POST('/inventory/adjustments', {
        params: { header: { 'Idempotency-Key': ctx.idempotencyKey } },
        body: {
          plantId: target.plantId,
          itemId: target.itemId,
          lotId: target.lotId,
          locationId: target.locationId,
          stockStatus: target.stockStatus,
          direction: v.direction as 'PLUS' | 'MINUS',
          qty: v.qty!,
          reasonCode: v.reasonCode as 'COUNT_CORRECTION' | 'DAMAGE' | 'FOUND' | 'OTHER',
          note: v.note || null,
        },
      }),
    );
  },
  update: () => Promise.reject(new Error('A request is never edited: reject it and request again.')),
};

/** Inventory → Control → Adjustments: the list, or one request's page with `?request=<id>`. */
export function AdjustmentsScreen() {
  const request = useSearchParams()?.get('request');
  return request ? <AdjustmentPage key={request} id={request} /> : <MasterScreen config={ADJUSTMENTS} />;
}

function AdjustmentPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('inventory.adjustment.view') || permissions.includes('inventory.adjustment.approve');
  const canApprove = permissions.includes('inventory.adjustment.approve');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  // one key per approval attempt of this page: a retried approval replays instead of posting twice (ADR-0014)
  const key = React.useRef(`adj-approve-${id}-${crypto.randomUUID()}`);

  const row = useQuery({
    queryKey: ['master', 'inventory.adjustments', 'one', id],
    queryFn: async ({ signal }) =>
      (
        await querySource<AdjustmentRow & { code: string }>('inventory.adjustments').list(
          { filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' },
          signal,
        )
      ).rows[0] ?? null,
    enabled: canView,
  });

  if (me.isPending || (canView && row.isPending)) return <LoadingState title="Loading adjustment" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Adjustments are not available for your roles"
          description="Ask a tenant administrator for a role that includes stock adjustments."
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
            title="The adjustment could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void row.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Adjustment not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/inventory/adjustments')}>
                Back to adjustments
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const mine = me.data?.user.id === r.requestedById;
  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ?? 'No lot' },
    { label: 'Location', value: `${r.locationCode} · ${r.plantCode}` },
    { label: 'Stock status', value: <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { label: 'Change', value: <span className="font-semibold tabular-nums">{signed(r)}</span> },
    { label: 'Reason', value: reason(r.reasonCode) },
    { label: 'Note', value: r.note ?? '—' },
    { label: 'Day', value: r.effectiveDate },
    ...(r.status === 'REJECTED' ? [{ label: 'Rejected because', value: r.rejectReason ?? '—' }] : []),
  ];

  return (
    <EnterprisePage
      title={label(r)}
      description={`${r.direction === 'PLUS' ? 'Adds' : 'Removes'} ${r.qty} ${r.uomCode} of ${r.itemCode} at ${r.locationCode}, ${reason(r.reasonCode).toLowerCase()}.`}
      meta={<DomainStatusBadge domain="document" status={r.status} />}
      actions={
        <Button onClick={() => router.push('/inventory/adjustments')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All adjustments
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
        <InlineAlert tone="info" title="You requested this adjustment" className="mb-4">
          Another person approves or rejects it.
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-lg:grid-cols-1">
        <Card>
          <CardHeader>
            <CardTitle>Request</CardTitle>
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
        <ApprovalPanel
          label="Adjustment"
          record={{
            status: r.status === 'POSTED' || r.status === 'REVERSED' ? 'APPROVED' : r.status,
            preparedByName: r.requestedByName,
            approvedByName: r.decidedByName,
            approvedAt: r.decidedAt,
          }}
          can={{ view: true, create: false, submit: false, approve: canApprove && !mine }}
          approvedNote={`Posted as ${r.code ?? 'an adjustment'}. A posted adjustment is corrected by its reversal.`}
          rejectNote="Nothing changes in stock. The requester sees the reason and can request again."
          act={async () => {
            const posted = (await call(() =>
              api.POST('/inventory/adjustments/{adjustment}/approve', {
                params: { path: { adjustment: id }, header: { 'Idempotency-Key': key.current } },
              }),
            )) as { data: { docNo: string } };
            key.current = `adj-approve-${id}-${crypto.randomUUID()}`;
            return posted;
          }}
          reject={(why) => call(() => api.POST('/inventory/adjustments/{adjustment}/reject', { params: { path: { adjustment: id } }, body: { reason: why } }))}
          onDone={(message) => {
            setNotice(message.replace(/approved$/, 'approved and posted'));
            void client.invalidateQueries({ queryKey: ['master'] });
          }}
        />
      </div>
    </EnterprisePage>
  );
}

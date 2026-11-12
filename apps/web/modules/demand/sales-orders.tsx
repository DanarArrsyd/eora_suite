'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { ConfirmDialog, DataTable, DomainStatusBadge, EnterprisePage, type EoraColumn } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState, ErrorState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { MasterEditor } from '@/modules/master/editor';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll } from '@/modules/master/source';
import type { FormValues, MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M4 step 1 (doc 33 §1.1, D-107, D-034, doc 16 §12): Sales Orders on the live API, in Demand and in PPIC. A planner
 * drafts an order for a customer at a plant, adds lines (customer part, customer PO number and line) with their delivery
 * schedule, and confirms it: the schedule lines become firm demand. A confirmed order gains lines and deliveries or
 * cancels a firm delivery with a reason; nothing firm is edited. A draft that is not used is cancelled, never deleted.
 */

type OrderStatus = 'DRAFT' | 'CONFIRMED' | 'CANCELLED';
type ScheduleStatus = 'DRAFT' | 'FIRM' | 'PARTIALLY_FULFILLED' | 'FULFILLED' | 'CANCELLED' | 'SUPERSEDED';

export type SalesOrderRow = {
  id: string;
  code: string;
  version: number;
  status: OrderStatus;
  plantId: string;
  plantCode: string;
  customerId: string;
  customerCode: string;
  customerName: string;
  customerPoNos: string | null;
  lineCount: number;
  nextDueDate: string | null;
  note: string | null;
  createdAt: string;
  createdByName: string | null;
  confirmedAt: string | null;
  confirmedByName: string | null;
  cancelledAt: string | null;
  cancelledByName: string | null;
  cancelReason: string | null;
};

type Schedule = {
  id: string;
  scheduleNo: number;
  requirementDate: string;
  qty: string;
  deliveredQty: string;
  outstandingQty: string;
  status: ScheduleStatus;
  cancelReason: string | null;
  cancelledAt: string | null;
  cancelledByName: string | null;
};

type Line = {
  id: string;
  lineNo: number;
  customerItemId: string;
  customerPartNo: string;
  customerDrawingRev: string | null;
  itemCode: string;
  itemName: string;
  uomCode: string;
  customerPoNo: string;
  customerPoLine: string;
  status: 'ACTIVE' | 'CANCELLED';
  qty: string;
  schedules: Schedule[];
};

type SalesOrder = SalesOrderRow & { lines: Line[]; plantToday: string };

const STATUS_OPTIONS: Option[] = [
  { value: 'DRAFT', label: 'Draft' },
  { value: 'CONFIRMED', label: 'Confirmed' },
  { value: 'CANCELLED', label: 'Cancelled' },
];

const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });

/** The page of the list this screen runs in (Demand or PPIC both list Sales Orders). */
function salesOrders(base: string): MasterResource<SalesOrderRow> {
  return {
    id: 'sales-orders',
    resource: 'demand.sales_orders',
    permission: { view: 'demand.sales_order.view', create: 'demand.sales_order.create', label: 'sales orders' },
    title: 'Sales Orders',
    description:
      'Customer orders with their delivery schedule. Each line is a customer part from one customer PO line; once confirmed, its deliveries are firm demand and are changed only by adding or cancelling deliveries.',
    itemLabel: 'sales orders',
    singular: 'sales order',
    searchPlaceholder: 'Search order, customer or customer PO…',
    rowLabel: (r) => `${r.code} ${r.customerCode}`,
    openHref: (r) => `${base}?order=${r.id}`,
    defaultSort: [{ id: 'createdAt', desc: true }],
    filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS }],
    filterPermissions: { plantId: 'master.org.view' },
    columns: [
      { id: 'code', header: 'Sales order', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.plantCode },
      { id: 'customerCode', header: 'Customer', sortable: true, cell: (r) => r.customerCode, sub: (r) => r.customerName },
      { id: 'customerPoNos', header: 'Customer PO', cell: (r) => r.customerPoNos ?? '—' },
      { id: 'lineCount', header: 'Lines', numeric: true, cell: (r) => String(r.lineCount) },
      { id: 'nextDueDate', header: 'Next delivery', cell: (r) => r.nextDueDate ?? '—' },
      { id: 'createdAt', header: 'Created', sortable: true, cell: (r) => when(r.createdAt), sub: (r) => r.createdByName ?? undefined },
      { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
    ],
    fields: [
      { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', hint: 'The plant that ships the order.' },
      { key: 'customerId', label: 'Customer', kind: 'select', required: true, options: 'customers' },
      { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
    ],
    blank: { plantId: '', customerId: '', note: '' },
    toForm: () => ({ plantId: '', customerId: '', note: '' }),
    createLabel: 'New sales order',
    savedMessage: (result) => `${(result as { data: SalesOrderRow }).data.code} drafted; add its lines next`,
    create: (v) => call(() => api.POST('/demand/sales-orders', { body: { plantId: v.plantId!, customerId: v.customerId!, note: v.note || null } })),
    update: () => Promise.reject(new Error('An order is changed on its own page.')),
  };
}

/** Demand → Sales Orders and PPIC → Sales Orders: the list, or one order's page with `?order=<id>`. */
export function SalesOrdersScreen({ base }: { base: string }) {
  const order = useSearchParams()?.get('order');
  const config = React.useMemo(() => salesOrders(base), [base]);
  return order ? <SalesOrderPage key={order} id={order} base={base} /> : <MasterScreen config={config} />;
}

/** A form's 422 field errors from the order endpoints, moved onto the drawer's own fields. */
function onFields(error: unknown): never {
  if (error instanceof ApiError && error.status === 422) {
    const fields: Record<string, string[]> = {};
    for (const [key, messages] of Object.entries(error.problem?.errors ?? {})) {
      const field = key.replace(/^lines\.\d+\./, '').replace(/^schedules\.\d+\./, '');
      fields[field] = messages;
    }
    const known = ['customerItemId', 'customerPoNo', 'customerPoLine', 'requirementDate', 'qty', 'lineId'];
    const stray = Object.keys(fields).find((k) => !known.includes(k));
    if (stray) {
      throw new ApiError(409, { ...error.problem!, detail: fields[stray]?.[0] ?? error.problem?.detail, errors: undefined });
    }
    throw new ApiError(422, { ...error.problem!, errors: fields });
  }
  throw error;
}

type LineBody = { customerItemId: string; customerPoNo: string; customerPoLine: string; schedules: { requirementDate: string; qty: string }[] };

/** The draft's lines as the PUT body (cancelled rows do not exist in a draft). */
const draftLines = (o: SalesOrder): LineBody[] =>
  o.lines.map((l) => ({
    customerItemId: l.customerItemId,
    customerPoNo: l.customerPoNo,
    customerPoLine: l.customerPoLine,
    schedules: l.schedules.map((s) => ({ requirementDate: s.requirementDate, qty: s.qty })),
  }));

/** A drawer form on this page (the master editor in create mode, with this order's own action). */
function drawerConfig(
  title: string,
  fields: MasterResource<SalesOrderRow>['fields'],
  create: (v: FormValues) => Promise<unknown>,
  savedMessage: string,
): MasterResource<SalesOrderRow> {
  return {
    ...salesOrders(''),
    singular: title,
    createLabel: title,
    submitLabel: title,
    fields,
    blank: Object.fromEntries(fields.map((f) => [f.key, ''])),
    savedMessage: () => savedMessage,
    create: (v) => create(v).catch(onFields),
  };
}

function SalesOrderPage({ id, base }: { id: string; base: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('demand.sales_order.view');
  const canDraft = permissions.includes('demand.sales_order.create');
  const canPost = permissions.includes('demand.sales_order.post');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [drawer, setDrawer] = React.useState<null | { kind: 'line' } | { kind: 'delivery'; line: Line }>(null);
  const [ask, setAsk] = React.useState<
    null | { kind: 'confirm' } | { kind: 'cancel' } | { kind: 'removeLine'; line: Line } | { kind: 'cancelDelivery'; line: Line; schedule: Schedule }
  >(null);

  const order = useQuery({
    queryKey: ['master', 'demand.sales_orders', 'one', id],
    queryFn: async () => (await call(() => api.GET('/demand/sales-orders/{order}', { params: { path: { order: id } } }))) as unknown as { data: SalesOrder },
    enabled: canView,
    retry: false,
  });
  const o = order.data?.data;
  const parts = useQuery({
    queryKey: ['master', 'picker', 'customerParts', o?.customerId],
    queryFn: async () =>
      (
        await queryAll<{ id: string; code: string; itemCode: string; itemName: string }>(
          'core.customer_items',
          { customerId: o!.customerId, status: 'ACTIVE' },
          undefined,
          'code',
        )
      ).map((p): Option => ({ value: p.id, label: `${p.code} · ${p.itemCode} ${p.itemName}` })),
    enabled: !!o && o.status !== 'CANCELLED',
    staleTime: 30_000,
  });

  const changed = (message: string) => {
    setNotice(message);
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  if (me.isPending || (canView && order.isPending)) return <LoadingState title="Loading sales order" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Sales orders are not available for your roles"
          description="Ask a tenant administrator for a role that includes sales orders."
        />
      </div>
    );
  }
  if (!o) {
    const missing = order.error instanceof ApiError && order.error.status === 404;
    return (
      <div className="p-6 max-md:p-4">
        {missing ? (
          <EmptyState
            title="Sales order not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push(base)}>
                Back to sales orders
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The sales order could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void order.refetch()}>
                Try again
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const draft = o.status === 'DRAFT';
  const confirmed = o.status === 'CONFIRMED';
  const mayChange = (draft && canDraft) || (confirmed && canPost);
  const putLines = (lines: LineBody[]) =>
    call(() => api.PUT('/demand/sales-orders/{order}/lines', { params: { path: { order: id } }, headers: ifMatch(o.version), body: { lines } as never }));

  const lineFields: MasterResource<SalesOrderRow>['fields'] = [
    {
      key: 'customerItemId',
      label: 'Customer part',
      kind: 'select',
      required: true,
      options: parts.data ?? [],
      hint: parts.isError
        ? 'The customer parts could not be loaded. Close and reopen to try again.'
        : `Active customer parts of ${o.customerCode} (finished items).`,
    },
    { key: 'customerPoNo', label: 'Customer PO number', kind: 'text', required: true, maxLength: 40 },
    {
      key: 'customerPoLine',
      label: 'Customer PO line',
      kind: 'text',
      required: true,
      maxLength: 20,
      hint: 'One customer PO line belongs to one sales order line.',
    },
    { key: 'requirementDate', label: 'First delivery date', kind: 'date', required: true, minDate: o.plantToday },
    {
      key: 'qty',
      label: 'Quantity',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: 'In the item’s base unit. Add more deliveries after saving.',
    },
  ];
  const deliveryFields: MasterResource<SalesOrderRow>['fields'] = [
    { key: 'requirementDate', label: 'Delivery date', kind: 'date', required: true, minDate: o.plantToday },
    { key: 'qty', label: 'Quantity', kind: 'number', required: true, decimals: 6, positive: true, hint: 'In the item’s base unit.' },
  ];

  const lineDrawer = drawerConfig(
    'Add line',
    lineFields,
    (v) => {
      const line: LineBody = {
        customerItemId: v.customerItemId!,
        customerPoNo: v.customerPoNo!,
        customerPoLine: v.customerPoLine!,
        schedules: [{ requirementDate: v.requirementDate!, qty: v.qty! }],
      };
      return draft
        ? putLines([...draftLines(o), line])
        : call(() => api.POST('/demand/sales-orders/{order}/lines', { params: { path: { order: id } }, headers: ifMatch(o.version), body: line as never }));
    },
    draft ? 'Line added to the draft' : 'Line added; its delivery is firm demand',
  );
  const deliveryLine = drawer?.kind === 'delivery' ? drawer.line : null;
  const deliveryDrawer = drawerConfig(
    deliveryLine ? `Add delivery to line ${deliveryLine.lineNo}` : 'Add delivery',
    deliveryFields,
    (v) =>
      draft
        ? putLines(
            draftLines(o).map((l, i) =>
              o.lines[i]!.id === deliveryLine!.id ? { ...l, schedules: [...l.schedules, { requirementDate: v.requirementDate!, qty: v.qty! }] } : l,
            ),
          )
        : call(() =>
            api.POST('/demand/sales-orders/{order}/schedule-lines', {
              params: { path: { order: id } },
              headers: ifMatch(o.version),
              body: { lineId: deliveryLine!.id, requirementDate: v.requirementDate!, qty: v.qty! },
            }),
          ),
    draft ? 'Delivery added to the draft' : 'Delivery added; it is firm demand',
  );

  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Customer', value: `${o.customerCode} · ${o.customerName}` },
    { label: 'Plant', value: o.plantCode },
    { label: 'Customer PO', value: o.customerPoNos ?? '—' },
    { label: 'Next delivery', value: o.nextDueDate ?? '—' },
    { label: 'Created', value: `${when(o.createdAt)}${o.createdByName ? ` · ${o.createdByName}` : ''}` },
    ...(o.confirmedAt ? [{ label: 'Confirmed', value: `${when(o.confirmedAt)}${o.confirmedByName ? ` · ${o.confirmedByName}` : ''}` }] : []),
    ...(o.status === 'CANCELLED' ? [{ label: 'Cancelled because', value: o.cancelReason ?? '—' }] : []),
    { label: 'Note', value: o.note ?? '—' },
  ];

  const scheduleColumns = (line: Line): EoraColumn<Schedule>[] => [
    {
      id: 'requirementDate',
      header: 'Delivery date',
      lead: true,
      cell: (s) => s.requirementDate,
    },
    { id: 'qty', header: 'Quantity', numeric: true, cell: (s) => `${s.qty} ${line.uomCode}` },
    { id: 'deliveredQty', header: 'Delivered', numeric: true, cell: (s) => s.deliveredQty },
    { id: 'outstandingQty', header: 'Outstanding', numeric: true, critical: true, cell: (s) => s.outstandingQty },
    {
      id: 'status',
      header: 'Status',
      cell: (s) => (
        <div className="flex flex-col items-start gap-0.5">
          <DomainStatusBadge domain="schedule" status={s.status} />
          {s.status === 'CANCELLED' && s.cancelReason ? (
            <span className="text-[12.5px] text-ink-2">
              {s.cancelReason}
              {s.cancelledByName ? ` · ${s.cancelledByName}` : ''}
            </span>
          ) : null}
        </div>
      ),
    },
    {
      id: 'actions',
      header: 'Actions',
      cell: (s) =>
        confirmed && canPost && s.status === 'FIRM' ? (
          <Button size="sm" variant="ghost" onClick={() => setAsk({ kind: 'cancelDelivery', line, schedule: s })}>
            Cancel delivery
          </Button>
        ) : draft && canDraft && line.schedules.length > 1 ? (
          <Button
            size="sm"
            variant="ghost"
            onClick={() =>
              void putLines(
                draftLines(o).map((l, i) =>
                  o.lines[i]!.id === line.id ? { ...l, schedules: l.schedules.filter((_, j) => line.schedules[j]!.id !== s.id) } : l,
                ),
              )
                .then(() => changed('Delivery removed from the draft'))
                .catch((e: unknown) => setNotice(e instanceof ApiError ? (e.problem?.detail ?? e.message) : 'The delivery could not be removed.'))
            }
          >
            Remove
          </Button>
        ) : null,
    },
  ];

  return (
    <EnterprisePage
      title={o.code}
      description={
        draft
          ? 'Draft: add the lines and their deliveries, then confirm. Nothing counts as demand before the order is confirmed.'
          : confirmed
            ? 'Confirmed: its deliveries are firm demand. Add lines or deliveries, or cancel a delivery with a reason; firm deliveries are never edited.'
            : 'Cancelled: kept for the record; it carries no demand.'
      }
      meta={<DomainStatusBadge domain="document" status={o.status} />}
      actions={
        <div className="flex flex-wrap gap-2">
          {draft && canDraft ? (
            <Button variant="ghost" onClick={() => setAsk({ kind: 'cancel' })}>
              Cancel draft
            </Button>
          ) : null}
          {mayChange ? (
            <Button onClick={() => setDrawer({ kind: 'line' })}>
              <Plus aria-hidden="true" className="size-4" />
              Add line
            </Button>
          ) : null}
          {draft && canPost ? (
            <Button variant="primary" disabled={o.lines.length === 0} onClick={() => setAsk({ kind: 'confirm' })}>
              Confirm order
            </Button>
          ) : null}
          <Button onClick={() => router.push(base)}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All sales orders
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
      {draft && !canPost && canDraft ? (
        <InlineAlert tone="info" title="Another planner confirms this order" className="mb-4">
          Your roles allow drafting sales orders, not confirming them.
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-lg:grid-cols-1">
        <div className="flex min-w-0 flex-col gap-4">
          {o.lines.length === 0 ? (
            <Card>
              <CardContent>
                <EmptyState
                  title="No lines yet"
                  description="Add a line for each customer PO line: the customer part, its PO number and line, and the first delivery."
                  action={
                    mayChange ? (
                      <Button size="sm" onClick={() => setDrawer({ kind: 'line' })}>
                        Add line
                      </Button>
                    ) : undefined
                  }
                />
              </CardContent>
            </Card>
          ) : (
            o.lines.map((line) => (
              <Card key={line.id}>
                <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <CardTitle>
                      Line {line.lineNo} · <span className="font-mono">{line.customerPartNo}</span>
                    </CardTitle>
                    <p className="m-0 mt-1 text-[13px] text-ink-2">
                      {line.itemCode} · {line.itemName} · PO {line.customerPoNo} line {line.customerPoLine} ·{' '}
                      <span className="font-semibold text-ink tabular-nums">
                        {line.qty} {line.uomCode}
                      </span>
                      {line.status === 'CANCELLED' ? ' · Cancelled' : ''}
                    </p>
                  </div>
                  {mayChange && line.status === 'ACTIVE' ? (
                    <div className="flex gap-2">
                      {draft ? (
                        <Button size="sm" variant="ghost" onClick={() => setAsk({ kind: 'removeLine', line })}>
                          Remove line
                        </Button>
                      ) : null}
                      <Button size="sm" onClick={() => setDrawer({ kind: 'delivery', line })}>
                        Add delivery
                      </Button>
                    </div>
                  ) : null}
                </CardHeader>
                <CardContent>
                  <DataTable
                    label={`Deliveries of line ${line.lineNo}`}
                    columns={scheduleColumns(line)}
                    rows={line.schedules}
                    getRowId={(s) => s.id}
                    rowLabel={(s) => `Delivery ${s.requirementDate} ${s.qty} ${line.uomCode}`}
                    status="ready"
                    sort={[]}
                    onSortChange={() => undefined}
                    density="compact"
                    empty={<EmptyState title="No deliveries" description="Every line has at least one delivery." />}
                  />
                </CardContent>
              </Card>
            ))
          )}
        </div>
        <Card>
          <CardHeader>
            <CardTitle>Order</CardTitle>
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

      <MasterEditor
        config={drawer?.kind === 'delivery' ? deliveryDrawer : lineDrawer}
        row={null}
        canEdit={mayChange}
        open={drawer !== null}
        onOpenChange={(v) => (v ? undefined : setDrawer(null))}
        onSaved={(message) => {
          setDrawer(null);
          changed(message);
        }}
      />
      <ConfirmDialog
        open={ask?.kind === 'confirm'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Confirm this order"
        description={`${o.lines.length === 1 ? 'Its line becomes' : `Its ${o.lines.length} lines become`} firm demand. After this, deliveries are only added or cancelled with a reason, never edited.`}
        confirmLabel="Confirm order"
        onConfirm={async () => {
          await call(() => api.POST('/demand/sales-orders/{order}/actions/confirm', { params: { path: { order: id } }, headers: ifMatch(o.version) }));
          setAsk(null);
          changed(`${o.code} confirmed; its deliveries are firm demand`);
        }}
      />
      <ConfirmDialog
        open={ask?.kind === 'cancel'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Cancel this draft"
        description="The draft stays in the list as Cancelled and its customer PO lines are free for another order. Its number is not reused."
        confirmLabel="Cancel draft"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          await call(() =>
            api.POST('/demand/sales-orders/{order}/actions/cancel', {
              params: { path: { order: id } },
              headers: ifMatch(o.version),
              body: { reason: why ?? '' },
            }),
          );
          setAsk(null);
          changed(`${o.code} cancelled`);
        }}
      />
      <ConfirmDialog
        open={ask?.kind === 'removeLine'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Remove this line"
        description="The line and its deliveries leave the draft. Nothing was firm yet."
        confirmLabel="Remove line"
        tone="danger"
        onConfirm={async () => {
          const target = ask?.kind === 'removeLine' ? ask.line.id : '';
          await putLines(draftLines(o).filter((_, i) => o.lines[i]!.id !== target));
          setAsk(null);
          changed('Line removed from the draft');
        }}
      />
      <ConfirmDialog
        open={ask?.kind === 'cancelDelivery'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Cancel this delivery"
        description={
          ask?.kind === 'cancelDelivery'
            ? `${ask.schedule.qty} ${ask.line.uomCode} on ${ask.schedule.requirementDate} stops counting as demand. It stays on the order as Cancelled with your reason.`
            : ''
        }
        confirmLabel="Cancel delivery"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          if (ask?.kind !== 'cancelDelivery') return;
          await call(() =>
            api.POST('/demand/sales-orders/{order}/schedule-lines/{schedule}/actions/cancel', {
              params: { path: { order: id, schedule: ask.schedule.id } },
              headers: ifMatch(o.version),
              body: { reason: why ?? '' },
            }),
          );
          setAsk(null);
          changed('Delivery cancelled');
        }}
      />
    </EnterprisePage>
  );
}

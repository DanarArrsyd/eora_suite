'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Pencil } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { ConfirmDialog, DataTable, DomainStatusBadge, EnterprisePage, type EoraColumn } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, EmptyState, ErrorState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { MasterEditor } from '@/modules/master/editor';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import type { FormValues, MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M4 step 2 (doc 33 §1.2, D-109, G-MPS-6, BR-PLN-01): PPIC → Work Orders on the live API. A planner drafts a manual WO for
 * a finished item with its planning-exception reason, optionally for a firm delivery, and asks for release; a second
 * person releases it (BOM and routing versions are copied onto the WO with its material lines) or returns it with a
 * reason. A WO is cancelled with a reason, never deleted.
 */

type Status = 'DRAFT' | 'SUBMITTED' | 'RELEASED' | 'IN_PROGRESS' | 'ON_HOLD' | 'PRODUCTION_COMPLETE' | 'CLOSED' | 'CANCELLED';

export type WorkOrderRow = {
  id: string;
  code: string;
  version: number;
  status: Status;
  plantId: string;
  plantCode: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  qty: string;
  uomCode: string;
  dueDate: string;
  reasonCode: string;
  note: string | null;
  scheduleLineId: string | null;
  salesOrderId: string | null;
  salesOrderCode: string | null;
  customerCode: string | null;
  scheduleDate: string | null;
  scheduleQty: string | null;
  bomVersionNo: number | null;
  routingCode: string | null;
  routingVersionNo: number | null;
  standardYield: string | null;
  createdAt: string;
  createdByName: string | null;
  submittedById: string | null;
  submittedByName: string | null;
  submittedAt: string | null;
  releasedByName: string | null;
  releasedAt: string | null;
  returnReason: string | null;
  cancelledByName: string | null;
  cancelReason: string | null;
};

type Material = {
  lineNo: number;
  itemId: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  grossQtyPer: string;
  requiredQty: string;
  conversionProfileVersion: number | null;
  /** D-110: issued so far (less reversals) and still at the work centers (less returns). */
  issuedQty: string;
  atProductionQty: string;
  /** The unit issued and at-production quantities are in (the material's base unit; the ledger posts in it). */
  baseUomCode: string;
};

type WorkOrder = WorkOrderRow & { materials: Material[]; plantToday: string };

/** G-MPS-6 planning-exception reasons (D-109). */
export const WO_REASONS: Option[] = [
  { value: 'URGENT_ORDER', label: 'Urgent customer order' },
  { value: 'NO_MPS_YET', label: 'No MPS yet' },
  { value: 'SAMPLE_TRIAL', label: 'Sample or trial' },
  { value: 'NG_REPLACEMENT', label: 'Replacement of NG output' },
  { value: 'OTHER', label: 'Other (explain in the note)' },
];
const reason = (code: string) => WO_REASONS.find((r) => r.value === code)?.label ?? code;
const STATUS_OPTIONS: Option[] = [
  { value: 'DRAFT', label: 'Draft' },
  { value: 'SUBMITTED', label: 'Waiting for release' },
  { value: 'RELEASED', label: 'Released' },
  { value: 'CANCELLED', label: 'Cancelled' },
];
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const BLANK = { plantId: '', itemId: '', qty: '', dueDate: '', reasonCode: '', note: '', scheduleLineId: '' };

function fields(minDate?: string): MasterResource<WorkOrderRow>['fields'] {
  return [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', createOnly: true },
    {
      key: 'itemId',
      label: 'Item',
      kind: 'select',
      required: true,
      options: 'finishedItems',
      hint: 'A finished item; its approved BOM and routing are copied at release.',
    },
    {
      key: 'qty',
      label: 'Quantity',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: 'In the item’s base unit; it may differ from the delivery it serves.',
    },
    {
      key: 'dueDate',
      label: 'Due date',
      kind: 'date',
      required: true,
      // the plant's calendar day once the WO exists; on creation the plant is not chosen yet, so the server checks it
      ...(minDate ? { minDate } : {}),
      hint: 'Today or later at the plant.',
    },
    { key: 'reasonCode', label: 'Reason (outside MPS)', kind: 'select', required: true, options: WO_REASONS },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500, hint: 'Required when the reason is Other.' },
    {
      key: 'scheduleLineId',
      label: 'For delivery (optional)',
      kind: 'select',
      options: 'firmDeliveries',
      hint: 'A firm delivery of this item at this plant. It does not change the demand.',
    },
  ];
}

function workOrders(base: string): MasterResource<WorkOrderRow> {
  return {
    id: 'work-orders',
    resource: 'planning.work_orders',
    permission: { view: 'planning.wo.view', create: 'planning.wo.create', label: 'work orders', alsoView: ['planning.wo.approve'] },
    title: 'Work Orders',
    description:
      'Manual work orders outside the MPS, each with its reason. A planner asks for release; a second person releases it, and the approved BOM and routing are copied onto it.',
    itemLabel: 'work orders',
    singular: 'work order',
    createLabel: 'New work order',
    searchPlaceholder: 'Search work order or item…',
    rowLabel: (r) => `${r.code} ${r.itemCode}`,
    openHref: (r) => `${base}?order=${r.id}`,
    defaultSort: [{ id: 'createdAt', desc: true }],
    filters: [
      PLANT_FILTER,
      { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS },
      { key: 'reasonCode', type: 'multiselect', source: 'static', label: 'Reason', options: WO_REASONS },
    ],
    filterPermissions: { plantId: 'master.org.view' },
    columns: [
      { id: 'code', header: 'Work order', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.plantCode },
      { id: 'itemCode', header: 'Item', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
      { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
      { id: 'dueDate', header: 'Due', sortable: true, cell: (r) => r.dueDate },
      {
        id: 'salesOrderCode',
        header: 'For delivery',
        cell: (r) => r.salesOrderCode ?? '—',
        sub: (r) => (r.customerCode ? `${r.customerCode} · ${r.scheduleDate}` : undefined),
      },
      { id: 'reasonCode', header: 'Reason', cell: (r) => reason(r.reasonCode) },
      {
        id: 'status',
        header: 'Status',
        sortable: true,
        cell: (r) => (
          <DomainStatusBadge
            domain="document"
            status={r.status === 'SUBMITTED' ? 'SUBMITTED' : r.status === 'RELEASED' ? 'RELEASED' : r.status === 'CANCELLED' ? 'CANCELLED' : 'DRAFT'}
          />
        ),
      },
    ],
    fields: fields(),
    blank: BLANK,
    toForm: () => BLANK,
    check: async (v): Promise<Record<string, string>> =>
      v.reasonCode === 'OTHER' && (v.note ?? '').trim().length < 3 ? { note: 'Explain the work order when the reason is Other.' } : {},
    savedMessage: (result) => `${(result as { data: WorkOrderRow }).data.code} drafted; ask for its release when it is ready`,
    create: (v) =>
      call(() =>
        api.POST('/planning/work-orders', {
          body: {
            plantId: v.plantId!,
            itemId: v.itemId!,
            qty: v.qty!,
            dueDate: v.dueDate!,
            reasonCode: v.reasonCode as 'URGENT_ORDER' | 'NO_MPS_YET' | 'SAMPLE_TRIAL' | 'NG_REPLACEMENT' | 'OTHER',
            note: v.note || null,
            scheduleLineId: v.scheduleLineId || null,
          },
        }),
      ),
    update: () => Promise.reject(new Error('A work order is changed on its own page.')),
  };
}

/** PPIC → Work Orders: the list, or one WO's page with `?order=<id>`. */
export function WorkOrdersScreen({ base }: { base: string }) {
  const order = useSearchParams()?.get('order');
  const config = React.useMemo(() => workOrders(base), [base]);
  return order ? <WorkOrderPage key={order} id={order} base={base} /> : <MasterScreen config={config} />;
}

function WorkOrderPage({ id, base }: { id: string; base: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('planning.wo.view') || permissions.includes('planning.wo.approve');
  const canCreate = permissions.includes('planning.wo.create');
  const canSubmit = permissions.includes('planning.wo.submit');
  const canPost = permissions.includes('planning.wo.post');
  const canApprove = permissions.includes('planning.wo.approve');
  const canIssue = permissions.includes('materials.issue.post') && permissions.includes('materials.issue.create');
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [editing, setEditing] = React.useState(false);
  const [ask, setAsk] = React.useState<null | 'submit' | 'withdraw' | 'release' | 'return' | 'cancel'>(null);

  const order = useQuery({
    queryKey: ['master', 'planning.work_orders', 'one', id],
    queryFn: async () => (await call(() => api.GET('/planning/work-orders/{order}', { params: { path: { order: id } } }))) as unknown as { data: WorkOrder },
    enabled: canView,
    retry: false,
  });
  const o = order.data?.data;
  const changed = (message: string) => {
    setNotice(message);
    void client.invalidateQueries({ queryKey: ['master'] });
  };

  if (me.isPending || (canView && order.isPending)) return <LoadingState title="Loading work order" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Work orders are not available for your roles"
          description="Ask a tenant administrator for a role that includes work orders."
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
            title="Work order not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push(base)}>
                Back to work orders
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The work order could not be loaded"
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

  const mine = me.data?.user.id === o.submittedById;
  // the dialog shows the server's explanation (e.g. which master is missing), not only its title
  const act = (action: 'submit' | 'withdraw' | 'release' | 'return' | 'cancel', body?: { reason: string }) =>
    call(() =>
      api.POST(`/planning/work-orders/{order}/actions/${action}` as '/planning/work-orders/{order}/actions/submit', {
        params: { path: { order: id } },
        headers: ifMatch(o.version),
        ...(body ? { body: body as never } : {}),
      }),
    ).catch((e: unknown) => {
      throw new Error(failureMessage(e, 'The work order could not be changed.'));
    });
  const editConfig: MasterResource<WorkOrderRow> = {
    ...workOrders(base),
    fields: fields(o.plantToday).filter((f) => !f.createOnly),
    drawerTitle: () => `Edit ${o.code}`,
    update: (_row: WorkOrderRow, v: FormValues) =>
      call(() =>
        api.PATCH('/planning/work-orders/{order}', {
          params: { path: { order: id } },
          headers: ifMatch(o.version),
          body: {
            itemId: v.itemId!,
            qty: v.qty!,
            dueDate: v.dueDate!,
            reasonCode: v.reasonCode as 'URGENT_ORDER' | 'NO_MPS_YET' | 'SAMPLE_TRIAL' | 'NG_REPLACEMENT' | 'OTHER',
            note: v.note || null,
            scheduleLineId: v.scheduleLineId || null,
          },
        }),
      ),
    toForm: (r) => ({
      plantId: r.plantId,
      itemId: r.itemId,
      qty: r.qty,
      dueDate: r.dueDate,
      reasonCode: r.reasonCode,
      note: r.note ?? '',
      scheduleLineId: r.scheduleLineId ?? '',
    }),
  };

  const facts: { label: string; value: React.ReactNode }[] = [
    { label: 'Item', value: `${o.itemCode} · ${o.itemName}` },
    { label: 'Quantity', value: <span className="font-semibold tabular-nums">{`${o.qty} ${o.uomCode}`}</span> },
    { label: 'Due', value: o.dueDate },
    { label: 'Plant', value: o.plantCode },
    { label: 'Reason', value: reason(o.reasonCode) },
    { label: 'Note', value: o.note ?? '—' },
    { label: 'For delivery', value: o.salesOrderCode ? `${o.salesOrderCode} · ${o.customerCode} · ${o.scheduleDate} · ${o.scheduleQty} ${o.uomCode}` : '—' },
    { label: 'Created', value: `${when(o.createdAt)}${o.createdByName ? ` · ${o.createdByName}` : ''}` },
    ...(o.submittedAt ? [{ label: 'Release asked', value: `${when(o.submittedAt)}${o.submittedByName ? ` · ${o.submittedByName}` : ''}` }] : []),
    ...(o.releasedAt ? [{ label: 'Released', value: `${when(o.releasedAt)}${o.releasedByName ? ` · ${o.releasedByName}` : ''}` }] : []),
    ...(o.releasedAt
      ? [
          {
            label: 'Copied at release',
            value: `BOM v${o.bomVersionNo} · routing ${o.routingCode} v${o.routingVersionNo}${o.standardYield ? ` · standard yield ${o.standardYield}` : ''}`,
          },
        ]
      : []),
    ...(o.status === 'CANCELLED' ? [{ label: 'Cancelled because', value: o.cancelReason ?? '—' }] : []),
  ];
  const materialColumns: EoraColumn<Material>[] = [
    { id: 'itemCode', header: 'Material', lead: true, cell: (m) => <span className="font-mono">{m.itemCode}</span>, sub: (m) => m.itemName },
    { id: 'grossQtyPer', header: 'Per piece', numeric: true, cell: (m) => `${m.grossQtyPer} ${m.uomCode}` },
    { id: 'requiredQty', header: 'Required', numeric: true, critical: true, cell: (m) => `${m.requiredQty} ${m.uomCode}` },
    { id: 'issuedQty', header: 'Issued', numeric: true, cell: (m) => `${m.issuedQty} ${m.baseUomCode}` },
    { id: 'atProductionQty', header: 'At production', numeric: true, cell: (m) => `${m.atProductionQty} ${m.baseUomCode}` },
    { id: 'conversionProfileVersion', header: 'Conversion profile', cell: (m) => (m.conversionProfileVersion ? `v${m.conversionProfileVersion}` : '—') },
  ];
  const status = o.status === 'SUBMITTED' || o.status === 'RELEASED' || o.status === 'CANCELLED' ? o.status : 'DRAFT';

  return (
    <EnterprisePage
      title={o.code}
      description={
        o.status === 'DRAFT'
          ? 'Draft: complete it, then ask for its release. A second person releases it.'
          : o.status === 'SUBMITTED'
            ? 'Waiting for release by a second person.'
            : o.status === 'RELEASED'
              ? 'Released: its BOM and routing versions and material lines are fixed on the work order.'
              : 'Cancelled: kept for the record.'
      }
      meta={<DomainStatusBadge domain="document" status={status} />}
      actions={
        <div className="flex flex-wrap gap-2">
          {o.status === 'DRAFT' && canCreate ? (
            <Button onClick={() => setEditing(true)}>
              <Pencil aria-hidden="true" className="size-4" />
              Edit
            </Button>
          ) : null}
          {o.status === 'DRAFT' && canSubmit ? (
            <Button variant="primary" onClick={() => setAsk('submit')}>
              Ask for release
            </Button>
          ) : null}
          {o.status === 'SUBMITTED' && mine && canSubmit ? <Button onClick={() => setAsk('withdraw')}>Withdraw request</Button> : null}
          {o.status === 'SUBMITTED' && canApprove && !mine ? (
            <>
              <Button onClick={() => setAsk('return')}>Return</Button>
              <Button variant="primary" onClick={() => setAsk('release')}>
                Release
              </Button>
            </>
          ) : null}
          {o.status === 'RELEASED' && canIssue ? (
            <Button variant="primary" onClick={() => router.push(`/materials/material-issue?wo=${o.id}`)}>
              Issue material
            </Button>
          ) : null}
          {((o.status === 'DRAFT' || o.status === 'SUBMITTED') && canCreate) || (o.status === 'RELEASED' && canPost) ? (
            <Button variant="ghost" onClick={() => setAsk('cancel')}>
              Cancel work order
            </Button>
          ) : null}
          <Button onClick={() => router.push(base)}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All work orders
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
      {o.status === 'DRAFT' && o.returnReason ? (
        <InlineAlert tone="warning" title="Returned for changes" className="mb-4">
          {o.returnReason}
        </InlineAlert>
      ) : null}
      {o.status === 'SUBMITTED' && mine ? (
        <InlineAlert tone="info" title="You asked for this release" className="mb-4">
          Another person releases or returns it.
        </InlineAlert>
      ) : null}
      <div className="grid grid-cols-[minmax(0,1fr)_340px] gap-4 max-lg:grid-cols-1">
        <Card>
          <CardHeader>
            <CardTitle>Materials</CardTitle>
          </CardHeader>
          <CardContent>
            {o.materials.length === 0 ? (
              <EmptyState
                title="Materials are copied at release"
                description="When the work order is released, the lines of its approved BOM version and the quantity they need are fixed here."
              />
            ) : (
              <DataTable
                label={`Materials of ${o.code}`}
                columns={materialColumns}
                rows={o.materials}
                getRowId={(m) => String(m.lineNo)}
                rowLabel={(m) => `${m.itemCode} ${m.requiredQty} ${m.uomCode}`}
                status="ready"
                sort={[]}
                onSortChange={() => undefined}
                density="compact"
                empty={<EmptyState title="No materials" description="The BOM version has no lines." />}
              />
            )}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Work order</CardTitle>
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
        config={editConfig}
        row={o}
        canEdit={canCreate}
        open={editing}
        onOpenChange={setEditing}
        onSaved={(message) => {
          setEditing(false);
          changed(message);
        }}
      />
      <ConfirmDialog
        open={ask === 'submit'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Ask for release"
        description="A second person releases it or returns it to you. It cannot be edited while it waits."
        confirmLabel="Ask for release"
        onConfirm={async () => {
          await act('submit');
          setAsk(null);
          changed(`Release of ${o.code} asked; it waits for a second person`);
        }}
      />
      <ConfirmDialog
        open={ask === 'withdraw'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Withdraw the request"
        description="The work order goes back to draft so you can change it."
        confirmLabel="Withdraw request"
        onConfirm={async () => {
          await act('withdraw');
          setAsk(null);
          changed('Request withdrawn; the work order is a draft again');
        }}
      />
      <ConfirmDialog
        open={ask === 'release'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Release this work order"
        description={`${o.qty} ${o.uomCode} of ${o.itemCode}, due ${o.dueDate}. The approved BOM and routing in force today are copied onto it with the materials it needs.`}
        confirmLabel="Release"
        onConfirm={async () => {
          await act('release');
          setAsk(null);
          changed(`${o.code} released`);
        }}
      />
      <ConfirmDialog
        open={ask === 'return'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Return to the planner"
        description="The work order goes back to draft with your reason."
        confirmLabel="Return"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          await act('return', { reason: why ?? '' });
          setAsk(null);
          changed(`${o.code} returned to the planner`);
        }}
      />
      <ConfirmDialog
        open={ask === 'cancel'}
        onOpenChange={(v) => (v ? undefined : setAsk(null))}
        title="Cancel this work order"
        description="It stays in the list as Cancelled with your reason. Its number is not reused."
        confirmLabel="Cancel work order"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (why) => {
          await act('cancel', { reason: why ?? '' });
          setAsk(null);
          changed(`${o.code} cancelled`);
        }}
      />
    </EnterprisePage>
  );
}

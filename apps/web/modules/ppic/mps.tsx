'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { ConfirmDialog, EnterprisePage } from '@eora/features/patterns';
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
  InlineAlert,
  Input,
  LoadingState,
  Modal,
  ModalBody,
  ModalContent,
  ModalFooter,
  PermissionDeniedState,
  Textarea,
  type IsoDate,
} from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M5 step 3 (doc 34 §1.3, D-123, D-046, BR-PLN-04/05/07): PPIC → Master Production Schedule on the live API. A version
 * per plant over 12 ISO weeks: opening FG stock used once, firm and net forecast demand on their days, projected supply
 * dated, Pending QC shown but never netted; lot-for-lot planned orders due on the earliest uncovered day. The planner
 * calculates, adjusts with a reason and submits; a second person approves while the data is unchanged.
 */

type Status = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'SUPERSEDED' | 'CANCELLED';

type MpsRow = {
  id: string;
  code: string;
  version: number;
  status: Status;
  plantId: string;
  plantCode: string;
  horizonStart: string;
  horizonEnd: string;
  horizonWeeks: number;
  planDate: string;
  itemCount: number;
  plannedOrderCount: number;
  plannedQty: string;
  adjustedCount: number;
  overloadCount: number;
  note: string | null;
  returnReason: string | null;
  calculatedAt: string;
  calculatedById: string;
  calculatedByName: string | null;
  submittedAt: string | null;
  submittedByName: string | null;
  approvedAt: string | null;
  approvedByName: string | null;
  createdAt: string;
};

type Bucket = {
  weekStart: string;
  opening: string;
  firm: string;
  forecast: string;
  receipts: string;
  netRequirement: string;
  plannedQty: string;
  closing: string;
};
type Demand = { id: string; kind: 'FIRM' | 'FORECAST'; reference: string; customerCode: string; dueDate: string; originalDate: string; qty: string };
type Supply = { kind: 'QC_RELEASED' | 'WORK_ORDER'; reference: string | null; date: string; qty: string };
type PlannedOrder = {
  id: string;
  weekStart: string;
  calcQty: string;
  calcDueDate: string;
  qty: string;
  dueDate: string;
  startDate: string;
  warnings: string[];
  adjusted: boolean;
  adjustReason: string | null;
  adjustedByName: string | null;
  pegs: { demandId: string | null; qty: string }[];
};
type MpsItem = {
  id: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  uomDecimals: number;
  onHand: string;
  outOfScopeAllocated: string;
  safetyStock: string | null;
  pendingQc: string;
  leadTimeDays: number | null;
  buckets: Bucket[];
  demands: Demand[];
  supplies: Supply[];
  plannedOrders: PlannedOrder[];
};
type Capacity = {
  workCenterId: string;
  workCenterCode: string;
  workCenterName: string;
  weekStart: string;
  loadHours: string;
  availableHours: string | null;
  loadPercent: number | null;
  status: 'OK' | 'OVERLOAD' | 'NOT_CONFIGURED';
  warnings: string[];
};
type Mps = MpsRow & { inputsChanged: boolean | null; items: MpsItem[]; capacity: Capacity[] };

const STATUS: Record<Status, { label: string; tone: 'info' | 'success' | 'neutral' | 'warning' }> = {
  DRAFT: { label: 'Draft', tone: 'info' },
  SUBMITTED: { label: 'Submitted', tone: 'warning' },
  APPROVED: { label: 'Approved', tone: 'success' },
  SUPERSEDED: { label: 'Superseded', tone: 'neutral' },
  CANCELLED: { label: 'Cancelled', tone: 'neutral' },
};
const WARNING: Record<string, string> = {
  LATE_START: 'Start date passed',
  LEAD_TIME_NOT_CONFIGURED: 'Lead time not configured',
  NO_ROUTING: 'No approved routing',
  NO_ACTIVE_MACHINE: 'No active machine',
  DIE_NOT_AVAILABLE: 'Die not available',
};
const statusBadge = (s: Status) => <Badge tone={STATUS[s].tone}>{STATUS[s].label}</Badge>;
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const BASE = '/ppic/mps';

/** ISO 8601 week number of a day (YYYY-MM-DD). */
export function isoWeek(day: string): number {
  const d = new Date(`${day}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7) + 3); // the Thursday of its week
  const jan4 = new Date(Date.UTC(d.getUTCFullYear(), 0, 4));
  return 1 + Math.round(((d.getTime() - jan4.getTime()) / 86_400_000 - 3 + ((jan4.getUTCDay() + 6) % 7)) / 7);
}

const zero = (q: string) => Number(q) === 0;

export const MPS: MasterResource<MpsRow> = {
  id: 'mps',
  resource: 'planning.mps_versions',
  permission: { view: 'planning.mps.view', create: 'planning.mps.create', label: 'master production schedules' },
  title: 'Master Production Schedule',
  description: 'What each plant must produce per week.',
  itemLabel: 'schedules',
  singular: 'schedule',
  createLabel: 'Calculate MPS',
  searchPlaceholder: 'Search MPS number…',
  rowLabel: (r) => r.code,
  openHref: (r) => `${BASE}?version=${r.id}`,
  defaultSort: [{ id: 'createdAt', desc: true }],
  filters: [
    PLANT_FILTER,
    {
      key: 'status',
      type: 'multiselect',
      source: 'static',
      label: 'Status',
      options: (Object.keys(STATUS) as Status[]).map((k) => ({ value: k, label: STATUS[k].label })),
    },
  ],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'code', header: 'MPS', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.plantCode },
    { id: 'horizonStart', header: 'Horizon', sortable: true, cell: (r) => `${r.horizonStart} – ${r.horizonEnd}`, sub: (r) => `${r.horizonWeeks} weeks` },
    { id: 'plannedOrderCount', header: 'Planned orders', numeric: true, cell: (r) => String(r.plannedOrderCount), sub: (r) => `${r.itemCount} items` },
    { id: 'plannedQty', header: 'Planned qty', numeric: true, cell: (r) => r.plannedQty },
    { id: 'calculatedAt', header: 'Calculated', sortable: true, cell: (r) => when(r.calculatedAt), sub: (r) => r.calculatedByName ?? undefined },
    { id: 'approvedAt', header: 'Approved', sortable: true, cell: (r) => (r.approvedAt ? when(r.approvedAt) : '—'), sub: (r) => r.approvedByName ?? undefined },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => statusBadge(r.status) },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants' },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
  ],
  blank: { plantId: '', note: '' },
  toForm: () => ({}),
  savedMessage: (result) => `${(result as { data: MpsRow }).data.code} calculated; review it and submit it for approval`,
  create: (v) => call(() => api.POST('/planning/mps-versions', { body: { plantId: v.plantId!, note: v.note || null } })),
  update: () => Promise.reject(new Error('An MPS is changed on its own page.')),
};

/** PPIC → Master Production Schedule: the versions, or one version's page with `?version=<id>`. */
export function MpsScreen() {
  const version = useSearchParams()?.get('version');
  return version ? <MpsPage id={version} /> : <MasterScreen config={MPS} />;
}

type Action = 'recalculate' | 'submit' | 'cancel' | 'approve' | 'return';

function MpsPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('planning.mps.view');
  const canCreate = permissions.includes('planning.mps.create');
  const canSubmit = permissions.includes('planning.mps.submit');
  const canApprove = permissions.includes('planning.mps.approve');
  const canRunMrp = permissions.includes('planning.mrp.create');
  const [runningMrp, setRunningMrp] = React.useState(false);
  const [mrpProblem, setMrpProblem] = React.useState<string | null>(null);
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [action, setAction] = React.useState<Action | null>(null);
  const [adjusting, setAdjusting] = React.useState<{ item: MpsItem; order: PlannedOrder } | null>(null);

  const query = useQuery({
    queryKey: ['master', 'planning.mps_versions', 'one', id],
    queryFn: async () => (await call(() => api.GET('/planning/mps-versions/{version}', { params: { path: { version: id } } }))) as unknown as { data: Mps },
    enabled: canView,
    retry: false,
  });
  const m = query.data?.data;

  if (me.isPending || (canView && query.isPending)) return <LoadingState title="Loading master production schedule" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="The MPS is not available for your roles" description="Ask a tenant administrator for a planner or approver role." />
      </div>
    );
  }
  if (!m) {
    return (
      <div className="p-6 max-md:p-4">
        {query.error instanceof ApiError && [403, 404].includes(query.error.status) ? (
          <EmptyState
            title="MPS not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push(BASE)}>
                Back to schedules
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The MPS could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void query.refetch()}>
                Try again
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const draft = m.status === 'DRAFT';
  const done = (message: string) => {
    setNotice(message);
    void client.invalidateQueries({ queryKey: ['master'] });
  };
  const act = async (name: Action, reason?: string) => {
    const args = { params: { path: { version: id } }, headers: ifMatch(m.version) };
    if (name === 'return') {
      await call(() => api.POST('/planning/mps-versions/{version}/actions/return', { ...args, body: { reason: reason ?? '' } }));
    } else {
      await call(() => api.POST(`/planning/mps-versions/{version}/actions/${name}` as '/planning/mps-versions/{version}/actions/submit', args));
    }
    done(
      {
        recalculate: 'Recalculated from today’s data',
        submit: 'Submitted for approval',
        cancel: 'Draft cancelled',
        approve: `${m.code} approved`,
        return: 'Returned to the planner',
      }[name],
    );
  };
  const runMrp = async () => {
    setRunningMrp(true);
    setMrpProblem(null);
    try {
      const result = (await call(() => api.POST('/planning/mrp-runs', { body: { mpsVersionId: id } }))) as unknown as { data: { id: string } };
      void client.invalidateQueries({ queryKey: ['master'] });
      router.push(`/ppic/mrp?run=${result.data.id}`);
    } catch (e) {
      setMrpProblem(failureMessage(e, 'MRP could not be run.'));
      setRunningMrp(false);
    }
  };
  const notConfigured = m.capacity.length > 0 && m.capacity.every((c) => c.status === 'NOT_CONFIGURED');

  return (
    <EnterprisePage
      title={m.code}
      description={`Plant ${m.plantCode} · ${m.horizonWeeks} weeks ${m.horizonStart} – ${m.horizonEnd} · calculated ${when(m.calculatedAt)}${m.calculatedByName ? ` by ${m.calculatedByName}` : ''}`}
      meta={statusBadge(m.status)}
      actions={
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => router.push(BASE)}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All schedules
          </Button>
          {draft && canCreate ? (
            <>
              <Button onClick={() => setAction('cancel')}>Cancel draft</Button>
              <Button onClick={() => setAction('recalculate')}>
                <RefreshCw aria-hidden="true" className="size-4" />
                Recalculate
              </Button>
            </>
          ) : null}
          {draft && canSubmit ? (
            <Button variant="primary" onClick={() => setAction('submit')}>
              Submit for approval
            </Button>
          ) : null}
          {m.status === 'APPROVED' && canRunMrp ? (
            <Button variant="primary" disabled={runningMrp} onClick={() => void runMrp()}>
              Run MRP
            </Button>
          ) : null}
          {m.status === 'SUBMITTED' && canApprove ? (
            <>
              <Button onClick={() => setAction('return')}>Return</Button>
              <Button variant="primary" disabled={m.inputsChanged === true} onClick={() => setAction('approve')}>
                Approve
              </Button>
            </>
          ) : null}
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
      {mrpProblem ? (
        <InlineAlert tone="danger" title="MRP not run" className="mb-4">
          {mrpProblem}
        </InlineAlert>
      ) : null}
      {m.inputsChanged ? (
        <InlineAlert tone="warning" title="Data changed since this calculation" className="mb-4">
          Demand, stock, work orders or planning parameters changed after {when(m.calculatedAt)}.{' '}
          {draft ? 'Recalculate before submitting.' : 'It cannot be approved; return it so the planner recalculates.'}
        </InlineAlert>
      ) : null}
      {draft && m.returnReason ? (
        <InlineAlert tone="warning" title="Returned by the approver" className="mb-4">
          {m.returnReason}
        </InlineAlert>
      ) : null}

      <dl className="mb-4 grid grid-cols-5 gap-3 max-lg:grid-cols-3 max-md:grid-cols-2">
        {[
          ['Items', String(m.itemCount)],
          ['Planned orders', String(m.plannedOrderCount)],
          ['Planned quantity', m.plannedQty],
          ['Adjusted by the planner', String(m.adjustedCount)],
          ['Overloaded work-center weeks', String(m.overloadCount)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-md border border-line bg-surface px-3 py-2">
            <dt className="text-[12.5px] text-ink-2">{label}</dt>
            <dd className="m-0 text-[17px] font-semibold tabular-nums">{value}</dd>
          </div>
        ))}
      </dl>

      {m.items.length === 0 ? (
        <Card>
          <CardContent>
            <EmptyState title="No demand in the horizon" description="No firm order or net forecast is due at this plant in the next 12 weeks." />
          </CardContent>
        </Card>
      ) : (
        <div className="flex flex-col gap-4">
          {m.items.map((item) => (
            <ItemCard key={item.id} item={item} canAdjust={draft && canCreate} onAdjust={(order) => setAdjusting({ item, order })} />
          ))}
        </div>
      )}

      <Card className="mt-4">
        <CardHeader>
          <CardTitle>Capacity load</CardTitle>
        </CardHeader>
        <CardContent>
          {notConfigured ? (
            <InlineAlert tone="info" title="Capacity not configured" className="mb-3">
              The plant has no shift calendar, or its work centers have no hours per shift. The load is shown in hours without a percentage.
            </InlineAlert>
          ) : null}
          {m.capacity.length === 0 ? (
            <p className="m-0 text-ink-2">No planned order loads a work center.</p>
          ) : (
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Capacity load table">
              <table className="w-full border-collapse text-[13.5px]" aria-label="Capacity load per work center and week">
                <thead>
                  <tr className="border-b border-line text-left text-ink-2">
                    <th className="px-2 py-2 font-medium">Work center</th>
                    <th className="px-2 py-2 font-medium">Week</th>
                    <th className="px-2 py-2 text-right font-medium">Load (h)</th>
                    <th className="px-2 py-2 text-right font-medium">Available (h)</th>
                    <th className="px-2 py-2 text-right font-medium">Load</th>
                    <th className="px-2 py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {m.capacity.map((c) => (
                    <tr key={`${c.workCenterId}-${c.weekStart}`} className="border-b border-line">
                      <td className="px-2 py-2">
                        <span className="font-mono">{c.workCenterCode}</span> <span className="text-ink-2">· {c.workCenterName}</span>
                      </td>
                      <td className="px-2 py-2 tabular-nums">
                        W{isoWeek(c.weekStart)} · {c.weekStart}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums">{c.loadHours}</td>
                      <td className="px-2 py-2 text-right tabular-nums">{c.availableHours ?? '—'}</td>
                      <td className="px-2 py-2 text-right tabular-nums">{c.loadPercent === null ? '—' : `${c.loadPercent} %`}</td>
                      <td className="px-2 py-2">
                        <div className="flex flex-wrap gap-1">
                          {c.status === 'OVERLOAD' ? <Badge tone="danger">Over capacity</Badge> : null}
                          {c.status === 'NOT_CONFIGURED' ? <Badge tone="neutral">Not configured</Badge> : null}
                          {c.status === 'OK' ? <Badge tone="success">Within capacity</Badge> : null}
                          {c.warnings.map((w) => (
                            <Badge key={w} tone="warning">
                              {WARNING[w] ?? w}
                            </Badge>
                          ))}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <p className="mt-3 mb-0 text-[13px] text-ink-2">
            A warning only: the MPS never moves an order. Load = setup + standard cycle per operation of the primary routing, in the week the order starts.
          </p>
        </CardContent>
      </Card>

      <ConfirmDialog
        open={action === 'recalculate'}
        onOpenChange={(o) => setAction(o ? 'recalculate' : null)}
        title="Recalculate this MPS"
        description="It is calculated again from today’s demand, stock, work orders and parameters. Adjusted planned orders are replaced by the new calculation."
        confirmLabel="Recalculate"
        onConfirm={() => act('recalculate')}
      />
      <ConfirmDialog
        open={action === 'submit'}
        onOpenChange={(o) => setAction(o ? 'submit' : null)}
        title="Submit for approval"
        description="An approver who did not prepare it decides it. While it waits, nothing in it can be changed."
        confirmLabel="Submit for approval"
        onConfirm={() => act('submit')}
      />
      <ConfirmDialog
        open={action === 'cancel'}
        onOpenChange={(o) => setAction(o ? 'cancel' : null)}
        title="Cancel this draft"
        description={`${m.code} stays in the history as Cancelled; you can calculate a new MPS for ${m.plantCode}.`}
        confirmLabel="Cancel draft"
        tone="danger"
        onConfirm={() => act('cancel')}
      />
      <ConfirmDialog
        open={action === 'approve'}
        onOpenChange={(o) => setAction(o ? 'approve' : null)}
        title="Approve this MPS"
        description={`${m.code} becomes the plan of ${m.plantCode}; the approved MPS before it becomes Superseded.${m.adjustedCount > 0 ? ` ${m.adjustedCount} planned order(s) were adjusted by the planner.` : ''}`}
        confirmLabel="Approve"
        onConfirm={() => act('approve')}
      />
      <ConfirmDialog
        open={action === 'return'}
        onOpenChange={(o) => setAction(o ? 'return' : null)}
        title="Return to the planner"
        description="It goes back to draft with your reason; the planner recalculates or adjusts it and submits again."
        confirmLabel="Return"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={(reason) => act('return', reason)}
      />
      <AdjustDialog
        target={adjusting}
        mps={m}
        onOpenChange={(o) => (o ? null : setAdjusting(null))}
        onAdjusted={() => {
          setAdjusting(null);
          done('Planned order adjusted');
        }}
      />
    </EnterprisePage>
  );
}

function ItemCard({ item, canAdjust, onAdjust }: { item: MpsItem; canAdjust: boolean; onAdjust: (order: PlannedOrder) => void }) {
  const unit = item.uomCode;
  const demandById = new Map(item.demands.map((d) => [d.id, d]));
  const rows: [string, (b: Bucket) => string, string?][] = [
    ['Opening balance', (b) => b.opening],
    ['Firm orders', (b) => b.firm],
    ['Net forecast', (b) => b.forecast],
    ['Projected supply', (b) => b.receipts],
    ['Planned', (b) => b.plannedQty, 'font-semibold'],
    ['Closing balance', (b) => b.closing],
  ];
  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <span className="font-mono">{item.itemCode}</span> <span className="font-normal text-ink-2">· {item.itemName}</span>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <dl className="mb-4 flex flex-wrap gap-x-6 gap-y-2 text-[13.5px]">
          <div>
            <dt className="text-ink-2">FG on hand (used once)</dt>
            <dd className="m-0 tabular-nums">
              {item.onHand} {unit}
            </dd>
          </div>
          <div>
            <dt className="text-ink-2">Allocated beyond the horizon</dt>
            <dd className="m-0 tabular-nums">{item.outOfScopeAllocated}</dd>
          </div>
          <div>
            <dt className="text-ink-2">Safety stock</dt>
            <dd className="m-0 tabular-nums">{item.safetyStock ?? <Badge tone="neutral">Not configured</Badge>}</dd>
          </div>
          <div>
            <dt className="text-ink-2">Pending QC (not netted)</dt>
            <dd className="m-0 tabular-nums">{item.pendingQc}</dd>
          </div>
          <div>
            <dt className="text-ink-2">Production lead time</dt>
            <dd className="m-0 tabular-nums">
              {item.leadTimeDays === null ? <Badge tone="neutral">Not configured</Badge> : `${item.leadTimeDays} working days`}
            </dd>
          </div>
        </dl>

        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={`${item.itemCode} weeks table`}>
          <table className="w-full border-collapse text-[13px]" aria-label={`${item.itemCode} projected balance per week`}>
            <thead>
              <tr className="border-b border-line text-ink-2">
                <th className="sticky left-0 bg-surface px-2 py-2 text-left font-medium">{unit}</th>
                {item.buckets.map((b) => (
                  <th key={b.weekStart} className="px-2 py-2 text-right font-medium whitespace-nowrap">
                    <span className="block">W{isoWeek(b.weekStart)}</span>
                    <span className="block text-[11.5px] font-normal">{b.weekStart.slice(5)}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map(([label, value, weight]) => (
                <tr key={label} className="border-b border-line">
                  <th scope="row" className={`sticky left-0 bg-surface px-2 py-1.5 text-left font-medium whitespace-nowrap ${weight ?? ''}`}>
                    {label}
                  </th>
                  {item.buckets.map((b) => {
                    const v = value(b);
                    return (
                      <td
                        key={b.weekStart}
                        className={`px-2 py-1.5 text-right tabular-nums ${weight ?? ''} ${zero(v) ? 'text-ink-2' : Number(v) < 0 ? 'font-semibold text-stat-err-fg' : ''}`}
                      >
                        {zero(v) ? '·' : v}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <h3 className="mt-5 mb-2 text-[14px] font-semibold">Planned orders</h3>
        {item.plannedOrders.length === 0 ? (
          <p className="m-0 text-ink-2">Stock and projected supply cover every due date in the horizon.</p>
        ) : (
          <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={`${item.itemCode} planned orders table`}>
            <table className="w-full border-collapse text-[13.5px]" aria-label={`${item.itemCode} planned orders`}>
              <thead>
                <tr className="border-b border-line text-left text-ink-2">
                  <th className="px-2 py-2 font-medium">Week</th>
                  <th className="px-2 py-2 text-right font-medium">Quantity</th>
                  <th className="px-2 py-2 font-medium">Due (earliest uncovered)</th>
                  <th className="px-2 py-2 font-medium">Start</th>
                  <th className="px-2 py-2 font-medium">Covers</th>
                  <th className="px-2 py-2 font-medium">Notes</th>
                  {canAdjust ? <th className="px-2 py-2 font-medium" aria-label="Actions" /> : null}
                </tr>
              </thead>
              <tbody>
                {item.plannedOrders.map((o) => (
                  <tr key={o.id} className="border-b border-line align-top">
                    <td className="px-2 py-2 tabular-nums">W{isoWeek(o.weekStart)}</td>
                    <td className="px-2 py-2 text-right tabular-nums">
                      <span className="font-semibold">
                        {o.qty} {unit}
                      </span>
                      {o.adjusted && o.qty !== o.calcQty ? <span className="block text-[12px] text-ink-2">calculated {o.calcQty}</span> : null}
                    </td>
                    <td className="px-2 py-2 tabular-nums">
                      {o.dueDate}
                      {o.adjusted && o.dueDate !== o.calcDueDate ? <span className="block text-[12px] text-ink-2">calculated {o.calcDueDate}</span> : null}
                    </td>
                    <td className="px-2 py-2 tabular-nums">{o.startDate}</td>
                    <td className="px-2 py-2">
                      {o.adjusted ? <span className="block text-[12px] text-ink-2">Calculated to cover (before the adjustment):</span> : null}
                      <ul className="m-0 list-none p-0 text-[12.5px]">
                        {o.pegs.map((p, i) => {
                          const d = p.demandId ? demandById.get(p.demandId) : undefined;
                          return (
                            <li key={i}>
                              {d ? (
                                <>
                                  {d.kind === 'FIRM' ? <span className="font-mono">{d.reference}</span> : `Forecast ${d.reference}`} · {d.dueDate} · {p.qty}
                                </>
                              ) : (
                                `Safety stock · ${p.qty}`
                              )}
                            </li>
                          );
                        })}
                      </ul>
                    </td>
                    <td className="px-2 py-2">
                      <div className="flex flex-wrap gap-1">
                        {o.adjusted ? (
                          <Badge tone="info" title={o.adjustReason ?? undefined}>
                            Adjusted{o.adjustedByName ? ` by ${o.adjustedByName}` : ''}
                          </Badge>
                        ) : null}
                        {o.warnings.map((w) => (
                          <Badge key={w} tone={w === 'LATE_START' ? 'danger' : 'warning'}>
                            {WARNING[w] ?? w}
                          </Badge>
                        ))}
                      </div>
                      {o.adjusted && o.adjustReason ? <p className="m-0 mt-1 text-[12.5px] text-ink-2">{o.adjustReason}</p> : null}
                    </td>
                    {canAdjust ? (
                      <td className="px-2 py-2 text-right">
                        <Button size="sm" onClick={() => onAdjust(o)}>
                          Adjust
                        </Button>
                      </td>
                    ) : null}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <details className="mt-4">
          <summary className="cursor-pointer text-[13.5px] font-medium text-link">
            Daily due dates and supply ({item.demands.length + item.supplies.length})
          </summary>
          <div className="mt-2 overflow-x-auto" tabIndex={0} role="region" aria-label={`${item.itemCode} daily table`}>
            <table className="w-full border-collapse text-[13px]" aria-label={`${item.itemCode} demand and supply per day`}>
              <thead>
                <tr className="border-b border-line text-left text-ink-2">
                  <th className="px-2 py-1.5 font-medium">Day</th>
                  <th className="px-2 py-1.5 font-medium">What</th>
                  <th className="px-2 py-1.5 font-medium">Reference</th>
                  <th className="px-2 py-1.5 text-right font-medium">Quantity</th>
                </tr>
              </thead>
              <tbody>
                {[
                  ...item.demands.map((d) => ({
                    day: d.dueDate,
                    what: d.kind === 'FIRM' ? 'Firm order' : 'Net forecast',
                    ref: `${d.reference} · ${d.customerCode}${d.originalDate !== d.dueDate ? ` (due ${d.originalDate}, past due)` : ''}`,
                    qty: `−${d.qty}`,
                  })),
                  ...item.supplies.map((s) => ({
                    day: s.date,
                    what: s.kind === 'WORK_ORDER' ? 'Work order remaining' : 'QC released, to receive',
                    ref: s.reference ?? '—',
                    qty: `+${s.qty}`,
                  })),
                ]
                  .sort((a, b) => a.day.localeCompare(b.day))
                  .map((r, i) => (
                    <tr key={i} className="border-b border-line">
                      <td className="px-2 py-1.5 tabular-nums">{r.day}</td>
                      <td className="px-2 py-1.5">{r.what}</td>
                      <td className="px-2 py-1.5">{r.ref}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">{r.qty}</td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
        </details>
      </CardContent>
    </Card>
  );
}

function AdjustDialog({
  target,
  mps,
  onOpenChange,
  onAdjusted,
}: {
  target: { item: MpsItem; order: PlannedOrder } | null;
  mps: Mps;
  onOpenChange: (open: boolean) => void;
  onAdjusted: () => void;
}) {
  if (!target) return null;
  return (
    <Modal open onOpenChange={onOpenChange}>
      <ModalContent title={`Adjust planned order W${isoWeek(target.order.weekStart)} · ${target.item.itemCode}`} closeLabel="Close">
        <AdjustForm key={target.order.id} target={target} mps={mps} onCancel={() => onOpenChange(false)} onAdjusted={onAdjusted} />
      </ModalContent>
    </Modal>
  );
}

function AdjustForm({
  target: { item, order },
  mps,
  onCancel,
  onAdjusted,
}: {
  target: { item: MpsItem; order: PlannedOrder };
  mps: Mps;
  onCancel: () => void;
  onAdjusted: () => void;
}) {
  const [qty, setQty] = React.useState(order.qty);
  const [due, setDue] = React.useState(order.dueDate);
  const [reason, setReason] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const pattern = item.uomDecimals === 0 ? /^\d+$/ : new RegExp(`^\\d+(\\.\\d{1,${item.uomDecimals}})?$`);

  const submit = async () => {
    const why =
      !pattern.test(qty) || Number(qty) <= 0
        ? `Enter ${item.uomDecimals === 0 ? 'a whole number' : `a quantity with at most ${item.uomDecimals} decimals`} above zero.`
        : !due || due < mps.planDate || due > mps.horizonEnd
          ? `Choose a due date from ${mps.planDate} to ${mps.horizonEnd}.`
          : reason.trim().length < 3
            ? 'Give the reason in at least 3 characters.'
            : null;
    setProblem(why);
    if (why) return;
    setBusy(true);
    try {
      await call(() =>
        api.PATCH('/planning/mps-versions/{version}/planned-orders/{order}', {
          params: { path: { version: mps.id, order: order.id } },
          headers: ifMatch(mps.version),
          body: { qty, dueDate: due, reason: reason.trim() },
        }),
      );
      onAdjusted();
    } catch (e) {
      const errors = e instanceof ApiError ? (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors : undefined;
      setProblem((errors ? Object.values(errors)[0]?.[0] : undefined) ?? failureMessage(e, 'The planned order could not be adjusted.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <ModalBody>
        <p className="mt-0 mb-3 text-ink-2">
          Calculated: {order.calcQty} {item.uomCode} due {order.calcDueDate}. The start date follows the lead time; the approver sees the change and your
          reason. A recalculation replaces it.
        </p>
        {problem ? (
          <InlineAlert tone="danger" title="Not adjusted" className="mb-3">
            {problem}
          </InlineAlert>
        ) : null}
        <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
          <Field label={`Quantity (${item.uomCode})`}>
            {(control) => (
              <Input
                {...control}
                inputMode={item.uomDecimals === 0 ? 'numeric' : 'decimal'}
                className="tabular-nums"
                value={qty}
                onChange={(e) => setQty(e.target.value.trim())}
              />
            )}
          </Field>
          <div className="flex flex-col gap-1.5">
            <span aria-hidden="true" className="text-[13px] font-medium text-ink">
              Due date
            </span>
            <DatePicker
              label="Due date"
              value={due as IsoDate | ''}
              min={mps.planDate as IsoDate}
              max={mps.horizonEnd as IsoDate}
              today={mps.planDate as IsoDate}
              clearable={false}
              onChange={(v) => setDue(v)}
            />
          </div>
        </div>
        <Field label="Reason" required className="mt-4">
          {(control) => <Textarea {...control} rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </ModalBody>
      <ModalFooter>
        <Button onClick={onCancel}>Cancel</Button>
        <Button variant="primary" onClick={() => void submit()} disabled={busy}>
          Adjust planned order
        </Button>
      </ModalFooter>
    </>
  );
}

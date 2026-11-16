'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
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
  Checkbox,
  EmptyState,
  ErrorState,
  InlineAlert,
  LoadingState,
  PermissionDeniedState,
} from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';
import { isoWeek } from './mps';

/*
 * M5 step 4 (doc 34 §1.4, D-124, D-059, BR-PLN-06): PPIC → MRP Results on the live API. A run is a read-only snapshot
 * made from the plant's approved MPS (Run MRP on the MPS page): planned work orders for finished and semi-finished items,
 * and the material shortage list with need and order-by dates. No purchasing document (M5-D5).
 */

type RunRow = {
  id: string;
  code: string;
  plantId: string;
  plantCode: string;
  mpsVersionId: string;
  mpsNo: string;
  mpsStatus: string;
  planDate: string;
  horizonStart: string;
  horizonEnd: string;
  plannedOrderCount: number;
  shortageCount: number;
  shortItemCount: number;
  createdAt: string;
  createdByName: string | null;
  version: number;
};

type Order = {
  id: string;
  kind: 'MAKE' | 'BUY';
  source: 'MPS' | 'MRP';
  weekStart: string;
  qty: string;
  dueDate: string;
  startDate: string;
  bomVersionNo: number | null;
  warnings: string[];
  pegs: { requirementId: string | null; qty: string }[];
  workOrder: { id: string; code: string; status: string } | null;
};
type Requirement = { id: string; dueDate: string; qty: string; parentOrderId: string; parentItemCode: string };
type Supply = { kind: 'INBOUND' | 'WORK_ORDER'; reference: string; date: string; qty: string };
type RunItem = {
  id: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  itemType: string;
  uomCode: string;
  kind: 'MAKE' | 'BUY';
  level: number;
  onHand: string;
  committed: string;
  plannedWorkOrderNeed: string;
  safetyStock: string | null;
  leadTimeDays: number | null;
  warnings: string[];
  requirements: Requirement[];
  supplies: Supply[];
  orders: Order[];
};
type Run = RunRow & { items: RunItem[]; releasable: boolean };

const WARNING: Record<string, string> = {
  LATE_START: 'Start date passed',
  LEAD_TIME_NOT_CONFIGURED: 'Lead time not configured',
  NO_BOM: 'No approved BOM',
  UOM_CONVERSION_MISSING: 'Unit conversion missing',
};
const TYPE: Record<string, string> = { FINISHED: 'Finished good', SEMI_FINISHED: 'Semi-finished', RAW: 'Raw material', CONSUMABLE: 'Consumable' };
const BASE = '/ppic/mrp';

export const MRP_RUNS: MasterResource<RunRow> = {
  id: 'mrp',
  resource: 'planning.mrp_runs',
  permission: { view: 'planning.mrp.view', create: 'planning.mrp.create', label: 'MRP runs' },
  noCreate: true,
  title: 'MRP Results',
  description:
    'What to make and what to buy for the approved MPS: planned work orders for finished and semi-finished items, and the materials that run short, with the day they are needed and the day to order by. Run MRP from an approved Master Production Schedule.',
  itemLabel: 'runs',
  singular: 'run',
  searchPlaceholder: 'Search MRP run…',
  rowLabel: (r) => r.code,
  openHref: (r) => `${BASE}?run=${r.id}`,
  defaultSort: [{ id: 'createdAt', desc: true }],
  filters: [PLANT_FILTER],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'code', header: 'MRP run', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.plantCode },
    { id: 'mpsNo', header: 'From MPS', cell: (r) => <span className="font-mono">{r.mpsNo}</span>, sub: (r) => `${r.horizonStart} – ${r.horizonEnd}` },
    { id: 'plannedOrderCount', header: 'Planned work orders', numeric: true, cell: (r) => String(r.plannedOrderCount) },
    { id: 'shortItemCount', header: 'Materials short', numeric: true, cell: (r) => String(r.shortItemCount), sub: (r) => `${r.shortageCount} lines` },
    { id: 'createdAt', header: 'Run', sortable: true, cell: (r) => when(r.createdAt), sub: (r) => r.createdByName ?? undefined },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('MRP is run from an approved MPS.')),
  update: () => Promise.reject(new Error('An MRP run is a snapshot.')),
  readOnly: () => 'An MRP run is a snapshot; run MRP again from the approved MPS.',
};

/** PPIC → MRP Results: the runs, or one run's page with `?run=<id>`. */
export function MrpScreen() {
  const run = useSearchParams()?.get('run');
  return run ? <RunPage id={run} /> : <MasterScreen config={MRP_RUNS} />;
}

function RunPage({ id }: { id: string }) {
  const me = useMe();
  const canView = (me.data?.permissions ?? []).includes('planning.mrp.view');
  const canRelease = (me.data?.permissions ?? []).includes('planning.planned_order_release.post');
  const router = useRouter();
  const client = useQueryClient();
  const [picked, setPicked] = React.useState<string[]>([]);
  const [confirming, setConfirming] = React.useState(false);
  const [notice, setNotice] = React.useState<string | null>(null);
  const query = useQuery({
    queryKey: ['master', 'planning.mrp_runs', 'one', id],
    queryFn: async () => (await call(() => api.GET('/planning/mrp-runs/{run}', { params: { path: { run: id } } }))) as unknown as { data: Run },
    enabled: canView,
    retry: false,
  });
  const r = query.data?.data;

  if (me.isPending || (canView && query.isPending)) return <LoadingState title="Loading MRP run" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="MRP results are not available for your roles" description="Ask a tenant administrator for a planner or materials role." />
      </div>
    );
  }
  if (!r) {
    return (
      <div className="p-6 max-md:p-4">
        {query.error instanceof ApiError && [403, 404].includes(query.error.status) ? (
          <EmptyState
            title="MRP run not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push(BASE)}>
                Back to MRP runs
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The MRP run could not be loaded"
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

  const make = r.items.flatMap((i) => i.orders.filter((o) => o.kind === 'MAKE').map((o) => ({ item: i, order: o })));
  const buy = r.items.flatMap((i) => i.orders.filter((o) => o.kind === 'BUY').map((o) => ({ item: i, order: o })));
  const releasing = canRelease && r.releasable;
  const open = make.filter(({ order }) => order.workOrder === null).map(({ order }) => order.id);
  const toggle = (orderId: string) => setPicked(picked.includes(orderId) ? picked.filter((x) => x !== orderId) : [...picked, orderId]);
  const release = async () => {
    try {
      const result = (await call(() =>
        api.POST('/planning/mrp-runs/{run}/actions/release', { params: { path: { run: id } }, body: { orderIds: picked } }),
      )) as unknown as { data: Run };
      const codes = result.data.items.flatMap((i) => i.orders.filter((o) => picked.includes(o.id) && o.workOrder).map((o) => o.workOrder!.code));
      setPicked([]);
      setNotice(`${codes.length === 1 ? 'Work order' : 'Work orders'} ${codes.join(', ')} drafted; ask for their release on Work Orders.`);
      void client.invalidateQueries({ queryKey: ['master'] });
    } catch (e) {
      throw new Error(failureMessage(e, 'The planned orders could not be released.'));
    }
  };
  const requirementById = new Map(r.items.flatMap((i) => i.requirements.map((q) => [q.id, q] as const)));
  const covers = (o: Order) =>
    o.pegs.map((p) => {
      const q = p.requirementId ? requirementById.get(p.requirementId) : undefined;
      return q ? `${q.parentItemCode} · ${q.dueDate} · ${p.qty}` : `Safety stock · ${p.qty}`;
    });

  return (
    <EnterprisePage
      title={r.code}
      description={`Plant ${r.plantCode} · from ${r.mpsNo} · ${r.horizonStart} – ${r.horizonEnd} · run ${when(r.createdAt)}${r.createdByName ? ` by ${r.createdByName}` : ''}`}
      meta={r.mpsStatus === 'APPROVED' ? <Badge tone="success">From the approved MPS</Badge> : <Badge tone="neutral">MPS {r.mpsStatus.toLowerCase()}</Badge>}
      actions={
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => router.push(BASE)}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All runs
          </Button>
          <Button onClick={() => router.push(`/ppic/mps?version=${r.mpsVersionId}`)}>Open the MPS</Button>
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
      {!r.releasable ? (
        <InlineAlert tone="info" title="Not the current plan" className="mb-4">
          Planned work orders are released from the latest MRP run of the approved MPS.
        </InlineAlert>
      ) : null}
      <Card>
        <CardHeader>
          <CardTitle>Material shortages ({buy.length})</CardTitle>
        </CardHeader>
        <CardContent>
          {buy.length === 0 ? (
            <p className="m-0 text-ink-2">Stock, released work orders and inbound cover every material need in the horizon.</p>
          ) : (
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Material shortages table">
              <table className="w-full border-collapse text-[13.5px]" aria-label="Material shortages">
                <thead>
                  <tr className="border-b border-line text-left text-ink-2">
                    <th className="px-2 py-2 font-medium">Material</th>
                    <th className="px-2 py-2 text-right font-medium">Short</th>
                    <th className="px-2 py-2 font-medium">Needed on</th>
                    <th className="px-2 py-2 font-medium">Order by</th>
                    <th className="px-2 py-2 font-medium">For</th>
                    <th className="px-2 py-2 font-medium">Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {buy.map(({ item, order }) => (
                    <tr key={order.id} className="border-b border-line align-top">
                      <td className="px-2 py-2">
                        <span className="font-mono">{item.itemCode}</span> <span className="text-ink-2">· {item.itemName}</span>
                      </td>
                      <td className="px-2 py-2 text-right font-semibold tabular-nums">
                        {order.qty} {item.uomCode}
                      </td>
                      <td className="px-2 py-2 tabular-nums">{order.dueDate}</td>
                      <td className="px-2 py-2 tabular-nums">{item.leadTimeDays === null ? <Badge tone="neutral">Not configured</Badge> : order.startDate}</td>
                      <td className="px-2 py-2 text-[12.5px]">
                        {covers(order).map((c, i) => (
                          <span key={i} className="block">
                            {c}
                          </span>
                        ))}
                      </td>
                      <td className="px-2 py-2">
                        <div className="flex flex-wrap gap-1">
                          {order.warnings
                            .filter((w) => w !== 'LEAD_TIME_NOT_CONFIGURED')
                            .map((w) => (
                              <Badge key={w} tone={w === 'LATE_START' ? 'danger' : 'warning'}>
                                {w === 'LATE_START' ? 'Order date passed' : (WARNING[w] ?? w)}
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
            Short = need − (stock − what released work orders still need + inbound before the need date). No purchase order is created.
          </p>
        </CardContent>
      </Card>

      <Card className="mt-4">
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>Planned work orders ({make.length})</CardTitle>
          {releasing ? (
            <Button variant="primary" size="sm" disabled={picked.length === 0} onClick={() => setConfirming(true)}>
              Release to WO{picked.length > 0 ? ` (${picked.length})` : ''}
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          {make.length === 0 ? (
            <p className="m-0 text-ink-2">The approved MPS plans no work order in the horizon.</p>
          ) : (
            <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Planned work orders table">
              <table className="w-full border-collapse text-[13.5px]" aria-label="Planned work orders">
                <thead>
                  <tr className="border-b border-line text-left text-ink-2">
                    {releasing ? (
                      <th className="w-8 px-2 py-2">
                        <Checkbox
                          aria-label="Select all planned work orders without a work order"
                          checked={open.length > 0 && picked.length === open.length}
                          indeterminate={picked.length > 0 && picked.length < open.length}
                          disabled={open.length === 0}
                          onChange={() => setPicked(picked.length === open.length ? [] : open)}
                        />
                      </th>
                    ) : null}
                    <th className="px-2 py-2 font-medium">Item</th>
                    <th className="px-2 py-2 text-right font-medium">Quantity</th>
                    <th className="px-2 py-2 font-medium">Due</th>
                    <th className="px-2 py-2 font-medium">Start</th>
                    <th className="px-2 py-2 font-medium">From</th>
                    <th className="px-2 py-2 font-medium">Work order</th>
                    <th className="px-2 py-2 font-medium">Notes</th>
                  </tr>
                </thead>
                <tbody>
                  {make.map(({ item, order }) => (
                    <tr key={order.id} className="border-b border-line align-top">
                      {releasing ? (
                        <td className="px-2 py-2">
                          <Checkbox
                            aria-label={`Select ${item.itemCode} ${order.qty} due ${order.dueDate}`}
                            checked={picked.includes(order.id)}
                            disabled={order.workOrder !== null}
                            onChange={() => toggle(order.id)}
                          />
                        </td>
                      ) : null}
                      <td className="px-2 py-2">
                        <span className="font-mono">{item.itemCode}</span> <span className="text-ink-2">· {TYPE[item.itemType] ?? item.itemType}</span>
                      </td>
                      <td className="px-2 py-2 text-right font-semibold tabular-nums">
                        {order.qty} {item.uomCode}
                      </td>
                      <td className="px-2 py-2 tabular-nums">
                        {order.dueDate} <span className="text-ink-2">W{isoWeek(order.weekStart)}</span>
                      </td>
                      <td className="px-2 py-2 tabular-nums">{order.startDate}</td>
                      <td className="px-2 py-2 text-[12.5px]">
                        {order.source === 'MPS'
                          ? 'MPS'
                          : covers(order).map((c, i) => (
                              <span key={i} className="block">
                                {c}
                              </span>
                            ))}
                        {order.bomVersionNo !== null ? <span className="block text-ink-2">BOM v{order.bomVersionNo}</span> : null}
                      </td>
                      <td className="px-2 py-2">
                        {order.workOrder ? (
                          <a className="font-mono text-link" href={`/ppic/work-orders?order=${order.workOrder.id}`}>
                            {order.workOrder.code}
                          </a>
                        ) : (
                          <span className="text-ink-2">—</span>
                        )}
                      </td>
                      <td className="px-2 py-2">
                        <div className="flex flex-wrap gap-1">
                          {order.warnings.map((w) => (
                            <Badge key={w} tone={w === 'LATE_START' || w === 'NO_BOM' ? 'danger' : 'warning'}>
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
            Released, a planned order becomes a work order Draft with its quantity and due date; a second person releases the work order as usual.
          </p>
        </CardContent>
      </Card>

      <Card className="mt-4">
        <CardHeader>
          <CardTitle>Per item</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Items of the run table">
            <table className="w-full border-collapse text-[13.5px]" aria-label="Items of the run">
              <thead>
                <tr className="border-b border-line text-left text-ink-2">
                  <th className="px-2 py-2 font-medium">Item</th>
                  <th className="px-2 py-2 text-right font-medium">Level</th>
                  <th className="px-2 py-2 text-right font-medium">Needed</th>
                  <th className="px-2 py-2 text-right font-medium">On hand</th>
                  <th className="px-2 py-2 text-right font-medium">For released WOs</th>
                  <th className="px-2 py-2 text-right font-medium">For WOs from the plan</th>
                  <th className="px-2 py-2 text-right font-medium">Incoming</th>
                  <th className="px-2 py-2 text-right font-medium">Safety stock</th>
                  <th className="px-2 py-2 text-right font-medium">Lead time</th>
                </tr>
              </thead>
              <tbody>
                {r.items.map((i) => (
                  <tr key={i.id} className="border-b border-line">
                    <td className="px-2 py-2">
                      <span className="font-mono">{i.itemCode}</span> <span className="text-ink-2">· {TYPE[i.itemType] ?? i.itemType}</span>
                      {i.warnings.map((w) => (
                        <Badge key={w} tone="warning" className="ml-1">
                          {w === 'UOM_CONVERSION_MISSING' ? 'Released work order not netted: unit conversion missing' : (WARNING[w] ?? w)}
                        </Badge>
                      ))}
                    </td>
                    <td className="px-2 py-2 text-right tabular-nums">{i.level}</td>
                    <td className="px-2 py-2 text-right tabular-nums">{sum(i.requirements.map((q) => q.qty)) || '—'}</td>
                    <td className="px-2 py-2 text-right tabular-nums">
                      {i.onHand} {i.uomCode}
                    </td>
                    <td className="px-2 py-2 text-right tabular-nums">{i.committed}</td>
                    <td className="px-2 py-2 text-right tabular-nums">{Number(i.plannedWorkOrderNeed) === 0 ? '—' : i.plannedWorkOrderNeed}</td>
                    <td className="px-2 py-2 text-right tabular-nums">{sum(i.supplies.map((s) => s.qty)) || '—'}</td>
                    <td className="px-2 py-2 text-right tabular-nums">{i.safetyStock ?? <Badge tone="neutral">Not configured</Badge>}</td>
                    <td className="px-2 py-2 text-right tabular-nums">
                      {i.leadTimeDays === null ? <Badge tone="neutral">Not configured</Badge> : `${i.leadTimeDays} working days`}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`Release ${picked.length} planned ${picked.length === 1 ? 'order' : 'orders'} to work orders`}
        description="Each becomes a work order Draft with the planned quantity and due date (reason: from the plan). From then on it counts as supply in the MPS and MRP."
        confirmLabel="Release to WO"
        onConfirm={release}
      />
    </EnterprisePage>
  );
}

/** Sum of decimal strings for display (6 decimals at most). */
function sum(values: string[]): string {
  if (values.length === 0) return '';
  const total = values.reduce((acc, v) => acc + BigInt(Math.round(Number(v) * 1e6)), 0n);
  return (Number(total) / 1e6).toLocaleString('en-US', { maximumFractionDigits: 6, useGrouping: false });
}

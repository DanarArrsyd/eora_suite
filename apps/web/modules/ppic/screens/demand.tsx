'use client';

import * as React from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { DataTable, DomainStatusBadge, type EoraColumn, type FilterField } from '@eora/features/patterns';
import type { ListQuery } from '@eora/features/data';
import { Badge, Card, CardContent, CardDescription, CardHeader, CardTitle } from '@eora/ui';
import type { BlanketOrderRow, ControlTowerSources, DailyDeliveryRow, DeliveryRiskRow, DemandLineRow, WeeklyDemandRow } from '../contracts';
import { CtScreen, type CtScreenConfig } from '../components/ct-screen';
import { RiskReasons } from '../components/badges';
import { fmtDate, fmtDay, fmtQty } from '../components/format';
import { CUSTOMER_FIELD, DUE_FIELD, HREF, PART_FIELD, PLANT_FIELD, customerLabel, partLabel, q } from './common';

const EXCEPTION_LABEL = {
  REVISION_MAPPING_AMBIGUOUS: 'Revision mapping needs confirmation',
  FORECAST_MATCH_UNCERTAIN: 'Forecast match uncertain',
  BLANKET_EXCEEDED: 'Blanket exceeded',
} as const;

/* ------------------------------------------------------------------ CT-9 Demand & Fulfillment ------------------------------------------------------------------ */

const BUCKET_FIELD: FilterField = {
  key: 'bucket',
  type: 'select',
  source: 'static',
  label: 'Week (bucket)',
  options: ['2026-W41', '2026-W42', '2026-W43', '2026-W44'].map((w) => ({ value: w, label: w })),
};

function WeeklyAndBlanketPanel({ query, sources, mode }: { query: ListQuery; sources: ControlTowerSources; mode: string }) {
  const scope = { filters: { customer: query.filters.customer, part: query.filters.part, bucket: query.filters.bucket }, search: '' };
  const listQuery = (sort: string): ListQuery => ({
    page: 1,
    pageSize: 100,
    sort: [{ id: sort, desc: false }],
    filters: scope.filters,
    search: '',
    view: 'all',
  });
  const weekly = useQuery({
    queryKey: [sources.weeklyDemand.resource, mode, scope],
    queryFn: ({ signal }) => sources.weeklyDemand.list(listQuery('bucket'), signal),
    placeholderData: keepPreviousData,
  });
  const blanket = useQuery({
    queryKey: [sources.blanketOrders.resource, mode, scope.filters.customer, scope.filters.part],
    queryFn: ({ signal }) =>
      sources.blanketOrders.list({ ...listQuery('soNo'), filters: { customer: scope.filters.customer, part: scope.filters.part } }, signal),
    placeholderData: keepPreviousData,
  });
  const weeklyCols: EoraColumn<WeeklyDemandRow>[] = [
    { id: 'bucket', header: 'Week', lead: true, cell: (r) => <span className="font-medium">{r.bucket}</span>, sub: (r) => `from ${fmtDate(r.bucketStart)}` },
    { id: 'part', header: 'Part', cell: (r) => r.part },
    { id: 'customer', header: 'Customer', cell: (r) => (r.customer ? r.customer : <Badge tone="warning">No customer</Badge>) },
    { id: 'firmRequired', header: 'Firm required', numeric: true, cell: (r) => q(r.firmRequired, 'pcs') },
    { id: 'firmOutstanding', header: 'Firm outstanding', numeric: true, critical: true, cell: (r) => q(r.firmOutstanding, 'pcs') },
    { id: 'forecast', header: 'Forecast', numeric: true, cell: (r) => q(r.forecast, 'pcs') },
    { id: 'netForecast', header: 'Net forecast', numeric: true, cell: (r) => q(r.netForecast, 'pcs') },
    {
      id: 'outstandingDemand',
      header: 'Outstanding demand',
      numeric: true,
      critical: true,
      cell: (r) => <b className="font-semibold">{q(r.outstandingDemand, 'pcs')}</b>,
    },
    {
      id: 'match',
      header: 'Forecast match',
      cell: (r) =>
        r.matchStatus === 'NEEDS_REVIEW' ? (
          <Badge tone="warning">Needs review — not netted</Badge>
        ) : r.matchStatus === 'MATCHED' ? (
          <Badge tone="success">Consumed in bucket</Badge>
        ) : r.matchStatus === 'FORECAST_ONLY' ? (
          <Badge tone="info">Forecast only</Badge>
        ) : (
          <span className="text-ink-2">No forecast</span>
        ),
    },
  ];
  const blanketCols: EoraColumn<BlanketOrderRow>[] = [
    {
      id: 'so',
      header: 'Blanket SO line',
      lead: true,
      cell: (r) => <span className="font-medium">{`${r.soNo}/${r.soLine}`}</span>,
      sub: (r) => `${customerLabel(r.customer)} · rev ${r.revision}`,
    },
    { id: 'part', header: 'Part', cell: (r) => r.part },
    { id: 'blanketQty', header: 'Blanket order qty', numeric: true, critical: true, cell: (r) => q(r.blanketQty, 'pcs') },
    { id: 'releasedQty', header: 'Released', numeric: true, critical: true, cell: (r) => q(r.releasedQty, 'pcs') },
    { id: 'deliveredQty', header: 'Delivered', numeric: true, critical: true, cell: (r) => q(r.deliveredQty, 'pcs') },
    { id: 'remaining', header: 'Remaining scheduled', numeric: true, critical: true, cell: (r) => q(r.remainingScheduledQty, 'pcs') },
    {
      id: 'unscheduled',
      header: 'Unscheduled blanket balance',
      numeric: true,
      critical: true,
      cell: (r) => <b className="font-semibold">{q(r.unscheduledBalance, 'pcs')}</b>,
    },
  ];
  return (
    <div className="mb-5 grid gap-5 3xl:grid-cols-[3fr_2fr]">
      <Card>
        <CardHeader>
          <div>
            <CardTitle>Weekly demand · MPS netting bucket</CardTitle>
            <CardDescription>
              Weekly buckets (plant calendar, ISO week from Monday 00:00 WIB) for basic netting. Firm demand consumes forecast only for the same customer, part
              and plant (D-040). Daily due dates stay in the list below (D-041).
            </CardDescription>
          </div>
        </CardHeader>
        <DataTable
          label="Weekly demand by bucket"
          columns={weeklyCols}
          rows={weekly.data?.rows ?? []}
          getRowId={(r) => r.id}
          rowLabel={(r) => `${r.bucket} ${r.part} ${r.customer ?? 'no customer'}`}
          status={weekly.isError ? 'error' : weekly.isPending ? 'loading' : 'ready'}
          error={weekly.error}
          onRetry={() => weekly.refetch()}
          empty={<p className="m-0 px-5 py-6 text-[13.5px] text-ink-2">No demand in these buckets.</p>}
          sort={[]}
          onSortChange={() => {}}
          density="compact"
          skeletonRows={4}
        />
        <CardContent className="pt-3 text-xs text-ink-2">
          Outstanding demand = firm outstanding + net forecast; net forecast = max(0, forecast − firm required) within the bucket. Unscheduled blanket balance
          is never in a bucket. Net production requirement (BR-PLN-05) is still proposed and not shown.
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <div>
            <CardTitle>Blanket orders</CardTitle>
            <CardDescription>
              Released releases are dated demand; the unscheduled balance is a commercial commitment, not a production requirement (D-039).
            </CardDescription>
          </div>
        </CardHeader>
        <DataTable
          label="Blanket order balances"
          columns={blanketCols}
          rows={blanket.data?.rows ?? []}
          getRowId={(r) => r.id}
          rowLabel={(r) => `${r.soNo}/${r.soLine}`}
          status={blanket.isError ? 'error' : blanket.isPending ? 'loading' : 'ready'}
          error={blanket.error}
          onRetry={() => blanket.refetch()}
          empty={<p className="m-0 px-5 py-6 text-[13.5px] text-ink-2">No blanket orders in this scope.</p>}
          sort={[]}
          onSortChange={() => {}}
          density="compact"
          skeletonRows={2}
        />
        <CardContent className="pt-3 text-xs text-ink-2">
          Released ≤ blanket order quantity unless an approved order revision raises it. Delivered is never counted as remaining demand.
        </CardContent>
      </Card>
    </div>
  );
}

const DEMAND: CtScreenConfig<DemandLineRow> = {
  id: 'demand',
  code: 'CT-9',
  title: 'Demand & Fulfillment',
  description:
    'Customer PO → Sales Order line → delivery schedule lines and releases, with daily due dates, partial fulfilment and outstanding. One demand identity per SO line (D-034).',
  source: (s) => s.demandLines,
  itemLabel: 'schedule lines',
  fields: [PLANT_FIELD, CUSTOMER_FIELD, PART_FIELD, BUCKET_FIELD, DUE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Outstanding' },
    { value: 'due7', label: 'Due ≤ 7 days' },
    { value: 'partial', label: 'Partially fulfilled' },
    { value: 'blanket', label: 'Blanket releases' },
    { value: 'exceptions', label: 'Exceptions' },
    { value: 'history', label: 'Fulfilled & superseded' },
  ],
  viewNote: 'Superseded revisions stay visible for history and count zero; shipments keep their original schedule line (D-038).',
  defaultSort: [{ id: 'requirementDate', desc: false }],
  searchPlaceholder: 'Search schedule, SO, customer PO…',
  rowLabel: (r) => `schedule line ${r.scheduleRef}`,
  columns: [
    {
      id: 'scheduleRef',
      header: 'Schedule line',
      lead: true,
      cell: (r) => <span className="font-medium">{r.scheduleRef}</span>,
      sub: (r) =>
        `${r.scheduleType === 'KANBAN_RELEASE' ? 'Kanban release' : r.scheduleType === 'MANUAL' ? 'Manual' : 'Customer schedule'} · rev ${r.revision}`,
    },
    { id: 'requirementDate', header: 'Due date', sortable: true, critical: true, cell: (r) => fmtDay(r.requirementDate), sub: (r) => r.bucket },
    { id: 'part', header: 'Part', sortable: true, cell: (r) => r.part, sub: (r) => customerLabel(r.customer) },
    { id: 'so', header: 'Sales order', cell: (r) => `${r.soNo}/${r.soLine}`, sub: (r) => `from ${r.cpoNo}/${r.cpoLine}${r.blanket ? ' · blanket' : ''}` },
    { id: 'required', header: 'Required', numeric: true, critical: true, cell: (r) => q(r.required, 'pcs'), emptyOnPhone: (r) => !r.required },
    { id: 'delivered', header: 'Delivered', numeric: true, critical: true, cell: (r) => q(r.delivered, 'pcs'), emptyOnPhone: (r) => !r.delivered },
    {
      id: 'outstanding',
      header: 'Outstanding',
      numeric: true,
      critical: true,
      sortable: true,
      cell: (r) => <b className="font-semibold">{q(r.outstanding, 'pcs')}</b>,
    },
    { id: 'allocated', header: 'FG allocated', numeric: true, cell: (r) => q(r.allocated, 'pcs'), emptyOnPhone: (r) => !r.allocated },
    {
      id: 'status',
      header: 'Status',
      critical: true,
      cell: (r) => (r.exception ? <Badge tone="warning">{EXCEPTION_LABEL[r.exception]}</Badge> : <DomainStatusBadge domain="schedule" status={r.status} />),
    },
  ],
  detail: (r) => ({
    title: `Schedule line ${r.scheduleRef}`,
    subtitle: `${customerLabel(r.customer)} · ${partLabel(r.part)} · due ${fmtDay(r.requirementDate)} (${r.bucket})`,
    sections: [
      {
        title: 'Demand identity',
        rows: [
          ['Sales order line', `${r.soNo}/${r.soLine}`],
          ['Source customer PO', `${r.cpoNo}/${r.cpoLine} (not netted separately)`],
          ['Schedule type · revision', `${r.scheduleType} · rev ${r.revision}`],
          ['Status', <DomainStatusBadge key="s" domain="schedule" status={r.status} />],
        ],
      },
      {
        title: 'Fulfilment (pcs)',
        rows: [
          ['Required', fmtQty(r.required, 'pcs')],
          ['Delivered (posted shipments)', fmtQty(r.delivered, 'pcs')],
          ['Outstanding', fmtQty(r.outstanding, 'pcs')],
          ['FG allocated (not fulfilment)', fmtQty(r.allocated, 'pcs')],
          ...r.shipments.map((s): [string, React.ReactNode] => [
            `Shipment ${s.ref} · ${fmtDate(s.date)}`,
            `${fmtQty(s.qty, 'pcs')}${s.reversed ? ' (reversed)' : ''}`,
          ]),
        ],
      },
    ],
    calculation: {
      title: 'Outstanding (BR-DEM-10)',
      body: (
        <>
          outstanding = max(0, required − Σ posted shipments + Σ reversals) = max(0, {r.required} − {r.delivered}) ={' '}
          {r.status === 'SUPERSEDED' || r.status === 'DRAFT' ? '0 (counts zero in this status)' : r.outstanding}
        </>
      ),
    },
    sources: [
      { label: 'Customer PO', ref: `${r.cpoNo}/${r.cpoLine}`, href: HREF.customerPo },
      { label: 'Sales order', ref: `${r.soNo}/${r.soLine}`, href: HREF.salesOrders },
      { label: 'Delivery schedule', ref: `${r.scheduleRef} rev ${r.revision}`, href: HREF.schedules },
      ...r.shipments.map((s) => ({ label: 'Shipment', ref: s.ref, href: HREF.shipments })),
    ],
  }),
  footnote:
    'Required, delivered and outstanding are disjoint: delivered never appears as outstanding demand. Allocation reserves FG but does not fulfil (BR-DEM-09).',
  Panel: WeeklyAndBlanketPanel,
};

/* ------------------------------------------------------------------ CT-5 Daily Delivery ------------------------------------------------------------------ */

const DELIVERY: CtScreenConfig<DailyDeliveryRow> = {
  id: 'daily-delivery',
  code: 'CT-5',
  title: 'Daily Delivery & Outstanding',
  description:
    'What is due per day, what shipped (posted shipments), what is outstanding and whether free or allocated FG covers it. Daily dates are never rolled into weeks.',
  source: (s) => s.dailyDelivery,
  itemLabel: 'delivery lines',
  fields: [PLANT_FIELD, CUSTOMER_FIELD, PART_FIELD, DUE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'partial', label: 'Partial shipment' },
    { value: 'risk', label: 'At risk' },
    { value: 'shipped', label: 'Fully shipped' },
  ],
  defaultSort: [{ id: 'dueDate', desc: false }],
  searchPlaceholder: 'Search schedule, SO, part…',
  rowLabel: (r) => `delivery line ${r.scheduleRef}`,
  groups: [
    { id: 'ful', label: 'Fulfilment (pcs)' },
    { id: 'cov', label: 'FG coverage (pcs)' },
  ],
  columns: [
    {
      id: 'dueDate',
      header: 'Due date',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{fmtDay(r.dueDate)}</span>,
      sub: (r) => r.scheduleRef,
    },
    { id: 'part', header: 'Part', cell: (r) => r.part, sub: (r) => customerLabel(r.customer) },
    { id: 'due', header: 'Due', numeric: true, critical: true, group: 'ful', cell: (r) => q(r.due, 'pcs') },
    { id: 'shipped', header: 'Shipped', numeric: true, critical: true, group: 'ful', cell: (r) => q(r.shipped, 'pcs'), emptyOnPhone: (r) => !r.shipped },
    { id: 'pod', header: 'Delivered (POD)', numeric: true, group: 'ful', cell: (r) => q(r.deliveredPod, 'pcs'), emptyOnPhone: (r) => !r.deliveredPod },
    {
      id: 'outstanding',
      header: 'Outstanding',
      numeric: true,
      critical: true,
      sortable: true,
      group: 'ful',
      cell: (r) => <b className="font-semibold">{q(r.outstanding, 'pcs')}</b>,
    },
    { id: 'allocated', header: 'Allocated', numeric: true, group: 'cov', cell: (r) => q(r.allocated, 'pcs'), emptyOnPhone: (r) => !r.allocated },
    { id: 'fgAvailable', header: 'FG available (part)', numeric: true, group: 'cov', cell: (r) => q(r.fgAvailable, 'pcs') },
    { id: 'gap', header: 'Gap', numeric: true, critical: true, sortable: true, group: 'cov', cell: (r) => q(r.gap, 'pcs') },
    {
      id: 'risk',
      header: 'Status',
      critical: true,
      cell: (r) =>
        r.outstanding === 0 ? (
          <Badge tone="success">Shipped</Badge>
        ) : r.risk ? (
          <Badge tone="danger">At risk</Badge>
        ) : r.partial ? (
          <Badge tone="warning">Partial</Badge>
        ) : (
          <Badge tone="info">Open</Badge>
        ),
    },
  ],
  detail: (r) => ({
    title: `Delivery ${r.scheduleRef} · ${fmtDay(r.dueDate)}`,
    subtitle: `${customerLabel(r.customer)} · ${partLabel(r.part)} · ${r.soNo}`,
    sections: [
      {
        title: 'Fulfilment (pcs)',
        rows: [
          ['Due', fmtQty(r.due, 'pcs')],
          ['Shipped (posted)', fmtQty(r.shipped, 'pcs')],
          ['Delivered (proof of delivery)', fmtQty(r.deliveredPod, 'pcs')],
          ['Outstanding', fmtQty(r.outstanding, 'pcs')],
        ],
      },
      {
        title: 'Partial shipments',
        rows: r.shipments.length
          ? r.shipments.map((s): [string, React.ReactNode] => [`${s.ref} · ${fmtDate(s.date)}`, `${fmtQty(s.qty, 'pcs')}${s.reversed ? ' (reversed)' : ''}`])
          : [['None yet', '—']],
      },
      {
        title: 'Coverage (pcs)',
        rows: [
          ['Allocated to this line', fmtQty(r.allocated, 'pcs')],
          ['FG available for the part', fmtQty(r.fgAvailable, 'pcs')],
          ['Gap', fmtQty(r.gap, 'pcs')],
        ],
      },
    ],
    calculation: {
      title: 'Gap',
      body: <>gap = max(0, outstanding − (allocated + free FG)); free FG = FG available − allocations of this line. Pending QC never covers a delivery.</>,
    },
    sources: [
      { label: 'Delivery schedule', ref: r.scheduleRef, href: HREF.schedules },
      { label: 'Delivery orders', ref: r.soNo, href: HREF.deliveryOrders },
      ...r.shipments.map((s) => ({ label: 'Shipment', ref: s.ref, href: HREF.shipments })),
    ],
  }),
  footnote: 'Shipped = posted shipments (net of reversals); Delivered (POD) = confirmed proof of delivery. Outstanding = due − shipped.',
};

/* ------------------------------------------------------------------ CT-8 Shortage & Delivery Risk ------------------------------------------------------------------ */

const RISK: CtScreenConfig<DeliveryRiskRow> = {
  id: 'delivery-risk',
  code: 'CT-8',
  title: 'Shortage & Delivery Risk',
  description:
    'Which demand lines will miss their due date, and why. Each supply contribution is its own column; daily risk is shown even when the week is covered (D-041).',
  source: (s) => s.deliveryRisk,
  itemLabel: 'demand lines',
  fields: [PLANT_FIELD, CUSTOMER_FIELD, PART_FIELD, DUE_FIELD],
  views: [
    { value: 'all', label: 'All open' },
    { value: 'atRisk', label: 'At risk' },
    { value: 'next7', label: 'At risk ≤ 7 days' },
    { value: 'covered', label: 'Covered' },
  ],
  defaultSort: [{ id: 'dueDate', desc: false }],
  searchPlaceholder: 'Search schedule, part…',
  rowLabel: (r) => `demand line ${r.scheduleRef}`,
  groups: [{ id: 'sup', label: 'Projected supply before due (pcs)', title: 'Projected = FG available + QC released + planned output − earlier demand' }],
  columns: [
    {
      id: 'dueDate',
      header: 'Due date',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{fmtDay(r.dueDate)}</span>,
      sub: (r) => r.scheduleRef,
    },
    { id: 'part', header: 'Part', cell: (r) => r.part, sub: (r) => customerLabel(r.customer) },
    { id: 'outstanding', header: 'Outstanding', numeric: true, critical: true, cell: (r) => q(r.outstanding, 'pcs') },
    { id: 'fgAvailable', header: 'FG available', numeric: true, group: 'sup', cell: (r) => q(r.fgAvailable, 'pcs') },
    { id: 'qcReleased', header: 'QC released', numeric: true, group: 'sup', cell: (r) => q(r.qcReleased, 'pcs') },
    { id: 'plannedOutput', header: 'Planned output', numeric: true, group: 'sup', cell: (r) => q(r.plannedOutput, 'pcs') },
    {
      id: 'earlierDemand',
      header: 'Earlier demand',
      numeric: true,
      group: 'sup',
      cell: (r) => (r.earlierDemand ? <>−{q(r.earlierDemand, 'pcs')}</> : q(0, 'pcs')),
    },
    {
      id: 'projected',
      header: 'Projected',
      numeric: true,
      critical: true,
      group: 'sup',
      cell: (r) => <b className="font-semibold">{q(Math.max(0, r.projected), 'pcs')}</b>,
    },
    { id: 'pendingQc', header: 'Pending QC (not projected)', numeric: true, cell: (r) => q(r.pendingQc, 'pcs') },
    { id: 'gap', header: 'Gap', numeric: true, critical: true, sortable: true, cell: (r) => q(r.gap, 'pcs') },
    {
      id: 'reasons',
      header: 'Risk',
      critical: true,
      cell: (r) =>
        r.atRisk ? (
          <span className="inline-flex flex-wrap items-center gap-1">
            <Badge tone="danger">At risk</Badge>
            <RiskReasons reasons={r.reasons} />
          </span>
        ) : (
          <Badge tone="success">Covered</Badge>
        ),
    },
  ],
  detail: (r) => ({
    title: `Delivery risk · ${r.scheduleRef}`,
    subtitle: `${customerLabel(r.customer)} · ${partLabel(r.part)} · due ${fmtDay(r.dueDate)}`,
    sections: [
      {
        title: 'Projection (pcs)',
        rows: [
          ['FG available', fmtQty(r.fgAvailable, 'pcs')],
          ['+ QC released (not yet received)', fmtQty(r.qcReleased, 'pcs')],
          ['+ Planned output before due', fmtQty(r.plannedOutput, 'pcs')],
          ['− Earlier demand', fmtQty(r.earlierDemand, 'pcs')],
          ['= Projected', fmtQty(r.projected, 'pcs')],
          ['Outstanding', fmtQty(r.outstanding, 'pcs')],
          ['Gap', fmtQty(r.gap, 'pcs')],
          ['Pending QC (shown, not projected)', fmtQty(r.pendingQc, 'pcs')],
        ],
      },
      { title: 'Risk reasons', rows: [['Codes', <RiskReasons key="r" reasons={r.reasons} />]] },
    ],
    calculation: {
      title: 'doc 19 CT-8',
      body: (
        <>
          Pending QC is excluded until a tenant expected-acceptance policy is approved; it is shown so PPIC can chase QC. Netting follows BR-DEM-03 (weekly) but
          the risk is evaluated per due date.
        </>
      ),
    },
    sources: [
      { label: 'Delivery schedule', ref: r.scheduleRef, href: HREF.schedules },
      { label: 'Work orders for the part', ref: r.part, href: HREF.workOrders },
      { label: 'FG stock', ref: r.part, href: HREF.finishedGoods },
      { label: 'Demand line in CT-9', ref: r.scheduleRef, href: `/ppic/demand-fulfillment?f.part=${r.part}` },
    ],
  }),
  footnote: 'At-risk lines are not delivery failures yet; they show where supply timing does not cover the due date.',
};

export function DemandFulfillmentScreen() {
  return <CtScreen config={DEMAND} />;
}
export function DailyDeliveryScreen() {
  return <CtScreen config={DELIVERY} />;
}
export function DeliveryRiskScreen() {
  return <CtScreen config={RISK} />;
}

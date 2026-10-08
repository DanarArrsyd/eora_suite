'use client';

import * as React from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import type { ListQuery } from '@eora/features/data';
import { Badge } from '@eora/ui';
import type {
  ControlTowerSources,
  DailyFgReceiptRow,
  DailyProductionRow,
  FgAvailabilityRow,
  MaterialVarianceRow,
  PendingQcRow,
  WoProgressRow,
} from '../contracts';
import { CtScreen, type CtScreenConfig } from '../components/ct-screen';
import { IndicatorCell, MethodLabel } from '../components/badges';
import { fmtDate, fmtDateTime, fmtDay, fmtQty, fmtSigned } from '../components/format';
import { HREF, PART_FIELD, PLANT_FIELD, SHIFT_FIELD, partLabel, q } from './common';

const DATE_FIELD: FilterField = { key: 'date', type: 'daterange', label: 'Production date' };

/* ------------------------------------------------------------------ CT-10 Work Order Progress ------------------------------------------------------------------ */

const WO: CtScreenConfig<WoProgressRow> = {
  id: 'wo',
  code: 'CT-10',
  title: 'Work Order Progress',
  description:
    'Good output against planned quantity per work order, with NG, process scrap and actual material consumption in material UOM. Pieces and kilograms are never combined.',
  source: (s) => s.woProgress,
  itemLabel: 'work orders',
  fields: [PLANT_FIELD, PART_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'materialShort', label: 'Material short' },
    { value: 'unmeasured', label: 'Consumption not measured' },
    { value: 'closed', label: 'Closed' },
  ],
  defaultSort: [{ id: 'dueDate', desc: false }],
  searchPlaceholder: 'Search WO or part…',
  rowLabel: (r) => r.wo,
  groups: [
    { id: 'out', label: 'Output (pcs)' },
    { id: 'mat', label: 'Material' },
  ],
  columns: [
    { id: 'wo', header: 'Work order', lead: true, sortable: true, cell: (r) => <span className="font-medium">{r.wo}</span>, sub: (r) => partLabel(r.part) },
    { id: 'dueDate', header: 'Due', sortable: true, cell: (r) => fmtDay(r.dueDate) },
    {
      id: 'status',
      header: 'Status',
      critical: true,
      cell: (r) => <DomainStatusBadge domain="document" status={r.status === 'IN_PROGRESS' ? 'RELEASED' : r.status} />,
    },
    {
      id: 'progress',
      header: 'Good of planned',
      critical: true,
      cell: (r) => (
        <span className="inline-flex items-center gap-2">
          <span className="tabular-nums">
            {fmtQty(r.good, 'pcs')} / {fmtQty(r.plannedQty, 'pcs')}
          </span>
          <span aria-hidden="true" className="h-1.5 w-16 overflow-hidden rounded-full bg-hover">
            <span className="block h-full bg-accent" style={{ width: `${Math.min(100, (r.good / r.plannedQty) * 100)}%` }} />
          </span>
        </span>
      ),
    },
    { id: 'ngRework', header: 'NG reworkable', numeric: true, group: 'out', cell: (r) => q(r.ngRework, 'pcs') },
    { id: 'ngRejected', header: 'NG rejected', numeric: true, group: 'out', cell: (r) => q(r.ngRejected, 'pcs') },
    { id: 'scrap', header: 'Process scrap', numeric: true, group: 'mat', cell: (r) => q(r.scrapKg, 'kg') },
    {
      id: 'consumed',
      header: 'Actual consumed',
      numeric: true,
      critical: true,
      group: 'mat',
      cell: (r) => q(r.consumed, r.consumedUom),
      sub: (r) => (r.good > 0 ? (r.consumptionMeasured ? 'measured' : 'not measured') : ''),
    },
    {
      id: 'flags',
      header: 'Flags',
      cell: (r) =>
        r.materialShort ? (
          <Badge tone="warning">Material short</Badge>
        ) : r.good > 0 && !r.consumptionMeasured ? (
          <Badge tone="warning">Unmeasured consumption</Badge>
        ) : (
          <span className="text-ink-2">—</span>
        ),
    },
  ],
  detail: (r) => ({
    title: `${r.wo} · ${partLabel(r.part)}`,
    subtitle: `Due ${fmtDay(r.dueDate)} · status ${r.status.replace('_', ' ').toLowerCase()}`,
    sections: [
      {
        title: 'Output (pcs)',
        rows: [
          ['Planned', fmtQty(r.plannedQty, 'pcs')],
          ['Good', fmtQty(r.good, 'pcs')],
          ['NG reworkable', fmtQty(r.ngRework, 'pcs')],
          ['NG rejected', fmtQty(r.ngRejected, 'pcs')],
        ],
      },
      {
        title: 'Material',
        rows: [
          ['Actual consumed', `${fmtQty(r.consumed, r.consumedUom)} ${r.consumedUom}`],
          ['Measured', r.consumptionMeasured ? 'Yes' : 'No — provisional'],
          ['Process scrap (weighed)', `${fmtQty(r.scrapKg, 'kg')} kg`],
        ],
      },
    ],
    sources: [
      { label: 'Work order', ref: r.wo, href: HREF.workOrders },
      { label: 'Production postings', ref: r.wo, href: HREF.postings },
      { label: 'Variance by run', ref: r.wo, href: `/ppic/material-variance?q=${r.wo}` },
    ],
  }),
  footnote: 'Good output is not FG: it becomes FG available only after QC release and FG receipt.',
};

/* ------------------------------------------------------------------ CT-10 Daily production output ------------------------------------------------------------------ */

const DAILY: CtScreenConfig<DailyProductionRow> = {
  id: 'daily-production',
  code: 'CT-10',
  title: 'Daily Production Results',
  description:
    'Production postings per production date and shift (plant shift calendar): good, NG reworkable, NG rejected, weighed process scrap and actual material consumption with its measurement method.',
  source: (s) => s.dailyProduction,
  itemLabel: 'run postings',
  fields: [PLANT_FIELD, PART_FIELD, SHIFT_FIELD, DATE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'unmeasured', label: 'Unmeasured consumption' },
  ],
  defaultSort: [{ id: 'productionDate', desc: true }],
  searchPlaceholder: 'Search WO, run, part…',
  rowLabel: (r) => `${r.run} ${r.productionDate} ${r.shift}`,
  groups: [{ id: 'out', label: 'Output (pcs)' }],
  columns: [
    {
      id: 'productionDate',
      header: 'Production date',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{fmtDay(r.productionDate)}</span>,
      sub: (r) => `Shift ${r.shift.slice(1)}`,
    },
    { id: 'run', header: 'Run', cell: (r) => r.run, sub: (r) => `${r.wo} · ${r.workCenter}` },
    { id: 'part', header: 'Part', cell: (r) => r.part },
    { id: 'good', header: 'Good', numeric: true, critical: true, sortable: true, group: 'out', cell: (r) => q(r.good, 'pcs') },
    { id: 'ngRework', header: 'NG reworkable', numeric: true, critical: true, group: 'out', cell: (r) => q(r.ngRework, 'pcs') },
    { id: 'ngRejected', header: 'NG rejected', numeric: true, critical: true, group: 'out', cell: (r) => q(r.ngRejected, 'pcs') },
    { id: 'scrap', header: 'Process scrap', numeric: true, critical: true, cell: (r) => q(r.scrapKg, 'kg') },
    { id: 'consumed', header: 'Actual consumed', numeric: true, critical: true, cell: (r) => q(r.consumed, r.consumedUom) },
    { id: 'method', header: 'Measurement', cell: (r) => <MethodLabel method={r.method} /> },
  ],
  detail: (r) => ({
    title: `${r.run} · ${fmtDay(r.productionDate)} shift ${r.shift.slice(1)}`,
    subtitle: `${r.wo} · ${partLabel(r.part)} · ${r.workCenter}`,
    sections: [
      {
        title: 'Outputs',
        rows: [
          ['Good', `${fmtQty(r.good, 'pcs')} pcs`],
          ['NG reworkable', `${fmtQty(r.ngRework, 'pcs')} pcs`],
          ['NG rejected', `${fmtQty(r.ngRejected, 'pcs')} pcs`],
          ['Process scrap', `${fmtQty(r.scrapKg, 'kg')} kg`],
        ],
      },
      {
        title: 'Input',
        rows: [
          ['Actual consumed', `${fmtQty(r.consumed, r.consumedUom)} ${r.consumedUom}`],
          ['Measurement', <MethodLabel key="m" method={r.method} />],
        ],
      },
    ],
    sources: [
      { label: 'Production posting', ref: r.run, href: HREF.postings },
      { label: 'Run', ref: r.run, href: HREF.runs },
      { label: 'Measurements', ref: r.run, href: HREF.measurements },
    ],
  }),
  footnote: 'Day boundary follows the plant shift calendar (production date, DD-08). Consumption by difference is provisional and opens a case (ADR-0003).',
};

/* ------------------------------------------------------------------ CT-3 Pending QC ------------------------------------------------------------------ */

const AGING_FIELD: FilterField = {
  key: 'agingBucket',
  type: 'select',
  source: 'static',
  label: 'Age',
  options: ['<8h', '8-24h', '24-72h', '>72h'].map((v) => ({ value: v, label: v.replace('h', ' h') })),
};

const QC: CtScreenConfig<PendingQcRow> = {
  id: 'pending-qc',
  code: 'CT-3',
  title: 'Pending Quality',
  description: 'Output waiting for or under quality disposition. None of these quantities is FG available; QC released stock still needs an FG receipt.',
  source: (s) => s.pendingQc,
  itemLabel: 'inspection lots',
  fields: [PLANT_FIELD, PART_FIELD, AGING_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending QC' },
    { value: 'overSla', label: 'Over 24 h SLA' },
    { value: 'hold', label: 'Hold' },
    { value: 'releasedNotReceived', label: 'Released, not received' },
  ],
  defaultSort: [{ id: 'ageHours', desc: true }],
  searchPlaceholder: 'Search inspection lot, output lot, WO…',
  rowLabel: (r) => `inspection lot ${r.inspectionLot}`,
  groups: [{ id: 'disp', label: 'Quality status (pcs)', title: 'Each quantity is a separate stock status' }],
  columns: [
    {
      id: 'lot',
      header: 'Inspection lot',
      lead: true,
      cell: (r) => <span className="font-medium">{r.inspectionLot}</span>,
      sub: (r) => `${r.outputLot} · ${r.wo}`,
    },
    { id: 'part', header: 'Part', cell: (r) => r.part, sub: (r) => `${fmtDate(r.productionDate)} · shift ${r.shift.slice(1)}` },
    { id: 'pendingQc', header: 'Pending QC', numeric: true, critical: true, sortable: true, group: 'disp', cell: (r) => q(r.pendingQc, 'pcs') },
    { id: 'accepted', header: 'Accepted', numeric: true, critical: true, group: 'disp', cell: (r) => q(r.accepted, 'pcs') },
    { id: 'hold', header: 'Hold', numeric: true, critical: true, group: 'disp', cell: (r) => q(r.hold, 'pcs') },
    { id: 'rework', header: 'Rework', numeric: true, critical: true, group: 'disp', cell: (r) => q(r.rework, 'pcs') },
    { id: 'rejected', header: 'Rejected', numeric: true, critical: true, group: 'disp', cell: (r) => q(r.rejected, 'pcs') },
    { id: 'rnr', header: 'Released, not received', numeric: true, cell: (r) => q(r.releasedNotReceived, 'pcs') },
    {
      id: 'ageHours',
      header: 'Age',
      numeric: true,
      sortable: true,
      cell: (r) => (r.pendingQc > 0 && r.ageHours > 24 ? <Badge tone="warning">{r.ageHours} h</Badge> : `${r.ageHours} h`),
    },
    { id: 'inspector', header: 'Inspector', cell: (r) => r.inspector ?? <span className="text-ink-2">Unassigned</span> },
  ],
  detail: (r) => ({
    title: `Inspection lot ${r.inspectionLot}`,
    subtitle: `${partLabel(r.part)} · ${r.outputLot} · ${r.wo} / ${r.run}`,
    sections: [
      {
        title: 'Quality status (pcs)',
        rows: [
          ['Pending QC', fmtQty(r.pendingQc, 'pcs')],
          ['Accepted (QC released)', fmtQty(r.accepted, 'pcs')],
          ['Released, not yet received as FG', fmtQty(r.releasedNotReceived, 'pcs')],
          ['Hold', fmtQty(r.hold, 'pcs')],
          ['Rework', fmtQty(r.rework, 'pcs')],
          ['Rejected', fmtQty(r.rejected, 'pcs')],
        ],
      },
      {
        title: 'Timing',
        rows: [
          ['Age', `${r.ageHours} h (${r.agingBucket})`],
          ['Due by', fmtDateTime(r.dueBy)],
          ['Inspector', r.inspector ?? 'Unassigned'],
        ],
      },
    ],
    sources: [
      { label: 'Inspection lot', ref: r.inspectionLot, href: HREF.inspectionLots },
      { label: 'FG receipt', ref: r.outputLot, href: HREF.fgReceipt },
      { label: 'Run posting', ref: r.run, href: HREF.postings },
    ],
  }),
  footnote: 'Partial dispositions are visible per lot. Pending QC, Hold, Rework and Rejected never appear in FG availability or delivery coverage.',
};

/* ------------------------------------------------------------------ CT-4 Daily FG receipt ------------------------------------------------------------------ */

const FGR: CtScreenConfig<DailyFgReceiptRow> = {
  id: 'daily-fg',
  code: 'CT-4',
  title: 'Daily Finished Goods',
  description:
    'Per production date, shift and part: planned output, good output posted, QC released and FG actually received. Released-not-received is shown separately.',
  source: (s) => s.dailyFgReceipt,
  itemLabel: 'day × shift × part rows',
  fields: [PLANT_FIELD, PART_FIELD, SHIFT_FIELD, DATE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'belowPlan', label: 'Received below plan' },
    { value: 'releasedNotReceived', label: 'Released, not received' },
  ],
  defaultSort: [{ id: 'date', desc: true }],
  rowLabel: (r) => `${r.part} ${r.date} ${r.shift}`,
  groups: [{ id: 'flow', label: 'Pieces by stage' }],
  columns: [
    {
      id: 'date',
      header: 'Production date',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{fmtDay(r.date)}</span>,
      sub: (r) => `Shift ${r.shift.slice(1)}`,
    },
    { id: 'part', header: 'Part', cell: (r) => r.part },
    { id: 'planned', header: 'Planned output', numeric: true, group: 'flow', cell: (r) => q(r.plannedOutput, 'pcs') },
    { id: 'good', header: 'Good posted', numeric: true, critical: true, group: 'flow', cell: (r) => q(r.goodPosted, 'pcs') },
    { id: 'released', header: 'QC released', numeric: true, critical: true, group: 'flow', cell: (r) => q(r.qcReleased, 'pcs') },
    {
      id: 'fgReceived',
      header: 'FG received',
      numeric: true,
      critical: true,
      sortable: true,
      group: 'flow',
      cell: (r) => <b className="font-semibold">{q(r.fgReceived, 'pcs')}</b>,
    },
    {
      id: 'vsPlan',
      header: 'Received vs plan',
      numeric: true,
      cell: (r) => <span className={r.receivedVsPlan < 0 ? 'text-stat-err-fg' : undefined}>{fmtSigned(r.receivedVsPlan, 'pcs')}</span>,
    },
    { id: 'rnr', header: 'Released, not received', numeric: true, critical: true, cell: (r) => q(r.releasedNotReceived, 'pcs') },
  ],
  detail: (r) => ({
    title: `${partLabel(r.part)} · ${fmtDay(r.date)} shift ${r.shift.slice(1)}`,
    sections: [
      {
        title: 'Stages (pcs)',
        rows: [
          ['Planned output', fmtQty(r.plannedOutput, 'pcs')],
          ['Good output posted', fmtQty(r.goodPosted, 'pcs')],
          ['QC released', fmtQty(r.qcReleased, 'pcs')],
          ['FG received', fmtQty(r.fgReceived, 'pcs')],
          ['Released, not received', fmtQty(r.releasedNotReceived, 'pcs')],
        ],
      },
    ],
    calculation: {
      title: 'FG received',
      body: <>FG received = Σ FG receipt legs into FG warehouse for the production date and shift. QC released alone does not change FG stock (doc 14 #7).</>,
    },
    sources: [
      { label: 'FG receipts', ref: `${r.part} ${r.date}`, href: HREF.fgReceipt },
      { label: 'Inspection lots', ref: r.part, href: HREF.inspectionLots },
    ],
  }),
  footnote: 'Each stage is a separate quantity; a stage never implies the next one.',
};

/* ------------------------------------------------------------------ CT-7 FG availability ------------------------------------------------------------------ */

const FGA: CtScreenConfig<FgAvailabilityRow> = {
  id: 'fg',
  code: 'CT-7',
  title: 'Finished Goods Availability',
  description:
    'FG stock by part, location and lot: unrestricted on hand, allocated to delivery lines, available, blocked, and QC-released stock still incoming from QC (not available).',
  source: (s) => s.fgAvailability,
  itemLabel: 'FG stock rows',
  fields: [PLANT_FIELD, PART_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'available', label: 'Available' },
    { value: 'blocked', label: 'Blocked' },
    { value: 'incoming', label: 'Incoming from QC' },
    { value: 'atRisk', label: 'Parts with delivery risk' },
  ],
  defaultSort: [{ id: 'part', desc: false }],
  searchPlaceholder: 'Search part, lot, location…',
  rowLabel: (r) => `${r.part} lot ${r.lot}`,
  groups: [{ id: 'stock', label: 'FG stock (pcs)', title: 'On hand = allocated + available' }],
  columns: [
    {
      id: 'part',
      header: 'Part',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{r.part}</span>,
      sub: (r) => partLabel(r.part).split(' · ')[1],
    },
    { id: 'location', header: 'Location · lot', cell: (r) => r.location, sub: (r) => r.lot },
    { id: 'onHand', header: 'On hand (unrestricted)', numeric: true, critical: true, group: 'stock', cell: (r) => q(r.onHand, 'pcs') },
    { id: 'allocated', header: 'Allocated', numeric: true, critical: true, group: 'stock', cell: (r) => q(r.allocated, 'pcs') },
    {
      id: 'available',
      header: 'Available',
      numeric: true,
      critical: true,
      sortable: true,
      group: 'stock',
      cell: (r) => <b className="font-semibold">{q(r.available, 'pcs')}</b>,
    },
    { id: 'blocked', header: 'Blocked', numeric: true, critical: true, cell: (r) => q(r.blocked, 'pcs') },
    { id: 'incoming', header: 'Incoming from QC', numeric: true, cell: (r) => q(r.incomingFromQc, 'pcs') },
    { id: 'risk', header: 'Demand', cell: (r) => (r.atRiskDemand ? <Badge tone="danger">Delivery risk</Badge> : <span className="text-ink-2">Covered</span>) },
  ],
  detail: (r) => ({
    title: `${partLabel(r.part)}`,
    subtitle: `${r.location} · lot ${r.lot}`,
    sections: [
      {
        title: 'FG stock (pcs)',
        rows: [
          ['On hand (unrestricted)', fmtQty(r.onHand, 'pcs')],
          ['Allocated to delivery lines', fmtQty(r.allocated, 'pcs')],
          ['Available', fmtQty(r.available, 'pcs')],
          ['Blocked', fmtQty(r.blocked, 'pcs')],
          ['Incoming from QC (not available)', fmtQty(r.incomingFromQc, 'pcs')],
        ],
      },
    ],
    calculation: {
      title: 'Available',
      body: <>available = unrestricted on hand − active allocations. Allocation is a reservation, not a movement (DD-03). Pending QC is never included.</>,
    },
    sources: [
      { label: 'FG stock', ref: r.lot, href: HREF.finishedGoods },
      { label: 'Allocations', ref: r.part, href: HREF.allocations },
      { label: 'Delivery risk for the part', ref: r.part, href: `/ppic/shortage?f.part=${r.part}` },
    ],
  }),
  footnote: 'FG shortage per demand line is in CT-8 (Shortage & Delivery Risk). Estimated pieces from raw material never appear here.',
};

/* ------------------------------------------------------------------ CT-6 Material Variance ------------------------------------------------------------------ */

function VarianceSummary({ query, sources, mode }: { query: ListQuery; sources: ControlTowerSources; mode: string }) {
  const lq: ListQuery = { ...query, page: 1, pageSize: 100, view: 'all' };
  const all = useQuery({
    queryKey: [sources.materialVariance.resource, 'summary', mode, query.filters, query.search],
    queryFn: ({ signal }) => sources.materialVariance.list(lq, signal),
    placeholderData: keepPreviousData,
  });
  const rows = all.data?.rows ?? [];
  const count = (s: string) => rows.filter((r) => r.gyvStatus === s).length;
  const items = [
    { label: 'FINAL', value: count('FINAL'), note: 'measured, reporting closed' },
    { label: 'PROVISIONAL', value: count('PROVISIONAL'), note: 'still unproven' },
    { label: 'NOT CALCULABLE', value: count('NOT_CALCULABLE'), note: 'inputs missing' },
    { label: 'Open cases', value: rows.filter((r) => r.case && r.case.status !== 'CLOSED').length, note: 'investigation, not loss' },
  ];
  return (
    <section aria-label="Gross yield variance status of runs in scope" className="mb-5 grid grid-cols-4 gap-3 max-lg:grid-cols-2">
      {items.map((i) => (
        <div key={i.label} className="rounded-lg border border-line bg-surface px-4 py-3 shadow-[var(--shadow-card)]">
          <p className="m-0 text-xs font-semibold tracking-[0.04em] text-ink-2 uppercase">{i.label}</p>
          <p className="m-0 mt-1 text-2xl font-semibold tabular-nums">
            {all.isPending ? '…' : i.value}
            <span className="ml-1.5 text-sm font-normal text-ink-2">{i.label === 'Open cases' ? 'cases' : 'runs'}</span>
          </p>
          <p className="m-0 text-xs text-ink-2">{i.note}</p>
        </div>
      ))}
      <p className="col-span-full m-0 text-xs text-ink-2">Counts of runs by GYV status — there is deliberately no total “loss” figure (doc 19 CT-6).</p>
    </section>
  );
}

const GYV_FIELD: FilterField = {
  key: 'gyvStatus',
  type: 'select',
  source: 'static',
  label: 'Indicator status',
  options: [
    { value: 'FINAL', label: 'Final' },
    { value: 'PROVISIONAL', label: 'Provisional' },
    { value: 'NOT_CALCULABLE', label: 'Not calculable' },
  ],
};
const CASE_FIELD: FilterField = {
  key: 'caseStatus',
  type: 'select',
  source: 'static',
  label: 'Case status',
  options: ['OPEN', 'INVESTIGATING', 'REVIEW', 'CLOSED', 'NONE'].map((v) => ({ value: v, label: v === 'NONE' ? 'No case' : v[0] + v.slice(1).toLowerCase() })),
};

const MV: CtScreenConfig<MaterialVarianceRow> = {
  id: 'variance',
  code: 'CT-6',
  title: 'Material Variance',
  description:
    'Loss investigation per production run: material issued, consumed, remaining and returned; outputs; and the three variance indicators with their finality. Unexplained variance is not confirmed material loss.',
  source: (s) => s.materialVariance,
  itemLabel: 'runs',
  fields: [PLANT_FIELD, PART_FIELD, GYV_FIELD, CASE_FIELD],
  views: [
    { value: 'all', label: 'All' },
    { value: 'breach', label: 'Tolerance breach' },
    { value: 'provisional', label: 'Provisional' },
    { value: 'notCalculable', label: 'Not calculable' },
    { value: 'openCase', label: 'Open case' },
  ],
  defaultSort: [{ id: 'run', desc: true }],
  searchPlaceholder: 'Search run, WO, lot, case…',
  rowLabel: (r) => `run ${r.run}`,
  groups: [
    { id: 'mat', label: 'Material (input UOM)' },
    { id: 'out', label: 'Output (pcs)' },
    { id: 'ind', label: 'Variance indicators (ADR-0005)' },
  ],
  columns: [
    {
      id: 'run',
      header: 'Run',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-medium">{r.run}</span>,
      sub: (r) => `${r.wo} · ${r.part} · lot ${r.inputLot}`,
    },
    { id: 'issued', header: 'Issued', numeric: true, group: 'mat', cell: (r) => q(r.issued, r.consumedUom) },
    {
      id: 'consumed',
      header: 'Consumed',
      numeric: true,
      critical: true,
      group: 'mat',
      cell: (r) => q(r.consumed, r.consumedUom),
      sub: (r) => (r.method === 'UNMEASURED_BY_DIFFERENCE' ? 'unmeasured' : 'measured'),
    },
    {
      id: 'remaining',
      header: 'Remaining · returned',
      numeric: true,
      group: 'mat',
      cell: (r) => (
        <>
          {q(r.remaining, r.consumedUom)} · {q(r.returned, r.consumedUom)}
        </>
      ),
    },
    {
      id: 'theoretical',
      header: 'Theoretical',
      numeric: true,
      group: 'out',
      cell: (r) => (r.theoretical !== null ? q(r.theoretical, 'pcs') : '—'),
      sub: (r) => r.cpVersion ?? 'no profile',
    },
    {
      id: 'good',
      header: 'Good · NG',
      numeric: true,
      critical: true,
      group: 'out',
      cell: (r) => <>{q(r.good, 'pcs')}</>,
      sub: (r) => `NG ${r.ngRework} rework · ${r.ngRejected} rejected`,
    },
    {
      id: 'gyv',
      header: 'GYV',
      numeric: true,
      critical: true,
      sortable: true,
      group: 'ind',
      cell: (r) => <IndicatorCell value={r.gyv} pct={r.gyvPct} uom="pcs" status={r.gyvStatus} />,
    },
    { id: 'pvs', header: 'PvS (KPI)', numeric: true, group: 'ind', cell: (r) => <IndicatorCell value={r.pvs} pct={r.pvsPct} uom="pcs" status={r.pvsStatus} /> },
    {
      id: 'umv',
      header: 'UMV',
      numeric: true,
      critical: true,
      group: 'ind',
      cell: (r) => <IndicatorCell value={r.umvKg} pct={r.umvPct} uom="kg" status={r.umvStatus} />,
    },
    {
      id: 'case',
      header: 'Case',
      critical: true,
      cell: (r) =>
        r.case ? (
          <span className="inline-flex items-center gap-1.5">
            {r.case.id}
            <Badge tone={r.breach ? 'warning' : 'info'}>{r.case.status.toLowerCase()}</Badge>
          </span>
        ) : r.breach ? (
          <Badge tone="warning">Breach</Badge>
        ) : (
          <span className="text-ink-2">—</span>
        ),
    },
  ],
  detail: (r) => ({
    title: `Run ${r.run}`,
    subtitle: `${r.wo} · ${partLabel(r.part)} · input lot ${r.inputLot}`,
    sections: [
      {
        title: `Material reconciliation (${r.consumedUom})`,
        rows: [
          ['Issued', fmtQty(r.issued, r.consumedUom)],
          ['Actual consumed', fmtQty(r.consumed, r.consumedUom)],
          ['Remaining at production', fmtQty(r.remaining, r.consumedUom)],
          ['Returned', fmtQty(r.returned, r.consumedUom)],
          ['Measurement', <MethodLabel key="m" method={r.method} />],
        ],
      },
      {
        title: 'Outputs',
        rows: [
          [
            'Theoretical (T)',
            r.theoretical !== null ? `${fmtQty(r.theoretical, 'pcs')} pcs · ${r.cpVersion}` : `Not calculable — missing: ${r.missingInputs.join(', ')}`,
          ],
          ['Good (G)', `${fmtQty(r.good, 'pcs')} pcs`],
          ['NG reworkable (NR) · NG rejected (NJ)', `${r.ngRework} · ${r.ngRejected} pcs`],
          ['Other documented (O)', `${r.other} pcs`],
          ['Weighed process scrap (S)', r.scrapKg !== null ? `${fmtQty(r.scrapKg, 'kg')} kg` : 'not weighed'],
        ],
      },
      {
        title: 'Indicators',
        rows: [
          ['Gross Yield Variance', <IndicatorCell key="g" value={r.gyv} pct={r.gyvPct} uom="pcs" status={r.gyvStatus} />],
          ['Performance vs Standard', <IndicatorCell key="p" value={r.pvs} pct={r.pvsPct} uom="pcs" status={r.pvsStatus} />],
          ['Unexplained Material Variance', <IndicatorCell key="u" value={r.umvKg} pct={r.umvPct} uom="kg" status={r.umvStatus} />],
          ['Tolerance rule', r.toleranceRule],
          ['Breach', r.breach ? 'Yes — investigation case' : 'No'],
          ['Confirmed actual material loss', r.confirmedLossKg !== null ? `${fmtQty(r.confirmedLossKg, 'kg')} kg` : 'None (only from a resolved case)'],
          [
            'Confirmed physical stock discrepancy',
            r.confirmedDiscrepancyKg !== null ? `${fmtQty(r.confirmedDiscrepancyKg, 'kg')} kg` : 'None (only from an approved count)',
          ],
        ],
      },
    ],
    calculation: {
      title: 'ADR-0005',
      body: (
        <>
          GYV = T − (G + NR + NJ + O); PvS = G − floor(T × SY); UMV = C<sub>kg</sub> − ((G + NR + NJ + O) × NW + S + D). FINAL only when consumption is
          measured, reporting is closed and inputs are validated. A variance can lead to an investigation outcome; it is never itself “loss”.
        </>
      ),
    },
    sources: [
      { label: 'Production posting', ref: r.run, href: HREF.postings },
      { label: 'Measurements & evidence', ref: r.run, href: HREF.measurements },
      ...(r.case ? [{ label: 'Investigation case', ref: r.case.id, href: '/ppic/variance-investigation' }] : []),
      { label: 'Genealogy', ref: r.inputLot, href: HREF.backwardTrace },
    ],
  }),
  footnote: 'Confirmed Actual Material Loss and Physical Stock Discrepancy appear only after a resolved case or an approved stock count (D-024).',
  Panel: VarianceSummary,
};

export function WoProgressScreen() {
  return <CtScreen config={WO} />;
}
export function DailyProductionScreen() {
  return <CtScreen config={DAILY} />;
}
export function PendingQualityScreen() {
  return <CtScreen config={QC} />;
}
export function DailyFgScreen() {
  return <CtScreen config={FGR} />;
}
export function FgAvailabilityScreen() {
  return <CtScreen config={FGA} />;
}
export function MaterialVarianceScreen() {
  return <CtScreen config={MV} />;
}

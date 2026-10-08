'use client';

import * as React from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowUpRight } from 'lucide-react';
import {
  ActiveFilters,
  DataTable,
  EnterprisePage,
  FilterBar,
  FilterToggle,
  useFilterPanel,
  useListUrlState,
  type EoraColumn,
  type ListUrlConfig,
} from '@eora/features/patterns';
import { useShellSession } from '@eora/features/shell';
import { Badge, Button, ErrorState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { isSourceError } from '@eora/features/data';
import type { ExceptionRow } from '../contracts';
import { FreshnessBadge } from '../components/badges';
import { DueVsSupplyChart } from '../components/due-vs-supply';
import { MaterialFlowStrip } from '../components/flow-strip';
import { KpiCard } from '../components/kpi-card';
import { fmtDateTime, fmtDay, fmtQty } from '../components/format';
import { useCtSources } from '../components/use-ct-sources';
import { CUSTOMER_FIELD, PART_FIELD, PLANT_FIELD } from './common';

const FIELDS = [PLANT_FIELD, CUSTOMER_FIELD, PART_FIELD];
const CFG: ListUrlConfig = { filterKeys: FIELDS.map((f) => f.key), defaultSort: [], defaultPageSize: 20, lockedFilters: { plant: 'PA' } };

const TYPE: Record<ExceptionRow['type'], { label: string; tone: 'danger' | 'warning' | 'info' | 'neutral' }> = {
  DELIVERY_AT_RISK: { label: 'Delivery at risk', tone: 'danger' },
  MATERIAL_SHORTAGE: { label: 'Material shortage', tone: 'danger' },
  CONVERSION_NOT_CALCULABLE: { label: 'Conversion not calculable', tone: 'warning' },
  REVISION_MAPPING_AMBIGUOUS: { label: 'Revision mapping', tone: 'warning' },
  FORECAST_MATCH_UNCERTAIN: { label: 'Forecast match uncertain', tone: 'warning' },
  BLANKET_EXCEEDED: { label: 'Blanket exceeded', tone: 'warning' },
  PENDING_QC_AGING: { label: 'Pending QC aging', tone: 'warning' },
  VARIANCE_CASE: { label: 'Variance case', tone: 'info' },
  MATERIAL_IDLE: { label: 'Material idle', tone: 'neutral' },
};

const SCREENS = [
  ['CT-9', 'Demand & Fulfillment', '/ppic/demand-fulfillment', 'Customer PO, SO lines, schedules, weekly netting view, blanket balances'],
  ['CT-5', 'Daily Delivery & Outstanding', '/ppic/daily-delivery', 'Daily due, shipped, outstanding, partial shipments'],
  ['CT-8', 'Shortage & Delivery Risk', '/ppic/shortage', 'Projection per due date with reasons'],
  ['CT-1', 'Raw Material Availability', '/ppic/rm-availability', 'Coil kg / sheet stock, allocated, available'],
  ['CT-1', 'Coil / Sheet Conversion', '/ppic/conversion', 'Estimated pieces with confidence (not stock)'],
  ['CT-2', 'Material at Production', '/ppic/material-at-production', 'Remaining material, remnants, idle time'],
  ['CT-10', 'Work Order Progress', '/ppic/wo-progress', 'Good vs planned, NG, scrap, consumption'],
  ['CT-10', 'Daily Production Results', '/ppic/daily-results', 'Per production date and shift'],
  ['CT-3', 'Pending Quality', '/ppic/pending-quality', 'Pending QC, hold, rework, rejected, aging'],
  ['CT-4', 'Daily Finished Goods', '/ppic/daily-fg', 'Good → QC released → FG received'],
  ['CT-7', 'Finished Goods Availability', '/ppic/fg-availability', 'Available, allocated, blocked, incoming from QC'],
  ['CT-6', 'Material Variance', '/ppic/material-variance', 'GYV / PvS / UMV with finality and cases'],
] as const;

export function ControlTowerOverview() {
  const router = useRouter();
  const { sources, mode } = useCtSources();
  const { workspace } = useShellSession();
  const list = useListUrlState(CFG);
  const panel = useFilterPanel();
  const filters = {
    customer: typeof list.query.filters.customer === 'string' ? list.query.filters.customer : undefined,
    part: typeof list.query.filters.part === 'string' ? list.query.filters.part : undefined,
  };
  const ov = useQuery({
    queryKey: [sources.overview.resource, mode, filters],
    queryFn: ({ signal }) => sources.overview.get(filters, signal),
    placeholderData: keepPreviousData,
  });
  const data = ov.data;
  const stale = !!data && ov.dataUpdatedAt - Date.parse(data.meta.asOf) > 10 * 60_000;

  const exceptionCols: EoraColumn<ExceptionRow>[] = [
    { id: 'rank', header: '#', numeric: true, cell: (r) => r.impactRank },
    { id: 'type', header: 'Exception', lead: true, critical: true, cell: (r) => <Badge tone={TYPE[r.type].tone}>{TYPE[r.type].label}</Badge> },
    { id: 'title', header: 'What', textCol: true, cell: (r) => r.title, title: (r) => `${r.title} — ${r.detail}`, sub: (r) => r.detail },
    { id: 'due', header: 'Due', cell: (r) => (r.dueDate ? fmtDay(r.dueDate) : '—') },
    { id: 'qty', header: 'Quantity', numeric: true, cell: (r) => (r.qty !== null && r.uom ? `${fmtQty(r.qty, r.uom)} ${r.uom}` : '—') },
  ];

  const series = React.useMemo(() => {
    const s = data?.dueVsSupply ?? [];
    const atRisk = (x: (typeof s)[number]) => x.points.some((p) => p.due > 0 && p.projected < p.due);
    return [...s].sort((a, b) => Number(atRisk(b)) - Number(atRisk(a)) || a.part.localeCompare(b.part));
  }, [data]);

  const forbidden = ov.isError && isSourceError(ov.error) && ov.error.kind === 'forbidden';

  return (
    <EnterprisePage
      title="PPIC Control Tower"
      mock
      description="What needs action now to protect the next deliveries. Read-only workspace: every figure drills to its source list; nothing here posts inventory."
      meta={
        <>
          <span>
            <span className="font-medium text-ink">CT-0</span> · Overview
          </span>
          <span>Scope: {workspace.plant}</span>
          <span>Business date {data ? fmtDay(data.businessDate) : '…'} 2026 · Asia/Jakarta</span>
          {data ? <FreshnessBadge asOf={data.meta.asOf} receivedAt={ov.dataUpdatedAt} /> : null}
        </>
      }
    >
      <section aria-label="Overview filters" className="mb-5 rounded-lg border border-line bg-surface shadow-[var(--shadow-card)]">
        <div className="flex flex-wrap items-center gap-3 px-5 py-3 max-md:px-4">
          <FilterToggle panel={panel} count={(filters.customer ? 1 : 0) + (filters.part ? 1 : 0)} controlsId="ct0-filters" />
          <span className="text-[13px] text-ink-2">Filters scope every card, the flow strip, exceptions and the chart below.</span>
        </div>
        <FilterBar id="ct0-filters" fields={FIELDS} values={list.query.filters} onApply={list.applyFilters} panel={panel} />
        <ActiveFilters fields={FIELDS} values={list.query.filters} onRemove={list.removeFilter} onClearAll={list.clearFilters} />
      </section>

      {stale ? (
        <InlineAlert
          tone="warning"
          title="Read model is stale"
          className="mb-5"
          action={
            <Button size="sm" onClick={() => ov.refetch()}>
              Refresh
            </Button>
          }
        >
          Last update {fmtDateTime(data!.meta.asOf)}. Figures may lag the ledger.
        </InlineAlert>
      ) : null}

      {forbidden ? (
        <PermissionDeniedState
          title="You do not have access to the Control Tower"
          description="Requires planning.control_tower.view. No figures were loaded."
        />
      ) : ov.isError ? (
        <ErrorState
          title="Control Tower overview could not be loaded"
          description={
            <>
              {ov.error instanceof Error ? ov.error.message : 'The server did not respond.'}{' '}
              {isSourceError(ov.error) ? `Reference ${ov.error.reference}.` : null}
            </>
          }
          action={
            <Button size="sm" variant="primary" onClick={() => ov.refetch()}>
              Try again
            </Button>
          }
        />
      ) : !data ? (
        <LoadingState title="Loading Control Tower overview" />
      ) : (
        <div className={`flex flex-col gap-5 ${ov.isFetching ? 'opacity-70' : ''}`} aria-busy={ov.isFetching || undefined}>
          <section aria-label="Decision KPIs" className="grid grid-cols-4 gap-4 max-xxl:grid-cols-2 max-md:grid-cols-1">
            {data.kpis.map((k) => (
              <KpiCard key={k.id} kpi={k} asOf={fmtDateTime(data.meta.asOf)} />
            ))}
          </section>

          <MaterialFlowStrip material={data.flow.material} output={data.flow.output} />

          <div className="grid gap-5 3xl:grid-cols-[3fr_2fr]">
            <section aria-labelledby="ct0-ex" className="min-w-0 rounded-lg border border-line bg-surface shadow-[var(--shadow-card)]">
              <div className="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-4 pb-3 max-md:px-4">
                <h2 id="ct0-ex" className="m-0 text-[15px] font-semibold">
                  Exception queue
                </h2>
                <span className="text-xs text-ink-2">Top {data.exceptions.length} by due-date proximity, then impact type (doc 19)</span>
              </div>
              <DataTable
                label="Exception queue"
                columns={exceptionCols}
                rows={data.exceptions}
                getRowId={(r) => r.id}
                rowLabel={(r) => r.title}
                status="ready"
                empty={<p className="m-0 px-5 py-8 text-center text-[13.5px] text-ink-2">Nothing needs action in this scope.</p>}
                sort={[]}
                onSortChange={() => {}}
                density="compact"
                rowActions={{ primary: (r) => ({ id: 'open', label: 'Open', onSelect: () => router.push(r.href) }) }}
              />
            </section>
            <section aria-labelledby="ct0-chart" className="min-w-0 rounded-lg border border-line bg-surface p-5 shadow-[var(--shadow-card)] max-md:p-4">
              <h2 id="ct0-chart" className="m-0 mb-3 text-[15px] font-semibold">
                Will FG supply cover customer dues each day?
              </h2>
              <DueVsSupplyChart series={series} />
            </section>
          </div>

          <section aria-labelledby="ct0-screens" className="rounded-lg border border-line bg-surface p-5 shadow-[var(--shadow-card)] max-md:p-4">
            <h2 id="ct0-screens" className="m-0 mb-1 text-[15px] font-semibold">
              Detail screens
            </h2>
            <p className="m-0 mb-3 text-xs text-ink-2">Detail tables load on their own screens (server-paged) instead of all transactions on this dashboard.</p>
            <ul className="m-0 grid list-none grid-cols-3 gap-2 p-0 max-xl:grid-cols-2 max-md:grid-cols-1">
              {SCREENS.map(([code, label, href, note]) => (
                <li key={href}>
                  <Link
                    href={href}
                    className="flex h-full items-start justify-between gap-3 rounded-md border border-line px-3 py-2.5 hover:border-line-strong hover:bg-subtle focus-visible:outline-2 focus-visible:outline-ring"
                  >
                    <span className="min-w-0">
                      <span className="block text-[13.5px] font-medium">
                        <span className="mr-1.5 text-xs text-ink-2">{code}</span>
                        {label}
                      </span>
                      <span className="block text-xs text-ink-2">{note}</span>
                    </span>
                    <ArrowUpRight aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-ink-2" />
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        </div>
      )}
    </EnterprisePage>
  );
}

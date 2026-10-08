'use client';

import * as React from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Eye } from 'lucide-react';
import {
  activeFilterCount,
  useDensityPreference,
  usePageSizePreference,
  type FilterValues,
  type ListQuery,
  type ListSource,
  type SortRule,
} from '@eora/features/data';
import {
  ActiveFilters,
  ColumnSettings,
  DataTable,
  DensityToggle,
  EnterprisePage,
  ExportMenu,
  FilterBar,
  FilterToggle,
  ListSearch,
  ListWorkspace,
  Pagination,
  useFilterPanel,
  useListUrlState,
  type ColumnGroup,
  type ColumnVisibility,
  type EoraColumn,
  type FilterField,
  type ListUrlConfig,
} from '@eora/features/patterns';
import { useShellSession } from '@eora/features/shell';
import { Button, EmptyState, InlineAlert } from '@eora/ui';
import type { ControlTowerSources } from '../contracts';
import { BUSINESS_DATE } from '../mock/world';
import { mockExportSource } from '@/lib/samples/import-export.mock';
import { CtDetailDrawer, type CtDetail } from './detail-drawer';
import { FreshnessBadge } from './badges';
import { fmtDay } from './format';
import { useCtSources } from './use-ct-sources';

export type CtRow = { id: string };

export type CtScreenConfig<T extends CtRow> = {
  code: string;
  title: string;
  description: string;
  /** QueryDefinition resource of this screen. */
  source: (s: ControlTowerSources) => ListSource<T>;
  /** Stable id for element ids and query keys. */
  id: string;
  itemLabel: string;
  fields: FilterField[];
  views: { value: string; label: string }[];
  viewNote?: string;
  defaultSort: SortRule[];
  columns: EoraColumn<T>[];
  groups?: ColumnGroup[];
  rowLabel: (row: T) => string;
  detail: (row: T) => CtDetail;
  footnote?: string;
  searchPlaceholder?: string;
  /** Read-only panel above the list that shares the same filter scope (e.g. weekly demand view). */
  Panel?: React.ComponentType<{ query: ListQuery; sources: ControlTowerSources; mode: string }>;
};

export const PLANT_FIELD: FilterField = {
  key: 'plant',
  type: 'select',
  source: 'static',
  label: 'Plant',
  locked: true,
  options: [{ value: 'PA', label: 'Plant A · Cikarang' }],
};

/**
 * Control Tower list screen — composition of the Phase 2 shared patterns only (EnterprisePage, ListWorkspace, StatusTabs,
 * FilterBar, DataTable, Pagination, ExportMenu, Drawer). Screens differ by configuration, never by markup.
 */
export function CtScreen<T extends CtRow>({ config }: { config: CtScreenConfig<T> }) {
  const { sources, mode } = useCtSources();
  const source = config.source(sources);
  const { workspace } = useShellSession();
  const [density, setDensity] = useDensityPreference();
  const [prefSize, setPrefSize] = usePageSizePreference();
  const cfg = React.useMemo<ListUrlConfig>(
    () => ({
      filterKeys: config.fields.map((f) => f.key),
      defaultSort: config.defaultSort,
      defaultView: 'all',
      defaultPageSize: Number(prefSize),
      lockedFilters: { plant: 'PA' },
    }),
    [config.fields, config.defaultSort, prefSize],
  );
  const list = useListUrlState(cfg);
  const { query } = list;
  const panel = useFilterPanel();
  const [visibility, setVisibility] = React.useState<ColumnVisibility>({});
  const [detail, setDetail] = React.useState<CtDetail | null>(null);

  const rows = useQuery({ queryKey: [source.resource, mode, query], queryFn: ({ signal }) => source.list(query, signal), placeholderData: keepPreviousData });
  const counts = useQuery({
    queryKey: [source.resource, 'counts', mode, query.filters, query.search],
    queryFn: ({ signal }) => source.viewCounts!({ filters: query.filters, search: query.search }, signal),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  });
  const meta = rows.data?.meta;
  const status = rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready';
  const filtersApplied = activeFilterCount(query.filters, ['plant']);
  const hasFilters = filtersApplied > 0 || !!query.search || query.view !== 'all';
  const stale = !!meta && rows.dataUpdatedAt - Date.parse(meta.asOf) > 10 * 60_000;

  return (
    <EnterprisePage
      title={
        <>
          <span className="sr-only">{config.code} </span>
          {config.title}
        </>
      }
      mock
      description={config.description}
      meta={
        <>
          <span>
            <span className="font-medium text-ink">{config.code}</span> · PPIC Control Tower
          </span>
          <span>Scope: {workspace.plant}</span>
          <span>Business date {fmtDay(BUSINESS_DATE)} 2026 · Asia/Jakarta</span>
          {meta ? <FreshnessBadge asOf={meta.asOf} receivedAt={rows.dataUpdatedAt} /> : null}
        </>
      }
    >
      {stale ? (
        <InlineAlert
          tone="warning"
          title="Read model is stale"
          className="mb-4"
          action={
            <Button size="sm" onClick={() => rows.refetch()}>
              Refresh
            </Button>
          }
        >
          Figures may lag the ledger. Single-entity details are read live when you open them.
        </InlineAlert>
      ) : null}
      {config.Panel ? <config.Panel query={query} sources={sources} mode={mode} /> : null}
      <ListWorkspace
        label={config.title}
        statusTabs={{
          label: 'View by',
          value: query.view,
          onValueChange: list.setView,
          items: config.views.map((v) => ({ ...v, count: counts.data?.[v.value] })),
          note: config.viewNote,
        }}
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch
                label={`Search ${config.itemLabel}`}
                placeholder={config.searchPlaceholder ?? 'Search…'}
                value={query.search}
                onChange={list.setSearch}
              />
              <FilterToggle panel={panel} count={filtersApplied} controlsId={`${config.id}-filters`} />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{meta ? meta.total.toLocaleString('en-US') : '…'}</b> {config.itemLabel}
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <ExportMenu resource={config.title} source={mockExportSource} filtersApplied={filtersApplied} />
              <span aria-hidden="true" className="h-6 w-px bg-line max-md:hidden" />
              <DensityToggle value={density} onChange={setDensity} />
              <ColumnSettings columns={config.columns} value={visibility} onChange={setVisibility} />
            </div>
          </>
        }
        filterBar={
          <FilterBar
            id={`${config.id}-filters`}
            fields={config.fields}
            values={query.filters}
            onApply={(v: FilterValues) => list.applyFilters(v)}
            panel={panel}
          />
        }
        activeFilters={
          <ActiveFilters
            fields={config.fields}
            values={query.filters}
            search={query.search}
            onRemove={list.removeFilter}
            onClearSearch={() => list.setSearch('')}
            onClearAll={list.clearFilters}
          />
        }
        footnote={config.footnote}
        pagination={
          status === 'ready' && meta && meta.total > 0 ? (
            <Pagination
              page={query.page}
              pageSize={query.pageSize}
              total={meta.total}
              itemLabel={config.itemLabel}
              onPageChange={list.setPage}
              onPageSizeChange={(s) => {
                setPrefSize(String(s));
                list.setPageSize(s);
              }}
            />
          ) : null
        }
      >
        <DataTable
          label={config.title}
          columns={config.columns}
          groups={config.groups}
          rows={rows.data?.rows ?? (EMPTY as T[])}
          getRowId={(r) => r.id}
          rowLabel={config.rowLabel}
          status={status}
          error={rows.error}
          onRetry={() => rows.refetch()}
          isFetching={rows.isFetching && !rows.isPending}
          sort={query.sort}
          onSortChange={list.setSort}
          density={density}
          columnVisibility={visibility}
          onColumnVisibilityChange={setVisibility}
          skeletonRows={Math.min(query.pageSize, 8)}
          empty={
            hasFilters ? (
              <EmptyState
                title={`No ${config.itemLabel} match these filters`}
                description="Nothing in this scope matches the current view, search and filters."
                action={
                  <Button size="sm" onClick={list.clearFilters}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState title={`No ${config.itemLabel} yet`} description="Records appear here once the source transactions are posted." />
            )
          }
          rowActions={{ primary: (r) => ({ id: 'view', label: 'View', icon: Eye, onSelect: () => setDetail(config.detail(r)) }) }}
        />
      </ListWorkspace>
      <CtDetailDrawer detail={detail} onClose={() => setDetail(null)} />
    </EnterprisePage>
  );
}

const EMPTY: unknown[] = [];

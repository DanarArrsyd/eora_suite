'use client';

import * as React from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus } from 'lucide-react';
import { useMe } from '@eora/features/auth';
import { activeFilterCount, useDensityPreference, usePageSizePreference, type FilterValues } from '@eora/features/data';
import {
  ActiveFilters,
  ColumnSettings,
  DataTable,
  DensityToggle,
  EnterprisePage,
  FilterBar,
  FilterToggle,
  ListSearch,
  ListWorkspace,
  Pagination,
  useFilterPanel,
  useListUrlState,
  type ColumnVisibility,
  type ListUrlConfig,
} from '@eora/features/patterns';
import { Button, EmptyState, InlineAlert, LoadingState, PermissionDeniedState, Tooltip } from '@eora/ui';
import { MasterEditor } from './editor';
import { querySource } from './source';
import type { MasterResource, MasterRow } from './types';

/** doc 18 master-data table: lists need `master.org.view`, changes `master.org.create` (the server decides scope). */
export const ORG_PERMISSION = { view: 'master.org.view', create: 'master.org.create' } as const;
const CREATE_REASON = 'Your roles do not allow changes to organisation master data.';
const EMPTY: never[] = [];

/**
 * Master Data list screen (doc 29 §1, page template §1): one composition of the shared patterns for every resource —
 * breadcrumb, title, primary action, search + filters, table, pagination, create/edit drawer. Rows come live from
 * GET /api/v1/query/{resource}; filtering, sorting and paging are server-side and kept in the URL.
 */
export function MasterScreen<T extends MasterRow>({ config }: { config: MasterResource<T> }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes(ORG_PERMISSION.view);
  const canCreate = permissions.includes(ORG_PERMISSION.create);
  const source = React.useMemo(() => querySource<T>(config.resource), [config.resource]);
  const client = useQueryClient();
  const [density, setDensity] = useDensityPreference();
  const [prefSize, setPrefSize] = usePageSizePreference();
  const urlConfig = React.useMemo<ListUrlConfig>(
    () => ({ filterKeys: config.filters.map((f) => f.key), defaultSort: config.defaultSort, defaultView: 'all', defaultPageSize: Number(prefSize) }),
    [config.filters, config.defaultSort, prefSize],
  );
  const list = useListUrlState(urlConfig);
  const { query } = list;
  const panel = useFilterPanel();
  const [visibility, setVisibility] = React.useState<ColumnVisibility>({});
  const [editor, setEditor] = React.useState<{ open: boolean; row: T | null }>({ open: false, row: null });
  const [notice, setNotice] = React.useState<string | null>(null);

  const rows = useQuery({
    queryKey: ['master', config.resource, query],
    queryFn: ({ signal }) => source.list(query, signal),
    placeholderData: keepPreviousData,
    enabled: canView,
  });

  if (me.isPending) return <LoadingState title={`Loading ${config.title}`} />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title={`${config.title} is not available for your roles`}
          description="Ask a tenant administrator for a role that includes organisation master data."
        />
      </div>
    );
  }

  const meta = rows.data?.meta;
  const status = rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready';
  const filtersApplied = activeFilterCount(query.filters);
  const hasFilters = filtersApplied > 0 || !!query.search;
  const createLabel = `New ${config.singular}`;

  const createButton = canCreate ? (
    <Button variant="primary" onClick={() => setEditor({ open: true, row: null })}>
      <Plus aria-hidden="true" className="size-4" />
      {createLabel}
    </Button>
  ) : (
    <Tooltip content={CREATE_REASON}>
      <Button variant="primary" aria-disabled="true" aria-label={`${createLabel}. ${CREATE_REASON}`} onClick={(e) => e.preventDefault()}>
        <Plus aria-hidden="true" className="size-4" />
        {createLabel}
      </Button>
    </Tooltip>
  );

  return (
    <EnterprisePage title={config.title} description={config.description} actions={createButton}>
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
        >
          The list shows the saved record.
        </InlineAlert>
      ) : null}
      <ListWorkspace
        label={config.title}
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch label={`Search ${config.itemLabel}`} placeholder={config.searchPlaceholder} value={query.search} onChange={list.setSearch} />
              {config.filters.length ? <FilterToggle panel={panel} count={filtersApplied} controlsId={`${config.id}-filters`} /> : null}
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{meta ? meta.total.toLocaleString('en-US') : '…'}</b>{' '}
                {meta?.total === 1 ? config.singular : config.itemLabel}
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <DensityToggle value={density} onChange={setDensity} />
              <ColumnSettings columns={config.columns} value={visibility} onChange={setVisibility} />
            </div>
          </>
        }
        filterBar={
          config.filters.length ? (
            <FilterBar
              id={`${config.id}-filters`}
              fields={config.filters}
              values={query.filters}
              onApply={(v: FilterValues) => list.applyFilters(v)}
              panel={panel}
            />
          ) : undefined
        }
        activeFilters={
          <ActiveFilters
            fields={config.filters}
            values={query.filters}
            search={query.search}
            onRemove={list.removeFilter}
            onClearSearch={() => list.setSearch('')}
            onClearAll={list.clearFilters}
          />
        }
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
                description="Nothing in your scope matches the current search and filters."
                action={
                  <Button size="sm" onClick={list.clearFilters}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState
                title={`No ${config.itemLabel} yet`}
                description={canCreate ? `Create the first ${config.singular} with ${createLabel}.` : `Nothing has been set up in your scope yet.`}
              />
            )
          }
          rowActions={{
            primary: (r) => ({
              id: 'open',
              label: config.readOnly?.(r) || !canCreate ? 'View' : 'Edit',
              icon: Pencil,
              onSelect: () => setEditor({ open: true, row: r }),
            }),
          }}
        />
      </ListWorkspace>
      <MasterEditor
        config={config}
        row={editor.row}
        canEdit={canCreate}
        open={editor.open}
        onOpenChange={(open) => setEditor((e) => ({ ...e, open }))}
        onSaved={(message) => {
          setEditor({ open: false, row: null });
          setNotice(message);
          void client.invalidateQueries({ queryKey: ['master'] });
        }}
      />
    </EnterprisePage>
  );
}

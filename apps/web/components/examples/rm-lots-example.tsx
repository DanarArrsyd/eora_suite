'use client';

import * as React from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowRightLeft, BookOpen, Eye, FlaskConical, GitBranch, Printer, SlidersHorizontal } from 'lucide-react';
import { activeFilterCount, useDensityPreference, usePageSizePreference, type FilterValues } from '@eora/features/data';
import {
  ActiveFilters,
  BulkBar,
  ColumnSettings,
  ConfirmDialog,
  DataTable,
  DensityToggle,
  DomainStatusBadge,
  EnterprisePage,
  ExportMenu,
  FilterBar,
  FilterToggle,
  ImportWizard,
  ListSearch,
  ListWorkspace,
  Pagination,
  SavedViewsMenu,
  useFeedback,
  useFilterPanel,
  useListUrlState,
  type ColumnGroup,
  type ColumnVisibility,
  type EoraColumn,
  type ExportSource,
  type FilterField,
  type ImportSource,
  type ListUrlConfig,
  type RowSelection,
  type SavedView,
} from '@eora/features/patterns';
import { useShellSession } from '@eora/features/shell';
import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
  Drawer,
  DrawerContent,
  EmptyState,
  InlineAlert,
} from '@eora/ui';
import { LocationCode, QuantityWithUom } from '@/components/quantity';
import { SAMPLE_VIEWS, createRmLotSource, loadPlants, loadWarehouses, type RmLot, type SimulateMode } from '@/lib/samples/rm-lots.mock';
import { mockExportSource, mockImportSource } from '@/lib/samples/import-export.mock';

const FIELDS: FilterField[] = [
  { key: 'plant', type: 'entity', entity: 'plant', label: 'Plant', locked: true, loadOptions: loadPlants },
  { key: 'warehouse', type: 'entity', entity: 'warehouse', label: 'Warehouse', dependsOn: ['plant'], loadOptions: loadWarehouses },
  {
    key: 'materialType',
    type: 'select',
    source: 'static',
    label: 'Material type',
    options: [
      { value: 'coil', label: 'Coil' },
      { value: 'sheet', label: 'Sheet' },
    ],
  },
  {
    key: 'grade',
    type: 'multiselect',
    source: 'static',
    label: 'Grade',
    options: [
      { value: 'SPCC', label: 'SPCC' },
      { value: 'SPHC', label: 'SPHC' },
      { value: 'SGCC', label: 'SGCC' },
    ],
  },
  { key: 'received', type: 'daterange', label: 'Receipt date' },
];

/** Tabs are presets over approved dimensions only (EORA-PAGE-TEMPLATES §2). */
const VIEWS = [
  { value: 'all', label: 'All' },
  { value: 'unrestricted', label: 'Unrestricted' },
  { value: 'allocated', label: 'Allocated' },
  { value: 'at-production', label: 'At production' },
  { value: 'hold', label: 'Hold' },
  { value: 'blocked', label: 'Blocked' },
];

const GROUPS: ColumnGroup[] = [{ id: 'wh', label: 'Warehouse stock (base UOM)', title: 'Available + Allocated + Hold + Blocked = on-hand in warehouse' }];

const COLUMNS: EoraColumn<RmLot>[] = [
  { id: 'lot', header: 'Lot', lead: true, sortable: true, cell: (l) => <span className="font-medium">{l.lot}</span>, sub: (l) => l.item },
  { id: 'material', header: 'Material', textCol: true, cell: (l) => l.material, title: (l) => l.material, sub: (l) => `Heat ${l.heat} · ${l.certificate}` },
  {
    id: 'available',
    header: 'Available',
    numeric: true,
    critical: true,
    sortable: true,
    group: 'wh',
    cell: (l) => <QuantityWithUom value={l.available} uom={l.uom} />,
    emptyOnPhone: (l) => !l.available,
  },
  {
    id: 'allocated',
    header: 'Allocated',
    numeric: true,
    critical: true,
    group: 'wh',
    cell: (l) => <QuantityWithUom value={l.allocated} uom={l.uom} />,
    emptyOnPhone: (l) => !l.allocated,
  },
  {
    id: 'hold',
    header: 'Hold',
    numeric: true,
    critical: true,
    group: 'wh',
    cell: (l) => <QuantityWithUom value={l.hold} uom={l.uom} />,
    emptyOnPhone: (l) => !l.hold,
  },
  {
    id: 'blocked',
    header: 'Blocked',
    numeric: true,
    critical: true,
    group: 'wh',
    cell: (l) => <QuantityWithUom value={l.blocked} uom={l.uom} />,
    emptyOnPhone: (l) => !l.blocked,
  },
  {
    id: 'atProduction',
    header: 'At production',
    numeric: true,
    critical: true,
    sortable: true,
    cell: (l) => <QuantityWithUom value={l.atProduction} uom={l.uom} />,
    emptyOnPhone: (l) => !l.atProduction,
  },
  {
    id: 'status',
    header: 'Stock status',
    critical: true,
    cell: (l) => <DomainStatusBadge domain="stock" status={l.blocked ? 'BLOCKED' : l.hold ? 'HOLD' : 'UNRESTRICTED'} />,
  },
  { id: 'location', header: 'Location', cell: (l) => <LocationCode code={l.location} /> },
  { id: 'receivedAt', header: 'Received', sortable: true, cell: (l) => formatDate(l.receivedAt) },
];

function formatDate(iso: string) {
  return new Date(`${iso}T00:00:00Z`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' });
}
const asOfFmt = new Intl.DateTimeFormat('en-GB', {
  day: '2-digit',
  month: 'short',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Asia/Jakarta',
});

const SIMULATIONS: { value: SimulateMode; label: string }[] = [
  { value: 'none', label: 'Normal response' },
  { value: 'slow', label: 'Slow (2.5 s)' },
  { value: 'empty', label: 'No records (first run)' },
  { value: 'error', label: 'Server error' },
  { value: 'forbidden', label: 'No permission' },
  { value: 'stale', label: 'Stale read model' },
];

export function RmLotsExample({ simulate }: { simulate: SimulateMode }) {
  const notify = useFeedback();
  const { role, workspace } = useShellSession();
  const [density, setDensity] = useDensityPreference();
  const [prefSize, setPrefSize] = usePageSizePreference();
  const cfg = React.useMemo<ListUrlConfig>(
    () => ({
      filterKeys: FIELDS.map((f) => f.key),
      defaultSort: [{ id: 'lot', desc: false }],
      defaultPageSize: Number(prefSize),
      lockedFilters: { plant: 'PA' },
    }),
    [prefSize],
  );
  const list = useListUrlState(cfg);
  const { query } = list;
  const source = React.useMemo(() => createRmLotSource(simulate), [simulate]);
  const panel = useFilterPanel();

  const rows = useQuery({ queryKey: ['rm-lots', simulate, query], queryFn: ({ signal }) => source.list(query, signal), placeholderData: keepPreviousData });
  const counts = useQuery({
    queryKey: ['rm-lots-counts', simulate, query.filters, query.search],
    queryFn: ({ signal }) => source.viewCounts!({ filters: query.filters, search: query.search }, signal),
    placeholderData: keepPreviousData,
  });

  const qKey = JSON.stringify(query);
  const [sel, setSel] = React.useState<{ key: string; value: RowSelection }>({ key: qKey, value: {} });
  const selection = sel.key === qKey ? sel.value : {};
  const selectedIds = Object.keys(selection);
  const [visibility, setVisibility] = React.useState<ColumnVisibility>({});
  const [views, setViews] = React.useState<SavedView[]>(SAMPLE_VIEWS);
  const [detail, setDetail] = React.useState<RmLot | null>(null);
  const [adjust, setAdjust] = React.useState<RmLot | null>(null);

  const meta = rows.data?.meta;
  // Staleness = age of the read model when the response arrived (dataUpdatedAt), not a render-time clock.
  const stale = !!meta && rows.dataUpdatedAt - new Date(meta.asOf).getTime() > 15 * 60_000;
  const filtersApplied = activeFilterCount(query.filters, ['plant']);
  const canAdjust = role === 'company-super-admin';
  const status = rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready';
  const hasFilters = filtersApplied > 0 || !!query.search || query.view !== 'all';

  const applyView = (v: SavedView) => {
    list.applyFilters(v.filters as FilterValues);
    if (v.view) list.setView(v.view);
    notify({ tone: 'info', title: `View “${v.name}” applied` });
  };

  return (
    <EnterprisePage
      title="Raw material lots"
      mock
      description="Phase 2 example of the shared enterprise list pattern. Sample lots from a typed mock adapter — not operational stock."
      meta={
        <>
          <span>Scope: {workspace.plant}</span>
          <span>As of {meta ? `${asOfFmt.format(new Date(meta.asOf))} WIB` : '…'}</span>
        </>
      }
      actions={
        <DropdownMenu modal={false}>
          <DropdownMenuTrigger asChild>
            <Button>
              <FlaskConical aria-hidden="true" />
              Demo states
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent className="w-60">
            <DropdownMenuLabel>Simulate adapter response</DropdownMenuLabel>
            <DropdownMenuRadioGroup
              value={simulate}
              onValueChange={(v) => {
                const url = new URL(window.location.href);
                if (v === 'none') url.searchParams.delete('simulate');
                else url.searchParams.set('simulate', v);
                window.location.assign(url.toString());
              }}
            >
              {SIMULATIONS.map((s) => (
                <DropdownMenuRadioItem key={s.value} value={s.value}>
                  {s.label}
                </DropdownMenuRadioItem>
              ))}
            </DropdownMenuRadioGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      }
    >
      {stale ? (
        <InlineAlert
          tone="warning"
          title="Data may be outdated"
          className="mb-4"
          action={
            <Button size="sm" onClick={() => rows.refetch()}>
              Refresh
            </Button>
          }
        >
          The read model was last updated more than 15 minutes ago. Quantities can differ from the ledger until it catches up.
        </InlineAlert>
      ) : null}
      <ListWorkspace
        label="Raw material lots"
        statusTabs={{
          label: 'View by',
          value: query.view,
          onValueChange: list.setView,
          items: VIEWS.map((v) => ({ ...v, count: counts.data?.[v.value] })),
          note: 'Views are saved filters over approved dimensions: stock status, allocation and location type (Material at Production). They never create new stock statuses.',
        }}
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch label="Search lots" placeholder="Search lot, material, heat no." value={query.search} onChange={list.setSearch} />
              <FilterToggle panel={panel} count={filtersApplied} controlsId="rm-filters" />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{meta ? meta.total.toLocaleString('en-US') : '…'}</b> lots
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <SavedViewsMenu
                views={views}
                onApply={applyView}
                onSave={(name) => {
                  setViews((v) => [...v, { id: `v-${v.length + 1}`, name, scope: 'mine', filters: query.filters, view: query.view, search: query.search }]);
                  notify({ tone: 'success', title: `View “${name}” saved`, description: 'Mock: stored for this page only.' });
                }}
              />
              <span className="max-md:hidden">
                <ImportWizard title="Import raw material lots" source={mockImportSource as ImportSource} />
              </span>
              <ExportMenu resource="Raw material lots" source={mockExportSource as ExportSource} filtersApplied={filtersApplied} />
              <span aria-hidden="true" className="h-6 w-px bg-line max-md:hidden" />
              <DensityToggle value={density} onChange={setDensity} />
              <ColumnSettings columns={COLUMNS} value={visibility} onChange={setVisibility} />
            </div>
          </>
        }
        filterBar={<FilterBar id="rm-filters" fields={FIELDS} values={query.filters} onApply={list.applyFilters} panel={panel} />}
        activeFilters={
          <ActiveFilters
            fields={FIELDS}
            values={query.filters}
            search={query.search}
            onRemove={list.removeFilter}
            onClearSearch={() => list.setSearch('')}
            onClearAll={list.clearFilters}
          />
        }
        bulkBar={
          <BulkBar
            count={selectedIds.length}
            selectedIds={selectedIds}
            onClear={() => setSel({ key: qKey, value: {} })}
            actions={[
              {
                id: 'labels',
                label: 'Print labels',
                icon: Printer,
                onSelect: (ids) => notify({ tone: 'info', title: `Mock: ${ids.length} lot labels sent to print preview` }),
              },
              {
                id: 'issue',
                label: 'Request issue',
                icon: ArrowRightLeft,
                onSelect: (ids) => notify({ tone: 'info', title: `Mock: issue request drafted for ${ids.length} lots` }),
              },
            ]}
          />
        }
        footnote="Warehouse stock buckets are disjoint: Available + Allocated + Hold + Blocked = on-hand in the warehouse. Material at Production is shown separately and is still material stock."
        pagination={
          status === 'ready' && meta && meta.total > 0 ? (
            <Pagination
              page={query.page}
              pageSize={query.pageSize}
              total={meta.total}
              itemLabel="lots"
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
          label="Raw material lots"
          columns={COLUMNS}
          groups={GROUPS}
          rows={rows.data?.rows ?? EMPTY}
          getRowId={(l) => l.id}
          rowLabel={(l) => `lot ${l.lot}`}
          status={status}
          error={rows.error}
          onRetry={() => rows.refetch()}
          isFetching={rows.isFetching && !rows.isPending}
          sort={query.sort}
          onSortChange={list.setSort}
          density={density}
          columnVisibility={visibility}
          onColumnVisibilityChange={setVisibility}
          selection={selection}
          onSelectionChange={(v) => setSel({ key: qKey, value: v })}
          skeletonRows={Math.min(query.pageSize, 10)}
          empty={
            hasFilters ? (
              <EmptyState
                title="No lots match these filters"
                description="Nothing in this scope matches the current view, search and filters. Clear filters or switch to All."
                action={
                  <Button size="sm" onClick={list.clearFilters}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState title="No raw material lots yet" description="Lots appear here after the first RM receipt is posted." />
            )
          }
          rowActions={{
            primary: () => ({ id: 'view', label: 'View', icon: Eye, onSelect: setDetail }),
            menu: () => [
              {
                id: 'ledger',
                label: 'Movement ledger',
                icon: BookOpen,
                onSelect: (l) => notify({ title: `Mock: movement ledger for ${l.lot} opens in a later phase` }),
              },
              { id: 'trace', label: 'Trace lot', icon: GitBranch, onSelect: (l) => notify({ title: `Mock: trace for ${l.lot} opens in a later phase` }) },
              { id: 'label', label: 'Print lot label', icon: Printer, onSelect: (l) => notify({ title: `Mock: label preview for ${l.lot}` }) },
              'separator',
              { id: 'issue', label: 'Request issue', icon: ArrowRightLeft, onSelect: (l) => notify({ title: `Mock: issue request drafted for ${l.lot}` }) },
              {
                id: 'adjust',
                label: 'Request stock adjustment',
                icon: SlidersHorizontal,
                onSelect: setAdjust,
                disabledReason: canAdjust ? undefined : 'Requires Inventory Controller role',
              },
            ],
          }}
        />
      </ListWorkspace>

      <Drawer open={!!detail} onOpenChange={(v) => !v && setDetail(null)}>
        <DrawerContent title={detail ? `Lot ${detail.lot}` : 'Lot'} closeLabel="Close details">
          {detail ? <LotDetail lot={detail} /> : null}
        </DrawerContent>
      </Drawer>

      <ConfirmDialog
        open={!!adjust}
        onOpenChange={(v) => !v && setAdjust(null)}
        title={adjust ? `Request stock adjustment for ${adjust.lot}` : 'Request stock adjustment'}
        description={
          <>
            This creates an adjustment <b className="text-ink">request</b> for independent approval. Nothing is posted until an approver confirms it. Use it
            only for an approved count result — not for unexplained variance.
          </>
        }
        confirmLabel="Submit request"
        reason={{ label: 'Reason (stored in the audit trail)', minLength: 10 }}
        onConfirm={async () => {
          await new Promise((r) => setTimeout(r, 400));
          notify({ tone: 'success', title: 'Mock: adjustment request submitted', description: 'An approver will review it. No stock changed.' });
        }}
      />
    </EnterprisePage>
  );
}

const EMPTY: RmLot[] = [];

function LotDetail({ lot }: { lot: RmLot }) {
  const buckets = [
    ['Available', lot.available],
    ['Allocated', lot.allocated],
    ['Hold', lot.hold],
    ['Blocked', lot.blocked],
    ['At production', lot.atProduction],
  ] as const;
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="min-h-0 flex-1 overflow-y-auto p-5">
        <dl className="m-0 grid grid-cols-2 gap-x-4 gap-y-3 text-[13.5px]">
          {[
            ['Item', lot.item],
            ['Material', lot.material],
            ['Warehouse', lot.warehouse],
            ['Location', lot.location],
            ['Heat no.', lot.heat],
            ['Mill certificate', lot.certificate],
            ['Received', formatDate(lot.receivedAt)],
          ].map(([k, v]) => (
            <div key={k} className="min-w-0">
              <dt className="text-xs text-ink-2">{k}</dt>
              <dd className="m-0 truncate">{v}</dd>
            </div>
          ))}
        </dl>
        <h3 className="mt-6 mb-2 text-sm font-semibold">Quantity by bucket</h3>
        <table className="w-full border-separate border-spacing-0 text-[13px]">
          <caption className="sr-only">Quantity by bucket for lot {lot.lot}</caption>
          <tbody>
            {buckets.map(([k, v]) => (
              <tr key={k}>
                <th scope="row" className="border-b border-line py-2 text-left font-normal text-ink-2">
                  {k}
                </th>
                <td className="border-b border-line py-2 text-right">
                  <QuantityWithUom value={v} uom={lot.uom} />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <p className="mt-3 text-xs text-ink-2">Sample data from a mock adapter. Production reads balances from the inventory ledger projection.</p>
      </div>
      <div className="flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3">
        <Button>
          <GitBranch aria-hidden="true" />
          Trace
        </Button>
        <Button variant="primary">Request issue</Button>
      </div>
    </div>
  );
}

'use client';

import * as React from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, Ellipsis } from 'lucide-react';
import {
  columnVisibilityFeature,
  createColumnHelper,
  rowSelectionFeature,
  rowSortingFeature,
  tableFeatures,
  useTable,
  type RowData,
  type Updater,
} from '@tanstack/react-table';
import {
  Button,
  Checkbox,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  ErrorState,
  IconButton,
  PermissionDeniedState,
  Skeleton,
  cn,
} from '@eora/ui';
import { isSourceError, type Density, type SortRule } from '../../data';
import type { ColumnGroup, ColumnVisibility, EoraColumn, RowAction, RowSelection } from './types';

const features = tableFeatures({ rowSortingFeature, rowSelectionFeature, columnVisibilityFeature });

type RowActions<T> = { primary?: (row: T) => RowAction<T> | undefined; menu?: (row: T) => (RowAction<T> | 'separator')[] };

export type DataTableProps<T extends RowData> = {
  /** Table caption (screen readers) — e.g. "Raw material lots". */
  label: string;
  columns: EoraColumn<T>[];
  groups?: ColumnGroup[];
  rows: T[];
  getRowId: (row: T) => string;
  /** Human name of a row for checkbox / menu labels, e.g. "lot C-08". */
  rowLabel: (row: T) => string;
  status: 'loading' | 'error' | 'ready';
  error?: unknown;
  onRetry?: () => void;
  /** Previous page still shown while the next request runs. */
  isFetching?: boolean;
  /** Shown when ready with zero rows (filtered-empty vs first-run is the page's choice). */
  empty: React.ReactNode;
  sort: SortRule[];
  onSortChange: (sort: SortRule[]) => void;
  density: Density;
  columnVisibility?: ColumnVisibility;
  onColumnVisibilityChange?: (v: ColumnVisibility) => void;
  selection?: RowSelection;
  onSelectionChange?: (v: RowSelection) => void;
  rowActions?: RowActions<T>;
  skeletonRows?: number;
};

function resolve<V>(u: Updater<V>, prev: V): V {
  return typeof u === 'function' ? (u as (p: V) => V)(prev) : u;
}

/**
 * EoraDataTable — one table for every module (EORA-COMPONENT-STANDARDS §5): manual (server) sorting/paging,
 * density presets, grouped headers, pinned identity/actions columns, row actions with disabled reasons,
 * selection for bulk actions, card reflow on phones, and loading / error / empty / no-permission states.
 */
export function DataTable<T extends RowData>(props: DataTableProps<T>) {
  const {
    label,
    columns,
    groups,
    rows,
    getRowId,
    rowLabel,
    status,
    error,
    onRetry,
    isFetching,
    empty,
    sort,
    onSortChange,
    density,
    rowActions,
    skeletonRows = 8,
  } = props;
  const selectable = !!props.onSelectionChange;
  const selection = props.selection ?? EMPTY_SELECTION;
  const visibility = props.columnVisibility ?? EMPTY_VISIBILITY;

  const tableColumns = React.useMemo(() => {
    const helper = createColumnHelper<typeof features, T>();
    return columns.map((c) =>
      helper.accessor((): unknown => null, {
        id: c.id,
        header: c.header,
        enableSorting: !!c.sortable,
        // Server sorts; the accessor carries no value, so the cycle must not be inferred from data type.
        sortDescFirst: false,
        enableHiding: !(c.critical || c.lead) && c.hideable !== false,
      }),
    );
  }, [columns]);

  const table = useTable({
    features,
    columns: tableColumns,
    data: rows,
    getRowId: (r: T) => getRowId(r),
    manualSorting: true,
    enableMultiSort: false,
    enableSortingRemoval: false,
    enableRowSelection: selectable,
    state: { sorting: sort, rowSelection: selection, columnVisibility: visibility },
    onSortingChange: (u: Updater<SortRule[]>) => onSortChange(resolve(u, sort)),
    onRowSelectionChange: (u: Updater<RowSelection>) => props.onSelectionChange?.(resolve(u, selection)),
    onColumnVisibilityChange: (u: Updater<ColumnVisibility>) => props.onColumnVisibilityChange?.(resolve(u, visibility)),
  });

  const byId = React.useMemo(() => new Map(columns.map((c) => [c.id, c])), [columns]);
  const visible = table
    .getVisibleLeafColumns()
    .map((tc) => ({ tc, col: byId.get(tc.id)! }))
    .filter((v) => v.col);
  const hasActions = !!(rowActions?.primary || rowActions?.menu);
  const compact = density === 'compact';

  // Grouped header row: contiguous runs of the same group id across [select?, ...visible, actions?].
  const groupRow = (() => {
    if (!groups?.length || !visible.some((v) => v.col.group)) return null;
    const cells: { id: string | undefined; span: number; lead: boolean }[] = [];
    const slots = [
      ...(selectable ? [{ group: undefined, lead: true }] : []),
      ...visible.map((v) => ({ group: v.col.group, lead: !!v.col.lead })),
      ...(hasActions ? [{ group: undefined, lead: false }] : []),
    ];
    for (const s of slots) {
      const last = cells[cells.length - 1];
      if (last && last.id === s.group && !(s.lead && !last.lead)) last.span += 1;
      else cells.push({ id: s.group, span: 1, lead: s.lead });
    }
    return cells;
  })();

  const [scroll, setScroll] = React.useState({ start: false, end: false });
  const wrapRef = React.useRef<HTMLDivElement>(null);
  const measure = React.useCallback(() => {
    const el = wrapRef.current;
    if (!el) return;
    setScroll({ start: el.scrollLeft > 0, end: el.scrollLeft + el.clientWidth < el.scrollWidth - 1 });
  }, []);
  React.useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const ro = new ResizeObserver(() => measure());
    ro.observe(el);
    return () => ro.disconnect();
  }, [measure]);

  const forbidden = status === 'error' && isSourceError(error) && error.kind === 'forbidden';
  const errorRef = isSourceError(error) ? error.reference : undefined;

  const cellPad = compact ? 'px-2.5' : 'px-3';
  const rowH = compact ? 'h-[34px]' : 'h-11';
  const hdrH = compact ? 'h-8' : 'h-[38px]';
  const leadOffset = selectable ? 'left-11' : 'left-0';
  const stickyShadowStart = scroll.start ? 'shadow-[6px_0_6px_-6px_rgb(15_23_42/0.25)]' : '';
  const stickyShadowEnd = scroll.end ? 'shadow-[-6px_0_6px_-6px_rgb(15_23_42/0.25)]' : '';

  const showSkeleton = status === 'loading' && rows.length === 0;
  const pageAllSelected = selectable && rows.length > 0 && table.getIsAllPageRowsSelected();
  const pageSomeSelected = selectable && table.getIsSomePageRowsSelected();

  if (forbidden) return <PermissionDeniedState />;
  if (status === 'error') {
    return (
      <ErrorState
        title={`${label} could not be loaded`}
        description={
          <>
            {error instanceof Error ? error.message : 'The server did not respond.'} Your filters are kept.
            {errorRef ? ` Reference ${errorRef} for support.` : null}
          </>
        }
        action={
          onRetry ? (
            <Button size="sm" variant="primary" onClick={onRetry}>
              Try again
            </Button>
          ) : undefined
        }
      />
    );
  }

  return (
    <div
      data-density={density}
      style={{ '--hdr-h': compact ? '32px' : '38px' } as React.CSSProperties}
      className={cn('relative', compact && '[&_[data-slot=badge]]:h-5 [&_[data-slot=badge]]:px-1.5 [&_[data-slot=badge]]:text-[11.5px]')}
    >
      {isFetching && !showSkeleton ? (
        <span role="status" className="sr-only">
          Updating {label.toLowerCase()}
        </span>
      ) : null}
      {showSkeleton ? (
        <span role="status" className="sr-only">
          Loading {label.toLowerCase()}
        </span>
      ) : null}
      <div ref={wrapRef} onScroll={measure} className="relative overflow-x-auto overscroll-x-contain max-md:overflow-visible">
        <table
          aria-busy={showSkeleton || isFetching ? true : undefined}
          className={cn('w-full border-separate border-spacing-0 max-md:block', compact ? 'text-[12.5px]' : 'text-[13px]')}
        >
          <caption className="sr-only">{label}</caption>
          <thead className="max-md:hidden">
            {groupRow ? (
              <tr>
                {groupRow.map((g, i) => {
                  const grp = groups!.find((x) => x.id === g.id);
                  return (
                    <th
                      key={i}
                      scope="colgroup"
                      colSpan={g.span}
                      title={grp?.title}
                      className={cn('sticky top-0 z-2 border-b border-line bg-subtle text-center text-xs font-semibold text-ink', hdrH, grp && 'border-l')}
                    >
                      {grp ? grp.label : <span className="sr-only">—</span>}
                    </th>
                  );
                })}
              </tr>
            ) : null}
            <tr>
              {selectable ? (
                <th scope="col" className={cn('sticky left-0 z-3 w-11 min-w-11 border-b border-line bg-subtle pl-3', hdrH)}>
                  <Checkbox
                    aria-label="Select all rows on this page"
                    checked={pageAllSelected}
                    indeterminate={!pageAllSelected && pageSomeSelected}
                    onChange={(e) => table.toggleAllPageRowsSelected(e.target.checked)}
                    disabled={rows.length === 0}
                  />
                </th>
              ) : null}
              {visible.map(({ tc, col }, i) => {
                const sorted = tc.getIsSorted();
                const prevGroup = i > 0 ? visible[i - 1]!.col.group : undefined;
                return (
                  <th
                    key={tc.id}
                    scope="col"
                    aria-sort={col.sortable ? (sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : 'none') : undefined}
                    style={col.minWidth ? { minWidth: col.minWidth } : undefined}
                    className={cn(
                      'sticky top-0 z-2 border-b border-line bg-subtle text-left text-xs font-semibold whitespace-nowrap text-ink-2',
                      hdrH,
                      cellPad,
                      groupRow && 'top-[var(--hdr-h)]',
                      (col.numeric || col.align === 'end') && 'text-right',
                      col.group && col.group !== prevGroup && 'border-l',
                      col.lead && cn('z-3', leadOffset, stickyShadowStart),
                    )}
                  >
                    {col.sortable ? (
                      <button
                        type="button"
                        onClick={tc.getToggleSortingHandler()}
                        className={cn(
                          'inline-flex items-center gap-1 rounded-sm font-semibold hover:text-ink focus-visible:outline-2 focus-visible:outline-ring',
                          (col.numeric || col.align === 'end') && 'flex-row-reverse',
                        )}
                      >
                        {col.header}
                        {sorted === 'asc' ? (
                          <ArrowUp aria-hidden="true" className="size-[13px] text-accent" />
                        ) : sorted === 'desc' ? (
                          <ArrowDown aria-hidden="true" className="size-[13px] text-accent" />
                        ) : (
                          <ArrowUpDown aria-hidden="true" className="size-[13px] text-ink-muted" />
                        )}
                      </button>
                    ) : col.hideHeader ? (
                      <span className="sr-only">{col.header}</span>
                    ) : (
                      col.header
                    )}
                  </th>
                );
              })}
              {hasActions ? (
                <th scope="col" className={cn('sticky top-0 right-0 z-3 border-b border-line bg-subtle', hdrH, cellPad, stickyShadowEnd)}>
                  <span className="sr-only">Actions</span>
                </th>
              ) : null}
            </tr>
          </thead>
          <tbody className={cn('max-md:block', isFetching && !showSkeleton && 'opacity-70')}>
            {showSkeleton
              ? Array.from({ length: skeletonRows }, (_, r) => (
                  <tr key={r} aria-hidden="true" className="max-md:block max-md:border-b max-md:border-line max-md:px-4 max-md:py-3">
                    {selectable ? <td className={cn('border-b border-line max-md:hidden', rowH)} /> : null}
                    {visible.map(({ tc }) => (
                      <td key={tc.id} className={cn('border-b border-line max-md:block max-md:h-6 max-md:border-0', rowH, cellPad)}>
                        <Skeleton className="w-3/4" />
                      </td>
                    ))}
                    {hasActions ? <td className={cn('border-b border-line max-md:hidden', rowH)} /> : null}
                  </tr>
                ))
              : table.getRowModel().rows.map((row) => {
                  const r = row.original;
                  const selected = row.getIsSelected();
                  const primary = rowActions?.primary?.(r);
                  const menu = rowActions?.menu?.(r) ?? [];
                  const name = rowLabel(r);
                  return (
                    <tr
                      key={row.id}
                      aria-selected={selectable ? selected : undefined}
                      className={cn(
                        'group/row [&>td]:bg-surface hover:[&>td]:bg-subtle',
                        selected && '[&_[data-sub]]:text-ink-2s [&>td]:bg-selected hover:[&>td]:bg-selected',
                        'max-md:relative max-md:block max-md:border-b max-md:border-line max-md:px-4 max-md:py-3',
                      )}
                    >
                      {selectable ? (
                        <td
                          className={cn(
                            'sticky left-0 z-1 w-11 border-b border-line pl-3',
                            rowH,
                            'max-md:absolute max-md:top-3 max-md:right-3 max-md:left-auto max-md:h-auto max-md:w-auto max-md:border-0 max-md:bg-transparent! max-md:p-0',
                          )}
                        >
                          <Checkbox aria-label={`Select ${name}`} checked={selected} onChange={row.getToggleSelectedHandler()} />
                        </td>
                      ) : null}
                      {visible.map(({ tc, col }, i) => {
                        const prevGroup = i > 0 ? visible[i - 1]!.col.group : undefined;
                        const sub = !compact && col.sub ? col.sub(r) : null;
                        return (
                          <td
                            key={tc.id}
                            data-label={col.lead ? undefined : col.header}
                            title={col.title?.(r)}
                            className={cn(
                              'border-b border-line align-middle',
                              rowH,
                              cellPad,
                              (col.numeric || col.align === 'end') && 'text-right whitespace-nowrap tabular-nums',
                              col.textCol && 'max-w-[280px] min-w-[200px] truncate',
                              !col.textCol && 'whitespace-nowrap',
                              col.group && col.group !== prevGroup && 'border-l',
                              col.lead && cn('sticky z-1', leadOffset, stickyShadowStart),
                              // phone card reflow
                              'max-md:static max-md:flex max-md:h-auto max-md:max-w-none max-md:min-w-0 max-md:justify-between max-md:gap-3 max-md:border-0 max-md:bg-transparent! max-md:px-0 max-md:py-[3px] max-md:text-right max-md:whitespace-normal max-md:shadow-none',
                              'max-md:before:flex-none max-md:before:text-left max-md:before:text-xs max-md:before:text-ink-2 max-md:before:content-[attr(data-label)]',
                              col.lead && 'max-md:block max-md:pr-10 max-md:text-left max-md:text-sm max-md:before:content-none',
                              col.emptyOnPhone?.(r) && 'max-md:hidden',
                            )}
                          >
                            {sub ? (
                              <div className="min-w-0">
                                <div className={cn(col.textCol && 'truncate')}>{col.cell(r)}</div>
                                <div data-sub className="mt-px truncate text-xs text-ink-2 max-md:whitespace-normal">
                                  {sub}
                                </div>
                              </div>
                            ) : (
                              col.cell(r)
                            )}
                          </td>
                        );
                      })}
                      {hasActions ? (
                        <td
                          className={cn(
                            'sticky right-0 z-1 border-b border-line text-right whitespace-nowrap',
                            rowH,
                            cellPad,
                            stickyShadowEnd,
                            'max-md:static max-md:flex max-md:h-auto max-md:justify-end max-md:border-0 max-md:bg-transparent! max-md:px-0 max-md:pt-2 max-md:shadow-none',
                          )}
                        >
                          <span className="inline-flex items-center justify-end gap-0.5">
                            {primary ? (
                              <Button
                                size="sm"
                                variant="ghost"
                                aria-label={
                                  primary.disabledReason ? `${primary.label} ${name} — unavailable: ${primary.disabledReason}` : `${primary.label} ${name}`
                                }
                                aria-disabled={primary.disabledReason ? true : undefined}
                                title={primary.disabledReason}
                                onClick={() => {
                                  if (!primary.disabledReason) primary.onSelect(r);
                                }}
                                className={cn('text-link', compact && 'h-7', 'max-md:h-11')}
                              >
                                {primary.label}
                              </Button>
                            ) : null}
                            {menu.length ? <RowMenu row={r} name={name} items={menu} compact={compact} /> : null}
                          </span>
                        </td>
                      ) : null}
                    </tr>
                  );
                })}
          </tbody>
        </table>
      </div>
      {status === 'ready' && rows.length === 0 ? empty : null}
    </div>
  );
}

function RowMenu<T extends RowData>({ row, name, items, compact }: { row: T; name: string; items: (RowAction<T> | 'separator')[]; compact: boolean }) {
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <IconButton aria-label={`More actions for ${name}`} size={compact ? 'icon-sm' : 'icon'} className={cn(compact ? 'size-7' : 'size-8', 'max-md:size-11')}>
          <Ellipsis aria-hidden="true" />
        </IconButton>
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-64">
        <DropdownMenuLabel className="truncate">{name}</DropdownMenuLabel>
        {items.map((it, i) =>
          it === 'separator' ? (
            <DropdownMenuSeparator key={`sep-${i}`} />
          ) : (
            <DropdownMenuItem
              key={it.id}
              aria-disabled={it.disabledReason ? true : undefined}
              onSelect={(e) => {
                if (it.disabledReason) e.preventDefault();
                else it.onSelect(row);
              }}
              className={cn(it.disabledReason && 'cursor-not-allowed items-start py-1.5 text-ink-2')}
            >
              {it.icon ? <it.icon aria-hidden="true" className={cn(it.disabledReason && 'mt-0.5')} /> : null}
              <span className="flex min-w-0 flex-col">
                <span>{it.label}</span>
                {it.disabledReason ? <span className="text-xs">{it.disabledReason}</span> : null}
              </span>
            </DropdownMenuItem>
          ),
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

const EMPTY_SELECTION: RowSelection = {};
const EMPTY_VISIBILITY: ColumnVisibility = {};

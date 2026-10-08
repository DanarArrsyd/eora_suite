'use client';

import * as React from 'react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import type { FilterValues, ListQuery, SortRule } from '../data';

export type ListUrlConfig = {
  /** Filter keys owned by the page's FilterBar schema; anything else in the URL is ignored. */
  filterKeys: readonly string[];
  defaultSort: SortRule[];
  defaultView?: string;
  defaultPageSize: number;
  /** Scope filters from the workspace (e.g. plant). Always applied, never written to or removable from the URL. */
  lockedFilters?: FilterValues;
};

export type ListUrlState = {
  query: ListQuery;
  setPage: (page: number) => void;
  setPageSize: (size: number) => void;
  setSort: (sort: SortRule[]) => void;
  applyFilters: (values: FilterValues) => void;
  removeFilter: (key: string, value?: string) => void;
  clearFilters: () => void;
  setSearch: (search: string) => void;
  setView: (view: string) => void;
};

const F = 'f.';

export function parseSort(raw: string | null, fallback: SortRule[]): SortRule[] {
  if (!raw) return fallback;
  const rules = raw
    .split(',')
    .map((part) => {
      const [id, dir] = part.split('.');
      return id ? { id, desc: dir === 'desc' } : null;
    })
    .filter((r): r is SortRule => r !== null);
  return rules.length ? rules : fallback;
}

export function serializeSort(sort: SortRule[]): string {
  return sort.map((s) => `${s.id}.${s.desc ? 'desc' : 'asc'}`).join(',');
}

/** Pure: URL params → ListQuery (exported for tests). */
export function readListQuery(params: URLSearchParams, cfg: ListUrlConfig): ListQuery {
  const filters: Record<string, string | string[] | undefined> = {};
  for (const key of cfg.filterKeys) {
    const all = params.getAll(F + key).filter(Boolean);
    if (all.length) filters[key] = all.length === 1 ? all[0] : all;
  }
  const page = Number.parseInt(params.get('page') ?? '1', 10);
  const size = Number.parseInt(params.get('size') ?? '', 10);
  return {
    page: Number.isFinite(page) && page > 0 ? page : 1,
    pageSize: [20, 50, 100].includes(size) ? size : cfg.defaultPageSize,
    sort: parseSort(params.get('sort'), cfg.defaultSort),
    filters: { ...filters, ...(cfg.lockedFilters ?? {}) },
    search: params.get('q') ?? '',
    view: params.get('view') ?? cfg.defaultView ?? 'all',
  };
}

/** Pure: ListQuery → URL params. Locked scope filters and defaults are not written. */
export function writeListQuery(q: ListQuery, cfg: ListUrlConfig): URLSearchParams {
  const p = new URLSearchParams();
  if (q.view && q.view !== (cfg.defaultView ?? 'all')) p.set('view', q.view);
  if (q.search) p.set('q', q.search);
  for (const key of cfg.filterKeys) {
    if (cfg.lockedFilters && key in cfg.lockedFilters) continue;
    const v = q.filters[key];
    for (const item of Array.isArray(v) ? v : v ? [v] : []) p.append(F + key, item);
  }
  if (serializeSort(q.sort) !== serializeSort(cfg.defaultSort)) p.set('sort', serializeSort(q.sort));
  if (q.pageSize !== cfg.defaultPageSize) p.set('size', String(q.pageSize));
  if (q.page > 1) p.set('page', String(q.page));
  return p;
}

/**
 * URL-synced list state (EORA-COMPONENT-STANDARDS §4): view, search, filters, sort, page and page size live in the
 * query string so a link reproduces the same server query. Any change except paging returns to page 1.
 * `cfg` must be stable (module constant or useMemo).
 */
export function useListUrlState(cfg: ListUrlConfig): ListUrlState {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const query = React.useMemo(() => readListQuery(new URLSearchParams(params.toString()), cfg), [params, cfg]);

  const commit = React.useCallback(
    (next: ListQuery) => {
      const qs = writeListQuery(next, cfg).toString();
      router.replace(qs ? `${pathname}?${qs}` : pathname, { scroll: false });
    },
    [router, pathname, cfg],
  );

  return React.useMemo(
    () => ({
      query,
      setPage: (page) => commit({ ...query, page }),
      setPageSize: (pageSize) => commit({ ...query, pageSize, page: 1 }),
      setSort: (sort) => commit({ ...query, sort, page: 1 }),
      applyFilters: (filters) => commit({ ...query, filters, page: 1 }),
      removeFilter: (key, value) => {
        const current = query.filters[key];
        const next = Array.isArray(current) && value !== undefined ? current.filter((v) => v !== value) : undefined;
        commit({ ...query, filters: { ...query.filters, [key]: next && next.length ? next : undefined }, page: 1 });
      },
      clearFilters: () => commit({ ...query, filters: {}, search: '', page: 1 }),
      setSearch: (search) => commit({ ...query, search, page: 1 }),
      setView: (view) => commit({ ...query, view, page: 1 }),
    }),
    [query, commit],
  );
}

'use client';

import { api, call } from '@eora/features/auth';
import type { ListQuery, ListResult, ListSource, Option } from '@eora/features/data';

/*
 * Live list source for the query resources (ADR-0008, doc 24 §3, §5.2): ListQuery → GET /api/v1/query/{resource}.
 * The server filters, sorts and pages; the client only serializes the query and renders rows.
 */

/** doc 24 §3.1 grammar: filter[k]=v, filter[k][]=a&…, q, sort=-a,b, page[number], page[size]. */
export function queryString(query: Pick<ListQuery, 'filters' | 'search' | 'sort' | 'page' | 'pageSize'>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query.filters)) {
    if (Array.isArray(value)) value.forEach((v) => v && params.append(`filter[${key}][]`, v));
    else if (value) params.set(`filter[${key}]`, value);
  }
  if (query.search.trim()) params.set('q', query.search.trim());
  if (query.sort.length) params.set('sort', query.sort.map((s) => (s.desc ? '-' : '') + s.id).join(','));
  params.set('page[number]', String(query.page));
  params.set('page[size]', String(query.pageSize));
  return params.toString();
}

type Envelope = { data: unknown[]; meta: { total: number; asOf: string; timezone: string } };

async function fetchPage(resource: string, qs: string, signal?: AbortSignal): Promise<Envelope> {
  return (await call(() => api.GET('/query/{resource}', { params: { path: { resource } }, querySerializer: () => qs, signal }))) as Envelope;
}

export function querySource<T>(resource: string): ListSource<T> {
  return {
    resource,
    isMock: false,
    async list(query: ListQuery, signal?: AbortSignal): Promise<ListResult<T>> {
      const page = await fetchPage(resource, queryString(query), signal);
      return { rows: page.data as T[], meta: { total: page.meta.total, asOf: page.meta.asOf, timezone: page.meta.timezone } };
    },
  };
}

/**
 * Every row of a query resource the caller may see, sorted by `sortBy` (default code — a resource without a code field
 * names another sortable field), read page by page (the server caps a page at 200). Master lists of this kind stay small;
 * the cap of 50 pages only guards a runaway loop.
 */
export async function queryAll<T = Record<string, unknown>>(
  resource: string,
  filters: Record<string, string | string[]> = {},
  signal?: AbortSignal,
  sortBy = 'code',
): Promise<T[]> {
  const rows: T[] = [];
  for (let page = 1; page <= 50; page++) {
    const qs = queryString({ filters, search: '', sort: [{ id: sortBy, desc: false }], page, pageSize: 200 });
    const result = await fetchPage(resource, qs, signal);
    rows.push(...(result.data as T[]));
    if (rows.length >= result.meta.total || result.data.length === 0) break;
  }
  return rows;
}

/** Options for a picker from a query resource: every visible row, `value` = its id. */
export async function queryOptions(
  resource: string,
  label: (row: Record<string, unknown>) => string,
  filters: Record<string, string | string[]> = {},
  signal?: AbortSignal,
): Promise<Option[]> {
  return (await queryAll(resource, filters, signal)).map((r) => ({ value: String(r.id), label: label(r) }));
}

'use client';

import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, call } from '@eora/features/auth';
import type { FilterValues, SortRule } from '@eora/features/data';
import type { SavedView } from '@eora/features/patterns';

/** What a saved view keeps (the API accepts exactly these keys). */
export type ViewState = { search?: string; filters?: FilterValues; sort?: SortRule[]; columns?: Record<string, boolean> };
type Stored = { id: string; name: string; state: ViewState };

/**
 * The signed-in user's saved views of one list (GET/POST/DELETE /api/v1/list-views, EORA-COMPONENT-STANDARDS §4).
 * Personal only: the server keeps each view to its user in the active tenant.
 */
export function useSavedViews(list: string) {
  const client = useQueryClient();
  const key = ['list-views', list];
  const views = useQuery({
    queryKey: key,
    queryFn: async ({ signal }) => (await call(() => api.GET('/list-views', { params: { query: { list } }, signal }))) as unknown as { data: Stored[] },
    staleTime: 60_000,
  });
  const asView = (v: Stored): SavedView => ({
    id: v.id,
    name: v.name,
    scope: 'mine',
    filters: v.state.filters ?? {},
    search: v.state.search,
    sort: v.state.sort,
    columns: v.state.columns,
  });
  return {
    views: (views.data?.data ?? []).map(asView),
    save: async (name: string, state: ViewState) => {
      await call(() => api.POST('/list-views', { body: { list, name, state } as never }));
      await client.invalidateQueries({ queryKey: key });
    },
    remove: async (view: SavedView) => {
      await call(() => api.DELETE('/list-views/{view}', { params: { path: { view: view.id } } }));
      await client.invalidateQueries({ queryKey: key });
    },
  };
}

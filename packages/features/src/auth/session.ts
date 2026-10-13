'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { paths } from '@eora/api-client';
import { api, ApiError, call } from './api';

export type Me = paths['/auth/me']['get']['responses'][200]['content']['application/json'];

export const ME_KEY = ['auth', 'me'] as const;

/** The signed-in user, tenants, active tenant/plant and permission hints; `null` when not signed in (401). */
export function useMe() {
  return useQuery({
    queryKey: ME_KEY,
    queryFn: async (): Promise<Me | null> => {
      try {
        return await call(() => api.GET('/auth/me'));
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null;
        throw e;
      }
    },
    staleTime: 30_000,
    retry: (count, e) => !(e instanceof ApiError) && count < 2,
  });
}

/** Mutations that change the session: every one replaces the cached `me` and drops tenant-scoped data. */
export function useSessionActions() {
  const client = useQueryClient();
  const replace = (me: Me | null) => {
    client.removeQueries({ predicate: (q) => q.queryKey[0] !== ME_KEY[0] });
    client.setQueryData(ME_KEY, me);
  };

  const login = useMutation({
    mutationFn: (body: { email: string; password: string }) => call(() => api.POST('/auth/login', { body })),
    onSuccess: replace,
  });
  const selectTenant = useMutation({
    mutationFn: (tenantId: string) => call(() => api.POST('/auth/tenant', { body: { tenantId } })),
    onSuccess: replace,
  });
  const selectPlant = useMutation({
    mutationFn: (plantId: string) => call(() => api.POST('/auth/plant', { body: { plantId } })),
    onSuccess: replace,
  });
  const logout = useMutation({
    mutationFn: () => call(() => api.POST('/auth/logout')),
    onSettled: () => {
      client.clear();
      client.setQueryData(ME_KEY, null);
    },
  });

  return { login, selectTenant, selectPlant, logout };
}

/** Only same-origin relative paths are followed after sign-in (no open redirect). */
export function safeNext(next: string | null | undefined, fallback = '/'): string {
  return next && next.startsWith('/') && !next.startsWith('//') && !next.startsWith('/\\') ? next : fallback;
}

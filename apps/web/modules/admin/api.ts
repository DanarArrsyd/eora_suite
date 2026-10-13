'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { paths } from '@eora/api-client';
import { api, call } from '@eora/features/auth';

/* Administration → Users, Roles & Permissions on the live API (doc 25 §2.4, §3, §13; doc 18). */

type Json<T> = T extends { content: { 'application/json': infer B } } ? B : never;

export type Member = Json<paths['/core/members']['get']['responses'][200]>['data'][number];
export type Assignment = Member['assignments'][number];
export type Role = Json<paths['/core/roles']['get']['responses'][200]>['data'][number];
export type MemberStatus = Member['status'];
export type ScopeType = Assignment['scopeType'];
export type AssignBody = paths['/core/role-assignments']['post']['requestBody'] extends { content: { 'application/json': infer B } } ? B : never;

/** Permission codes (doc 18 §2): view the lists, invite members, grant/revoke roles and suspend/reactivate members. */
export const PERMISSION = { view: 'core.users_roles.view', invite: 'core.users_roles.create', manage: 'core.users_roles.post' } as const;

export const MEMBERS_KEY = ['core', 'members'] as const;
export const ROLES_KEY = ['core', 'roles'] as const;

export function useMembers(enabled = true) {
  return useQuery({ queryKey: MEMBERS_KEY, queryFn: async () => (await call(() => api.GET('/core/members'))).data, enabled });
}

export function useRoles(enabled = true) {
  return useQuery({ queryKey: ROLES_KEY, queryFn: async () => (await call(() => api.GET('/core/roles'))).data, enabled, staleTime: 5 * 60_000 });
}

/** Every write refreshes the member list (statuses and assignments change together on the server). */
export function useAccessActions() {
  const client = useQueryClient();
  const refresh = () => client.invalidateQueries({ queryKey: MEMBERS_KEY });
  const membership = (id: string) => ({ params: { path: { membership: id } } });

  return {
    invite: useMutation({
      mutationFn: (email: string) => call(() => api.POST('/core/members/invitations', { body: { email } })),
      onSuccess: refresh,
    }),
    resend: useMutation({
      mutationFn: (id: string) => call(() => api.POST('/core/members/{membership}/actions/resend-invitation', membership(id))),
      onSuccess: refresh,
    }),
    suspend: useMutation({
      mutationFn: (id: string) => call(() => api.POST('/core/members/{membership}/actions/suspend', membership(id))),
      onSuccess: refresh,
    }),
    reactivate: useMutation({
      mutationFn: (id: string) => call(() => api.POST('/core/members/{membership}/actions/reactivate', membership(id))),
      onSuccess: refresh,
    }),
    assign: useMutation({
      mutationFn: (body: AssignBody) => call(() => api.POST('/core/role-assignments', { body })),
      onSuccess: refresh,
    }),
    revoke: useMutation({
      mutationFn: ({ id, reason }: { id: string; reason: string }) =>
        call(() => api.POST('/core/role-assignments/{assignment}/actions/revoke', { params: { path: { assignment: id } }, body: { reason } })),
      onSuccess: refresh,
    }),
  };
}

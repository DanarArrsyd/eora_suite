'use client';

import * as React from 'react';
import { MailPlus, Settings2, UserCheck, UserX } from 'lucide-react';
import { useMe } from '@eora/features/auth';
import { useDensityPreference } from '@eora/features/data';
import {
  DataTable,
  DensityToggle,
  DomainStatusBadge,
  EnterprisePage,
  ListSearch,
  ListWorkspace,
  useListUrlState,
  type EoraColumn,
  type ListUrlConfig,
} from '@eora/features/patterns';
import { Button, EmptyState, InlineAlert, LoadingState, PermissionDeniedState, Tooltip } from '@eora/ui';
import { PERMISSION, useMembers, useRoles, type Member } from './api';
import { InviteDialog } from './invite-dialog';
import { MEMBER_ACTIONS, MemberDrawer, useMemberAction, type AccessContext } from './member-drawer';
import { filterMembers, INVITE_REASON, MEMBER_VIEWS, memberActionBlock, memberViewCounts, scopeText, type MemberView } from './model';

const URL_CONFIG: ListUrlConfig = { filterKeys: [], defaultSort: [], defaultView: 'all', defaultPageSize: 100 };
const EMPTY: Member[] = [];

export function useAccessContext(): { ctx: AccessContext | null; canView: boolean; pending: boolean } {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const ctx = me.data
    ? {
        selfId: me.data.user.id,
        canInvite: permissions.includes(PERMISSION.invite),
        canManage: permissions.includes(PERMISSION.manage),
        plants: me.data.plants,
        // D-044: the tenant zone reads calendar dates; Asia/Jakarta is the recorded default
        timeZone: me.data.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta',
      }
    : null;
  return { ctx, canView: permissions.includes(PERMISSION.view), pending: me.isPending };
}

/**
 * Administration → Users (doc 28 §1): members of the active tenant, their status and their roles inside the viewer's
 * scope. Invite, resend, suspend, reactivate, assign and revoke all run against the live API; the server enforces
 * permissions, scope coverage (D-068) and SoD-06 — the disabled states here are guidance only.
 */
export function UsersScreen() {
  const { ctx, canView, pending } = useAccessContext();
  const members = useMembers(canView);
  const roles = useRoles(canView);
  const [density, setDensity] = useDensityPreference();
  const list = useListUrlState(URL_CONFIG);
  const { query } = list;
  const view = (MEMBER_VIEWS.some((v) => v.value === query.view) ? query.view : 'all') as MemberView;
  const [inviteOpen, setInviteOpen] = React.useState(false);
  const [openId, setOpenId] = React.useState<string | null>(null);
  const [notice, setNotice] = React.useState<{ tone: 'success' | 'danger'; title: string } | null>(null);
  const rowAction = useMemberAction(setNotice);

  if (pending || !ctx) return <LoadingState title="Loading users" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Users is not available for your roles" description="Ask a tenant administrator for access to user administration." />
      </div>
    );
  }

  const all = members.data ?? EMPTY;
  const rows = filterMembers(all, view, query.search);
  const counts = memberViewCounts(all, query.search);
  const open = all.find((m) => m.membershipId === openId) ?? null;
  const status = members.isError ? 'error' : members.isPending ? 'loading' : 'ready';

  const columns: EoraColumn<Member>[] = [
    {
      id: 'member',
      header: 'Member',
      lead: true,
      textCol: true,
      cell: (m) => m.displayName,
      sub: (m) => m.email,
      title: (m) => `${m.displayName} · ${m.email}`,
    },
    { id: 'status', header: 'Status', critical: true, cell: (m) => <DomainStatusBadge domain="membership" status={m.status} /> },
    {
      id: 'roles',
      header: 'Roles in your scope',
      textCol: true,
      minWidth: 260,
      cell: (m) => (m.assignments.length ? m.assignments.map((a) => `${a.roleCode} · ${scopeText(a, ctx.plants)}`).join(', ') : 'No role'),
      title: (m) => m.assignments.map((a) => `${a.roleCode} (${scopeText(a, ctx.plants)})`).join(', ') || undefined,
      sub: (m) => (m.assignments.some((a) => !a.effective) ? 'Some roles are not in effect now' : undefined),
    },
  ];

  const inviteButton = ctx.canInvite ? (
    <Button variant="primary" onClick={() => setInviteOpen(true)}>
      <MailPlus aria-hidden="true" className="size-4" />
      Invite user
    </Button>
  ) : (
    <Tooltip content={INVITE_REASON}>
      <Button variant="primary" aria-disabled="true" aria-label={`Invite user. ${INVITE_REASON}`} onClick={(e) => e.preventDefault()}>
        <MailPlus aria-hidden="true" className="size-4" />
        Invite user
      </Button>
    </Tooltip>
  );

  return (
    <EnterprisePage
      title="Users"
      description="People with access to this workspace, their status and the roles they hold. Inviting gives no access until a role is assigned."
      actions={inviteButton}
    >
      {notice ? (
        <InlineAlert
          tone={notice.tone}
          title={notice.title}
          className="mb-4"
          action={
            <Button size="sm" variant="ghost" onClick={() => setNotice(null)}>
              Dismiss
            </Button>
          }
        />
      ) : null}
      <ListWorkspace
        label="Users"
        statusTabs={{
          label: 'View by',
          value: view,
          onValueChange: list.setView,
          items: MEMBER_VIEWS.map((v) => ({ ...v, count: members.data ? counts[v.value] : undefined })),
        }}
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch label="Search users" placeholder="Name, e-mail or role code" value={query.search} onChange={list.setSearch} />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{members.data ? rows.length : '…'}</b> users
              </span>
            </div>
            <DensityToggle value={density} onChange={setDensity} />
          </>
        }
      >
        <DataTable
          label="Users"
          columns={columns}
          rows={rows}
          getRowId={(m) => m.membershipId}
          rowLabel={(m) => m.displayName}
          status={status}
          error={members.error}
          onRetry={() => members.refetch()}
          isFetching={members.isFetching && !members.isPending}
          sort={[]}
          onSortChange={() => undefined}
          density={density}
          skeletonRows={5}
          empty={
            query.search || view !== 'all' ? (
              <EmptyState
                title="No users match"
                description="Nobody in this view matches the search."
                action={
                  <Button
                    size="sm"
                    onClick={() => {
                      list.clearFilters();
                      list.setView('all');
                    }}
                  >
                    Show all users
                  </Button>
                }
              />
            ) : (
              <EmptyState title="No users yet" description="Invite the first colleague, then assign a role so they can open a module." />
            )
          }
          rowActions={{
            primary: (m) => ({ id: 'manage', label: 'Manage', icon: Settings2, onSelect: () => setOpenId(m.membershipId) }),
            menu: (m) =>
              (
                [
                  ['resend', MailPlus],
                  ['suspend', UserX],
                  ['reactivate', UserCheck],
                ] as const
              ).map(([action, icon]) => ({
                id: action,
                label: MEMBER_ACTIONS[action].label,
                icon,
                onSelect: () => rowAction.start(action, m),
                disabledReason: memberActionBlock(action, m, ctx),
              })),
          }}
        />
      </ListWorkspace>
      {rowAction.dialog}
      <InviteDialog
        open={inviteOpen}
        onOpenChange={setInviteOpen}
        onInvited={(email) => {
          setInviteOpen(false);
          setNotice({ tone: 'success', title: `Invitation sent to ${email}. Assign a role so the new member can open a module.` });
        }}
      />
      <MemberDrawer
        key={openId ?? 'none'}
        member={open}
        members={all}
        roles={roles.data ?? []}
        rolesState={{ status: roles.isError ? 'error' : roles.isPending ? 'loading' : 'ready', retry: () => void roles.refetch() }}
        ctx={ctx}
        onClose={() => setOpenId(null)}
        onNotice={setNotice}
      />
    </EnterprisePage>
  );
}

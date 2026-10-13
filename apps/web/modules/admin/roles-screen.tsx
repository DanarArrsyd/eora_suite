'use client';

import * as React from 'react';
import { Eye, Plus } from 'lucide-react';
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
import { Button, Drawer, DrawerContent, EmptyState, ErrorState, InlineAlert, LoadingState, PermissionDeniedState, Tooltip } from '@eora/ui';
import { useMembers, useRoles, type Member, type Role } from './api';
import { AssignRoleForm } from './assign-role-form';
import { loadBlock, type AccessContext, type Loadable } from './member-drawer';
import { groupPermissions, holdersOf, MANAGE_REASON, SCOPE_LABEL, scopeText, validityText } from './model';
import { useAccessContext } from './users-screen';

const URL_CONFIG: ListUrlConfig = { filterKeys: [], defaultSort: [], defaultPageSize: 100 };

function RoleDrawer({
  role,
  members,
  membersState,
  roles,
  ctx,
  onClose,
}: {
  role: Role | null;
  members: readonly Member[];
  membersState: Loadable;
  roles: readonly Role[];
  ctx: AccessContext;
  onClose: () => void;
}) {
  const [assigning, setAssigning] = React.useState(false);
  const [notice, setNotice] = React.useState<string | null>(null);
  if (!role) return <Drawer open={false} />;
  const holders = holdersOf(role.id, members);

  return (
    <Drawer open onOpenChange={(o) => (o ? undefined : onClose())}>
      <DrawerContent title={`${role.name} (${role.code})`}>
        <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5">
          <dl className="m-0 grid grid-cols-[140px_1fr] gap-x-3 gap-y-2 text-[13px]">
            <dt className="text-ink-2">Template</dt>
            <dd className="m-0">{role.templateCode ?? 'Tenant role'}</dd>
            <dt className="text-ink-2">Suggested scope</dt>
            <dd className="m-0">{role.defaultScopeType ? SCOPE_LABEL[role.defaultScopeType as keyof typeof SCOPE_LABEL] : 'Chosen per assignment'}</dd>
          </dl>

          <section aria-labelledby="role-permissions" className="flex flex-col gap-2">
            <h3 id="role-permissions" className="m-0 text-sm font-semibold">
              Permissions ({role.permissions.length})
            </h3>
            {role.permissions.length === 0 ? (
              <p className="m-0 text-[13px] text-ink-2">
                This role carries no permission yet. Its permissions are defined when the modules it serves are released; assigning it now gives no access.
              </p>
            ) : (
              groupPermissions(role.permissions).map((g) => (
                <div key={g.module}>
                  <p className="m-0 text-xs font-semibold text-ink-2">{g.module}</p>
                  <ul className="m-0 flex list-none flex-wrap gap-1.5 p-0 pt-1">
                    {g.codes.map((c) => (
                      <li key={c} className="rounded-sm border border-line px-1.5 py-0.5 font-mono text-xs text-ink">
                        {c}
                      </li>
                    ))}
                  </ul>
                </div>
              ))
            )}
          </section>

          <section aria-labelledby="role-holders" className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-3">
              <h3 id="role-holders" className="m-0 text-sm font-semibold">
                Members with this role{membersState.status === 'ready' ? ` (${holders.length})` : ''}
              </h3>
              {assigning ? null : ctx.canManage && !loadBlock(membersState, 'Members') ? (
                <Button size="sm" onClick={() => setAssigning(true)}>
                  <Plus aria-hidden="true" className="size-4" />
                  Assign to member
                </Button>
              ) : (
                <Tooltip content={ctx.canManage ? (loadBlock(membersState, 'Members') ?? '') : MANAGE_REASON}>
                  <Button
                    size="sm"
                    aria-disabled="true"
                    aria-label={`Assign to member. ${ctx.canManage ? loadBlock(membersState, 'Members') : MANAGE_REASON}`}
                    onClick={(e) => e.preventDefault()}
                  >
                    <Plus aria-hidden="true" className="size-4" />
                    Assign to member
                  </Button>
                </Tooltip>
              )}
            </div>
            {notice ? <InlineAlert tone="success" title={notice} /> : null}
            {assigning ? (
              <div className="rounded-md border border-line p-4">
                <AssignRoleForm
                  members={members.filter((m) => m.userId !== ctx.selfId)}
                  roles={roles}
                  plants={ctx.plants}
                  timeZone={ctx.timeZone}
                  roleId={role.id}
                  onCancel={() => setAssigning(false)}
                  onDone={(title) => {
                    setAssigning(false);
                    setNotice(title);
                  }}
                />
              </div>
            ) : null}
            {membersState.status === 'loading' ? (
              <LoadingState title="Loading members" className="py-4" />
            ) : membersState.status === 'error' ? (
              <ErrorState
                className="py-4"
                title="Members could not be loaded"
                description="Who holds this role is unknown until the list loads."
                action={
                  <Button size="sm" onClick={membersState.retry}>
                    Retry
                  </Button>
                }
              />
            ) : holders.length === 0 ? (
              <EmptyState className="py-4" title="Nobody holds this role in your scope" description="Assign it to a member to give them its permissions." />
            ) : (
              <ul className="m-0 list-none p-0" aria-label={`Members with ${role.code}`}>
                {holders.map(({ member, assignments }) => (
                  <li key={member.membershipId} className="flex items-start justify-between gap-3 border-b border-line py-2.5 last:border-b-0">
                    <div className="min-w-0">
                      <p className="m-0 truncate text-sm font-medium text-ink">{member.displayName}</p>
                      {assignments.map((a) => (
                        <p key={a.id} className="m-0 text-xs text-ink-2">
                          {scopeText(a, ctx.plants)} · {validityText(a, ctx.timeZone)}
                        </p>
                      ))}
                    </div>
                    <DomainStatusBadge domain="membership" status={member.status} />
                  </li>
                ))}
              </ul>
            )}
            <p className="m-0 text-xs text-ink-2">Revoke a role from the member’s page in Users.</p>
          </section>
        </div>
      </DrawerContent>
    </Drawer>
  );
}

/**
 * Administration → Roles & Permissions (doc 28 §1, doc 18 §1, D-060): the tenant's roles copied from the templates,
 * what each one permits and who holds it inside the viewer's scope. Role content is fixed in M1; assigning happens here
 * or from a member's page.
 */
export function RolesScreen() {
  const { ctx, canView, pending } = useAccessContext();
  const roles = useRoles(canView);
  const members = useMembers(canView);
  const [density, setDensity] = useDensityPreference();
  const list = useListUrlState(URL_CONFIG);
  const [openId, setOpenId] = React.useState<string | null>(null);

  if (pending || !ctx) return <LoadingState title="Loading roles" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Roles & Permissions is not available for your roles"
          description="Ask a tenant administrator for access to user administration."
        />
      </div>
    );
  }

  const q = list.query.search.trim().toLowerCase();
  const all = roles.data ?? [];
  const rows = all.filter((r) => !q || r.name.toLowerCase().includes(q) || r.code.toLowerCase().includes(q) || r.permissions.some((p) => p.includes(q)));
  const memberList = members.data ?? [];
  const status = roles.isError ? 'error' : roles.isPending ? 'loading' : 'ready';

  const columns: EoraColumn<Role>[] = [
    { id: 'role', header: 'Role', lead: true, textCol: true, cell: (r) => r.name, sub: (r) => r.code },
    {
      id: 'scope',
      header: 'Suggested scope',
      cell: (r) => (r.defaultScopeType ? SCOPE_LABEL[r.defaultScopeType as keyof typeof SCOPE_LABEL] : 'Per assignment'),
    },
    { id: 'permissions', header: 'Permissions', numeric: true, critical: true, cell: (r) => (r.permissions.length ? r.permissions.length : 'None yet') },
    {
      id: 'holders',
      header: 'Members in your scope',
      numeric: true,
      cell: (r) => (members.data ? holdersOf(r.id, memberList).length : members.isError ? 'Unavailable' : '…'),
    },
  ];

  return (
    <EnterprisePage
      title="Roles & Permissions"
      description="Roles come from the EORA templates. Open a role to see what it permits and who holds it, or to assign it."
    >
      <ListWorkspace
        label="Roles"
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch label="Search roles" placeholder="Role, code or permission" value={list.query.search} onChange={list.setSearch} />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{roles.data ? rows.length : '…'}</b> roles
              </span>
            </div>
            <DensityToggle value={density} onChange={setDensity} />
          </>
        }
        footnote="Roles marked None yet carry permissions once the modules they serve are released."
      >
        <DataTable
          label="Roles"
          columns={columns}
          rows={rows}
          getRowId={(r) => r.id}
          rowLabel={(r) => `${r.name} (${r.code})`}
          status={status}
          error={roles.error}
          onRetry={() => roles.refetch()}
          sort={[]}
          onSortChange={() => undefined}
          density={density}
          skeletonRows={6}
          empty={
            q ? (
              <EmptyState
                title="No roles match"
                description="No role name, code or permission matches the search."
                action={
                  <Button size="sm" onClick={list.clearFilters}>
                    Clear search
                  </Button>
                }
              />
            ) : (
              <EmptyState title="No roles in this workspace" description="Roles are created when the workspace is provisioned. Contact EORA support." />
            )
          }
          rowActions={{ primary: (r) => ({ id: 'view', label: 'View', icon: Eye, onSelect: () => setOpenId(r.id) }) }}
        />
      </ListWorkspace>
      <RoleDrawer
        key={openId ?? 'none'}
        role={all.find((r) => r.id === openId) ?? null}
        members={memberList}
        membersState={{ status: members.isError ? 'error' : members.isPending ? 'loading' : 'ready', retry: () => void members.refetch() }}
        roles={all}
        ctx={ctx}
        onClose={() => setOpenId(null)}
      />
    </EnterprisePage>
  );
}

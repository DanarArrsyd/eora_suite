import * as React from 'react';
import { MailPlus, Plus, UserCheck, UserX } from 'lucide-react';
import { ConfirmDialog, DomainStatusBadge } from '@eora/features/patterns';
import { Button, Drawer, DrawerContent, EmptyState, InlineAlert, Tooltip } from '@eora/ui';
import { useAccessActions, type Assignment, type Member, type Role } from './api';
import { AssignRoleForm } from './assign-role-form';
import { actionMessage } from './errors';
import { memberActionBlock, scopeText, validityText, type PlantRef } from './model';

export type AccessContext = { selfId: string; canInvite: boolean; canManage: boolean; plants: readonly PlantRef[]; timeZone: string };

/** State of a list another view depends on: role-dependent controls wait for it and offer a retry when it failed. */
export type Loadable = { status: 'loading' | 'error' | 'ready'; retry: () => void };

export function loadBlock(state: Loadable, what: string): string | undefined {
  if (state.status === 'loading') return `${what} are still loading.`;
  return state.status === 'error' ? `${what} could not be loaded.` : undefined;
}

type MemberAction = 'resend' | 'suspend' | 'reactivate';

/** Labels and confirmation copy for the membership actions (doc 25 §2.4; D-068: scope must cover every assignment). */
export const MEMBER_ACTIONS: Record<
  MemberAction,
  { label: string; done: (name: string) => string; confirm?: { title: string; body: string; label: string; tone: 'danger' | 'primary' } }
> = {
  resend: { label: 'Resend invitation', done: (n) => `New invitation sent to ${n}. The previous link no longer works.` },
  suspend: {
    label: 'Suspend',
    done: (n) => `${n} is suspended.`,
    confirm: {
      title: 'Suspend member?',
      body: 'The member loses all access to this workspace on the next request. Role assignments are kept and come back on reactivation.',
      label: 'Suspend member',
      tone: 'danger',
    },
  },
  reactivate: {
    label: 'Reactivate',
    done: (n) => `${n} is reactivated.`,
    confirm: {
      title: 'Reactivate member?',
      body: 'The member regains the roles still assigned. A member who never accepted the invitation returns to Invited.',
      label: 'Reactivate member',
      tone: 'primary',
    },
  },
};

/** Runs a membership action, with a confirmation for suspend/reactivate. Shared by the table row menu and the drawer. */
export function useMemberAction(onNotice: (n: { tone: 'success' | 'danger'; title: string }) => void) {
  const actions = useAccessActions();
  const [pending, setPending] = React.useState<{ action: MemberAction; member: Member } | null>(null);

  const run = async (action: MemberAction, member: Member) => {
    await actions[action].mutateAsync(member.membershipId);
    onNotice({ tone: 'success', title: MEMBER_ACTIONS[action].done(member.displayName) });
  };
  const start = (action: MemberAction, member: Member) => {
    if (MEMBER_ACTIONS[action].confirm) {
      setPending({ action, member });
      return;
    }
    run(action, member).catch((e: unknown) =>
      onNotice({ tone: 'danger', title: actionMessage(e, 'The invitation could not be resent. Nothing was changed.') }),
    );
  };
  const confirm = pending ? MEMBER_ACTIONS[pending.action].confirm : undefined;
  const dialog = (
    <ConfirmDialog
      open={!!pending}
      onOpenChange={(o) => (o ? undefined : setPending(null))}
      title={confirm?.title ?? ''}
      description={
        <p className="m-0">
          <b className="font-semibold text-ink">{pending?.member.displayName}</b> ({pending?.member.email}). {confirm?.body}
        </p>
      }
      confirmLabel={confirm?.label ?? 'Confirm'}
      tone={confirm?.tone}
      onConfirm={async () => {
        if (!pending) return;
        try {
          await run(pending.action, pending.member);
        } catch (e) {
          throw new Error(actionMessage(e, 'The change could not be saved. Nothing was changed.'));
        }
      }}
    />
  );
  return { start, dialog };
}

function AssignmentItem({
  a,
  role,
  ctx,
  member,
  onRevoke,
}: {
  a: Assignment;
  role?: Role;
  ctx: AccessContext;
  member: Member;
  onRevoke: (a: Assignment) => void;
}) {
  const block = !ctx.canManage
    ? 'Requires the permission to manage users and roles.'
    : member.userId === ctx.selfId
      ? 'Your own access can be changed only by another administrator.'
      : undefined;
  return (
    <li className="flex items-start justify-between gap-3 border-b border-line py-3 last:border-b-0">
      <div className="min-w-0">
        <p className="m-0 text-sm font-semibold text-ink">
          {role?.name ?? a.roleCode} <span className="font-normal text-ink-2">({a.roleCode})</span>
        </p>
        <p className="m-0 text-[13px] text-ink">{scopeText(a, ctx.plants)}</p>
        <p className="m-0 text-xs text-ink-2">
          {validityText(a, ctx.timeZone)}
          {a.effective ? '' : ' · not in effect now'}
        </p>
        <p className="m-0 mt-1 truncate text-xs text-ink-2" title={a.grantReason}>
          Reason: {a.grantReason}
        </p>
      </div>
      {block ? (
        <Tooltip content={block}>
          <Button size="sm" variant="ghost" aria-disabled="true" aria-label={`Revoke ${a.roleCode}. ${block}`} onClick={(e) => e.preventDefault()}>
            Revoke
          </Button>
        </Tooltip>
      ) : (
        <Button size="sm" variant="ghost" onClick={() => onRevoke(a)} aria-label={`Revoke ${a.roleCode}, ${scopeText(a, ctx.plants)}`}>
          Revoke
        </Button>
      )}
    </li>
  );
}

/**
 * Member detail (EORA drawer): membership status and actions, unrevoked role assignments inside the viewer's scope, and
 * the assign form. Revocation keeps the assignment as history with revoker, time and the written reason.
 */
export function MemberDrawer({
  member,
  members,
  roles,
  rolesState,
  ctx,
  onClose,
  onNotice,
}: {
  member: Member | null;
  members: readonly Member[];
  roles: readonly Role[];
  rolesState: Loadable;
  ctx: AccessContext;
  onClose: () => void;
  onNotice: (n: { tone: 'success' | 'danger'; title: string }) => void;
}) {
  const { revoke } = useAccessActions();
  const [assigning, setAssigning] = React.useState(false);
  const [revoking, setRevoking] = React.useState<Assignment | null>(null);
  const [notice, setNotice] = React.useState<{ tone: 'success' | 'danger'; title: string } | null>(null);
  const memberActions = useMemberAction((n) => {
    setNotice(n);
    onNotice(n);
  });

  const assignBlock = member ? (memberActionBlock('assign', member, ctx) ?? loadBlock(rolesState, 'Roles')) : undefined;
  const roleById = new Map(roles.map((r) => [r.id, r]));

  return (
    <Drawer open={!!member} onOpenChange={(o) => (o ? undefined : onClose())}>
      {member ? (
        <DrawerContent title={member.displayName}>
          <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto p-5">
            <section aria-label="Membership" className="flex flex-col gap-3">
              <dl className="m-0 grid grid-cols-[120px_1fr] gap-x-3 gap-y-2 text-[13px]">
                <dt className="text-ink-2">E-mail</dt>
                <dd className="m-0 truncate" title={member.email}>
                  {member.email}
                </dd>
                <dt className="text-ink-2">Status</dt>
                <dd className="m-0">
                  <DomainStatusBadge domain="membership" status={member.status} />
                </dd>
              </dl>
              <div className="flex flex-wrap gap-2">
                {(
                  [
                    ['resend', MailPlus],
                    ['suspend', UserX],
                    ['reactivate', UserCheck],
                  ] as const
                ).map(([action, Icon]) => {
                  const block = memberActionBlock(action, member, ctx);
                  const hidden =
                    (action === 'resend' && member.status !== 'INVITED') ||
                    (action === 'reactivate' && member.status !== 'SUSPENDED') ||
                    (action === 'suspend' && member.status === 'SUSPENDED');
                  if (hidden) return null;
                  return block ? (
                    <Tooltip key={action} content={block}>
                      <Button size="sm" aria-disabled="true" aria-label={`${MEMBER_ACTIONS[action].label}. ${block}`} onClick={(e) => e.preventDefault()}>
                        <Icon aria-hidden="true" className="size-4" />
                        {MEMBER_ACTIONS[action].label}
                      </Button>
                    </Tooltip>
                  ) : (
                    <Button
                      key={action}
                      size="sm"
                      variant={action === 'suspend' ? 'destructive' : 'secondary'}
                      onClick={() => memberActions.start(action, member)}
                    >
                      <Icon aria-hidden="true" className="size-4" />
                      {MEMBER_ACTIONS[action].label}
                    </Button>
                  );
                })}
              </div>
              {notice ? <InlineAlert tone={notice.tone} title={notice.title} /> : null}
            </section>

            <section aria-labelledby="member-roles" className="flex flex-col gap-2">
              <div className="flex items-center justify-between gap-3">
                <h3 id="member-roles" className="m-0 text-sm font-semibold">
                  Roles
                </h3>
                {assigning ? null : assignBlock ? (
                  <Tooltip content={assignBlock}>
                    <Button size="sm" aria-disabled="true" aria-label={`Assign role. ${assignBlock}`} onClick={(e) => e.preventDefault()}>
                      <Plus aria-hidden="true" className="size-4" />
                      Assign role
                    </Button>
                  </Tooltip>
                ) : (
                  <Button size="sm" onClick={() => setAssigning(true)}>
                    <Plus aria-hidden="true" className="size-4" />
                    Assign role
                  </Button>
                )}
              </div>
              {rolesState.status === 'error' ? (
                <InlineAlert
                  tone="danger"
                  title="Roles could not be loaded, so no role can be assigned right now."
                  action={
                    <Button size="sm" onClick={rolesState.retry}>
                      Retry
                    </Button>
                  }
                />
              ) : null}
              {assigning && rolesState.status === 'ready' ? (
                <div className="rounded-md border border-line p-4">
                  <AssignRoleForm
                    members={members}
                    roles={roles}
                    plants={ctx.plants}
                    timeZone={ctx.timeZone}
                    memberId={member.membershipId}
                    onCancel={() => setAssigning(false)}
                    onDone={(title) => {
                      setAssigning(false);
                      setNotice({ tone: 'success', title });
                    }}
                  />
                </div>
              ) : null}
              {member.assignments.length === 0 ? (
                <EmptyState
                  className="py-4"
                  title="No role in your scope"
                  description="Without a role the member can sign in but cannot open any module. Assign a role to give access."
                />
              ) : (
                <ul className="m-0 list-none p-0" aria-label={`Roles of ${member.displayName}`}>
                  {member.assignments.map((a) => (
                    <AssignmentItem key={a.id} a={a} role={roleById.get(a.roleId)} ctx={ctx} member={member} onRevoke={setRevoking} />
                  ))}
                </ul>
              )}
              <p className="m-0 text-xs text-ink-2">Only assignments inside the scope you administer are listed.</p>
            </section>
          </div>
          {memberActions.dialog}
          <ConfirmDialog
            open={!!revoking}
            onOpenChange={(o) => (o ? undefined : setRevoking(null))}
            title="Revoke role?"
            description={
              <p className="m-0">
                {member.displayName} loses <b className="font-semibold text-ink">{revoking?.roleCode}</b> ({revoking ? scopeText(revoking, ctx.plants) : ''}) on
                the next request. The assignment stays in the history with your name, the time and the reason.
              </p>
            }
            confirmLabel="Revoke role"
            tone="danger"
            reason={{ label: 'Reason', minLength: 3 }}
            onConfirm={async (reason) => {
              if (!revoking) return;
              try {
                await revoke.mutateAsync({ id: revoking.id, reason: reason ?? '' });
                setNotice({ tone: 'success', title: `${revoking.roleCode} revoked from ${member.displayName}.` });
              } catch (e) {
                throw new Error(actionMessage(e, 'The role could not be revoked. Nothing was changed.'));
              }
            }}
          />
        </DrawerContent>
      ) : null}
    </Drawer>
  );
}

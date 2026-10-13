import type { Assignment, Member, MemberStatus, Role, ScopeType } from './api';

/* Pure presentation rules for Administration → Users / Roles (unit-tested in model.test.ts). */

export type PlantRef = { id: string; code: string; name: string };

export const SCOPE_LABEL: Record<ScopeType, string> = { TENANT: 'Whole tenant', COMPANY: 'Company', PLANT: 'Plant' };

/** "Whole tenant", "Plant · P1 Plant 1" — a plant outside the viewer's workspace keeps a neutral label (no guessing). */
export function scopeText(a: Pick<Assignment, 'scopeType' | 'plantId'>, plants: readonly PlantRef[]): string {
  if (a.scopeType !== 'PLANT') return SCOPE_LABEL[a.scopeType];
  const p = plants.find((x) => x.id === a.plantId);
  return p ? `Plant ${p.code} · ${p.name}` : 'Plant outside your workspace';
}

/** Validity of an unrevoked assignment: effective now, not yet started or ended (`validUntil` passed). */
export function validityText(a: Pick<Assignment, 'effective' | 'validFrom' | 'validUntil'>, now = new Date()): string {
  const until = a.validUntil ? new Date(a.validUntil) : null;
  if (a.effective) return until ? `Until ${fmtDate(until)}` : 'No end date';
  if (until && until <= now) return `Ended ${fmtDate(until)}`;
  return `Starts ${fmtDate(new Date(a.validFrom))}`;
}

export function fmtDate(d: Date): string {
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'Asia/Jakarta' });
}

export type MemberView = 'all' | Exclude<MemberStatus, 'REVOKED'>;
export const MEMBER_VIEWS: { value: MemberView; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'ACTIVE', label: 'Active' },
  { value: 'INVITED', label: 'Invited' },
  { value: 'SUSPENDED', label: 'Suspended' },
];

function matches(m: Member, search: string): boolean {
  const q = search.trim().toLowerCase();
  return !q || m.displayName.toLowerCase().includes(q) || m.email.toLowerCase().includes(q) || m.assignments.some((a) => a.roleCode.toLowerCase().includes(q));
}

/** Search + status view over the member list (status tabs map 1:1 to membership statuses, no invented status). */
export function filterMembers(members: readonly Member[], view: MemberView, search: string): Member[] {
  return members.filter((m) => (view === 'all' || m.status === view) && matches(m, search));
}

export function memberViewCounts(members: readonly Member[], search: string): Record<MemberView, number> {
  const hits = members.filter((m) => matches(m, search));
  return {
    all: hits.length,
    ACTIVE: hits.filter((m) => m.status === 'ACTIVE').length,
    INVITED: hits.filter((m) => m.status === 'INVITED').length,
    SUSPENDED: hits.filter((m) => m.status === 'SUSPENDED').length,
  };
}

export const SELF_REASON = 'Your own access can be changed only by another administrator.';
export const MANAGE_REASON = 'Requires the permission to manage users and roles.';
export const INVITE_REASON = 'Requires the permission to invite users.';

/** Why a member action is unavailable, or undefined when it may be tried (the server still decides, incl. scope). */
export function memberActionBlock(
  action: 'resend' | 'suspend' | 'reactivate' | 'assign',
  member: Pick<Member, 'status' | 'userId'>,
  ctx: { selfId: string; canInvite: boolean; canManage: boolean },
): string | undefined {
  if (action === 'resend') {
    if (!ctx.canInvite) return INVITE_REASON;
    return member.status === 'INVITED' ? undefined : 'Only a pending invitation can be resent.';
  }
  if (!ctx.canManage) return MANAGE_REASON;
  if (member.userId === ctx.selfId) return SELF_REASON;
  if (action === 'suspend') return member.status === 'INVITED' || member.status === 'ACTIVE' ? undefined : 'Only invited or active members can be suspended.';
  if (action === 'reactivate') return member.status === 'SUSPENDED' ? undefined : 'Only suspended members can be reactivated.';
  return member.status === 'INVITED' || member.status === 'ACTIVE' ? undefined : 'Roles can be assigned only to invited or active members.';
}

/** Scopes offered when assigning: the whole tenant or one plant of the viewer's workspace (company scope arrives with M2). */
export type ScopeChoice = { value: 'TENANT' | 'PLANT'; label: string; disabled?: string };

export function scopeChoices(role: Pick<Role, 'defaultScopeType'> | undefined, plants: readonly PlantRef[]): ScopeChoice[] {
  const choices: ScopeChoice[] = [
    { value: 'TENANT', label: SCOPE_LABEL.TENANT },
    { value: 'PLANT', label: 'One plant', disabled: plants.length === 0 ? 'No plant is in your workspace.' : undefined },
  ];
  return choices.map((c) => (role?.defaultScopeType === c.value ? { ...c, label: `${c.label} (suggested)` } : c));
}

/** Initial scope for a role: its template default when offered, otherwise the whole tenant. */
export function defaultScope(role: Pick<Role, 'defaultScopeType'> | undefined, plants: readonly PlantRef[]): 'TENANT' | 'PLANT' {
  return role?.defaultScopeType === 'PLANT' && plants.length > 0 ? 'PLANT' : 'TENANT';
}

/** Members holding `roleId` through an unrevoked assignment, with the scopes they hold it in. */
export function holdersOf(roleId: string, members: readonly Member[]): { member: Member; assignments: Assignment[] }[] {
  return members.flatMap((m) => {
    const assignments = m.assignments.filter((a) => a.roleId === roleId);
    return assignments.length ? [{ member: m, assignments }] : [];
  });
}

/** Permission codes grouped by module prefix (`core.users_roles.view` → `core`), for the role detail. */
export function groupPermissions(codes: readonly string[]): { module: string; codes: string[] }[] {
  const map = new Map<string, string[]>();
  for (const c of codes) {
    const m = c.split('.')[0] ?? c;
    map.set(m, [...(map.get(m) ?? []), c]);
  }
  return [...map.entries()].map(([module, list]) => ({ module, codes: list }));
}

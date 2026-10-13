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

/** Validity of an unrevoked assignment: effective now, not yet started or ended (`validUntil` passed), in the tenant zone. */
export function validityText(a: Pick<Assignment, 'effective' | 'validFrom' | 'validUntil'>, timeZone: string, now = new Date()): string {
  const until = a.validUntil ? new Date(a.validUntil) : null;
  if (a.effective) return until ? `Until ${fmtDate(until, timeZone)}` : 'No end date';
  if (until && until <= now) return `Ended ${fmtDate(until, timeZone)}`;
  return `Starts ${fmtDate(new Date(a.validFrom), timeZone)}`;
}

export function fmtDate(d: Date, timeZone: string): string {
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone });
}

/** Offset of `timeZone` from UTC at instant `ms`, in milliseconds (local = UTC + offset). */
function zoneOffset(ms: number, timeZone: string): number {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-US', {
      timeZone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
      .formatToParts(new Date(ms))
      .map((p) => [p.type, p.value]),
  );
  const local = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
  return local - Math.floor(ms / 1000) * 1000;
}

/**
 * The last second of calendar day `date` (YYYY-MM-DD) in the tenant's IANA zone (D-044), as an ISO instant. The API
 * compares `validUntil` as an instant, so the day boundary must be the tenant's, never the browser's or a fixed offset.
 */
export function endOfDayIn(date: string, timeZone: string): string {
  const [y, m, d] = date.split('-').map(Number) as [number, number, number];
  const wall = Date.UTC(y, m - 1, d, 23, 59, 59);
  let instant = wall - zoneOffset(wall, timeZone);
  instant = wall - zoneOffset(instant, timeZone); // second pass: offset at the result (DST change on that day)
  return new Date(instant).toISOString();
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

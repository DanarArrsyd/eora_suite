import { describe, expect, it } from 'vitest';
import type { Assignment, Member } from './api';
import {
  defaultScope,
  endOfDayIn,
  filterMembers,
  groupPermissions,
  holdersOf,
  memberActionBlock,
  memberViewCounts,
  scopeChoices,
  scopeText,
  SELF_REASON,
  validityText,
} from './model';

const PLANTS = [{ id: 'p1', code: 'P1', name: 'Plant 1' }];

function assignment(over: Partial<Assignment> = {}): Assignment {
  return {
    id: 'a1',
    membershipId: 'm1',
    roleId: 'r-qci',
    roleCode: 'QCI',
    scopeType: 'PLANT',
    companyId: null,
    plantId: 'p1',
    validFrom: '2026-10-01T00:00:00+07:00',
    validUntil: null,
    grantedBy: 'admin',
    grantReason: 'ticket 12',
    revokedAt: null,
    revokeReason: null,
    effective: true,
    ...over,
  };
}

function member(over: Partial<Member> = {}): Member {
  return { membershipId: 'm1', userId: 'u1', displayName: 'Ana Putri', email: 'ana@example.test', status: 'ACTIVE', assignments: [assignment()], ...over };
}

describe('admin presentation rules', () => {
  it('names scopes without guessing plants outside the workspace', () => {
    expect(scopeText(assignment(), PLANTS)).toBe('Plant P1 · Plant 1');
    expect(scopeText(assignment({ plantId: 'elsewhere' }), PLANTS)).toBe('Plant outside your workspace');
    expect(scopeText(assignment({ scopeType: 'TENANT', plantId: null }), PLANTS)).toBe('Whole tenant');
  });

  it('describes validity: open-ended, ending, ended, not yet started', () => {
    const now = new Date('2026-10-14T00:00:00Z');
    expect(validityText(assignment(), 'Asia/Jakarta', now)).toBe('No end date');
    expect(validityText(assignment({ validUntil: '2026-12-31T23:59:59+07:00' }), 'Asia/Jakarta', now)).toBe('Until 31 Dec 2026');
    expect(validityText(assignment({ effective: false, validUntil: '2026-10-01T23:59:59+07:00' }), 'Asia/Jakarta', now)).toBe('Ended 01 Oct 2026');
    expect(validityText(assignment({ effective: false, validFrom: '2026-11-01T00:00:00+07:00' }), 'Asia/Jakarta', now)).toBe('Starts 01 Nov 2026');
  });

  it('ends a role at the last second of the chosen day in the tenant zone, DST included', () => {
    expect(endOfDayIn('2026-12-31', 'Asia/Jakarta')).toBe('2026-12-31T16:59:59.000Z');
    expect(endOfDayIn('2026-12-31', 'Europe/Berlin')).toBe('2026-12-31T22:59:59.000Z');
    expect(endOfDayIn('2026-03-08', 'America/New_York')).toBe('2026-03-09T03:59:59.000Z'); // EDT starts that day
    expect(validityText(assignment({ validUntil: '2026-12-31T22:59:59.000Z' }), 'Europe/Berlin', new Date('2026-10-14T00:00:00Z'))).toBe('Until 31 Dec 2026');
  });

  it('filters by membership status and searches name, e-mail and role code; counts follow the search', () => {
    const list = [member(), member({ membershipId: 'm2', displayName: 'Budi', email: 'budi@x.test', status: 'INVITED', assignments: [] })];
    expect(filterMembers(list, 'INVITED', '').map((m) => m.membershipId)).toEqual(['m2']);
    expect(filterMembers(list, 'all', 'qci').map((m) => m.membershipId)).toEqual(['m1']);
    expect(filterMembers(list, 'all', 'BUDI@').map((m) => m.membershipId)).toEqual(['m2']);
    expect(memberViewCounts(list, '')).toEqual({ all: 2, ACTIVE: 1, INVITED: 1, SUSPENDED: 0 });
  });

  it('blocks actions with the reason: permission, own access (SoD-06), status', () => {
    const ctx = { selfId: 'u9', canInvite: true, canManage: true };
    expect(memberActionBlock('suspend', member(), ctx)).toBeUndefined();
    expect(memberActionBlock('suspend', member({ userId: 'u9' }), ctx)).toBe(SELF_REASON);
    expect(memberActionBlock('suspend', member(), { ...ctx, canManage: false })).toMatch(/manage users and roles/);
    expect(memberActionBlock('reactivate', member(), ctx)).toMatch(/Only suspended/);
    expect(memberActionBlock('resend', member({ status: 'INVITED' }), ctx)).toBeUndefined();
    expect(memberActionBlock('resend', member(), ctx)).toMatch(/pending invitation/);
    expect(memberActionBlock('assign', member({ status: 'SUSPENDED' }), ctx)).toMatch(/invited or active/);
  });

  it('suggests the template scope and offers a plant only when the workspace has one', () => {
    expect(defaultScope({ defaultScopeType: 'PLANT' }, PLANTS)).toBe('PLANT');
    expect(defaultScope({ defaultScopeType: 'PLANT' }, [])).toBe('TENANT');
    expect(scopeChoices({ defaultScopeType: 'TENANT' }, PLANTS).map((c) => c.label)).toEqual(['Whole tenant (suggested)', 'One plant']);
    expect(scopeChoices(undefined, []).find((c) => c.value === 'PLANT')?.disabled).toBeTruthy();
  });

  it('lists holders of a role and groups permissions by module', () => {
    expect(holdersOf('r-qci', [member(), member({ membershipId: 'm2', assignments: [] })]).map((h) => h.member.membershipId)).toEqual(['m1']);
    expect(groupPermissions(['core.audit.view', 'core.users_roles.view', 'quality.inspection.post'])).toEqual([
      { module: 'core', codes: ['core.audit.view', 'core.users_roles.view'] },
      { module: 'quality', codes: ['quality.inspection.post'] },
    ]);
  });
});

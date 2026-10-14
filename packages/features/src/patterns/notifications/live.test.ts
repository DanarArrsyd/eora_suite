import { describe, expect, it } from 'vitest';
import { categoryOf, fromApi } from './live';

const row = {
  id: 'n1',
  kind: 'access.sensitive_role_granted',
  severity: 'WARNING' as const,
  actionRequired: false,
  title: 'Sensitive role granted',
  body: null,
  deepLink: '/admin/roles',
  createdAt: '2026-10-14T01:00:00Z',
  readAt: null,
};

describe('live inbox mapping', () => {
  it('maps the API row to the inbox item', () => {
    expect(fromApi(row)).toEqual({
      id: 'n1',
      category: 'system',
      title: 'Sensitive role granted',
      body: '',
      createdAt: '2026-10-14T01:00:00Z',
      read: false,
      actionable: false,
      severity: 'WARNING',
      href: '/admin/roles',
      module: 'admin',
    });
  });

  it('follows only same-origin paths as deep links', () => {
    expect(fromApi({ ...row, deepLink: 'https://evil.example/x' }).href).toBeUndefined();
    expect(fromApi({ ...row, deepLink: '//evil.example/x' }).href).toBeUndefined();
  });

  it('derives the category from the kind domain', () => {
    expect(categoryOf('approval.requested')).toBe('approval');
    expect(categoryOf('quality.inspection_due')).toBe('qc');
    expect(categoryOf('reconciliation.case_opened')).toBe('variance');
    expect(categoryOf('outbox.dead')).toBe('system');
  });
});

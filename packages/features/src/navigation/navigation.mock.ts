import type { NavigationSource, RoleKey } from './types';

/**
 * MOCK navigation source (doc 22 §2). Replaces GET /api/v1/me/navigation until the API contract is approved.
 * Counts are SAMPLE values for prototype review — never operational data.
 */
const ALLOWED: Record<RoleKey, readonly string[] | '*'> = {
  'ppic-planner': ['dashboard', 'demand', 'ppic', 'materials', 'production', 'quality', 'inventory', 'warehouse', 'delivery', 'traceability', 'analytics'],
  'quality-inspector': ['dashboard', 'quality', 'traceability'],
  'materials-pic': ['dashboard', 'materials', 'inventory', 'traceability'],
  'company-super-admin': '*',
};

const COUNTS: Record<RoleKey, ReturnType<NavigationSource['badgeCounts']>> = {
  'ppic-planner': { approvals: 1, variance: 1, tasks: 2, bell: 2 },
  'quality-inspector': { qc: 4, tasks: 1, bell: 1 },
  'materials-pic': { tasks: 2, bell: 1 },
  'company-super-admin': { approvals: 2, variance: 3, bell: 3 },
};

export const mockNavigationSource: NavigationSource = {
  allowedModules: (role) => ALLOWED[role],
  badgeCounts: (role) => COUNTS[role],
};

export const ROLE_LABELS: Record<RoleKey, string> = {
  'ppic-planner': 'PPIC Planner',
  'quality-inspector': 'Quality Inspector',
  'materials-pic': 'Materials PIC',
  'company-super-admin': 'Company Super Admin',
};

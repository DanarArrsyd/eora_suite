import type { RoleKey } from '../../navigation';
import type { InboxNotification, NotificationSource } from './types';

/**
 * MOCK inbox (doc 22 §2) — SAMPLE items for UI review, never operational data.
 * "Needs action" counts match the mock navigation bell counts per role.
 */
const SEED: Record<RoleKey, InboxNotification[]> = {
  'ppic-planner': [
    {
      id: 'n-101',
      category: 'approval',
      title: 'Approval requested: stock adjustment ADJ-0192',
      body: 'Sample: 12.000 kg physical stock discrepancy on lot C-09 after approved count. Independent approval required.',
      createdAt: '2026-10-08T02:12:00Z',
      read: false,
      actionable: true,
      href: '/dashboard/approvals',
      module: 'dashboard',
    },
    {
      id: 'n-102',
      category: 'variance',
      title: 'Variance case RC-0031 above tolerance',
      body: 'Sample: Gross Yield Variance 25 pcs (3.16 %) on WO-2210, provisional. Investigation needed — not a confirmed loss.',
      createdAt: '2026-10-08T01:40:00Z',
      read: false,
      actionable: true,
      href: '/ppic/variance-investigation',
      module: 'ppic',
    },
    {
      id: 'n-103',
      category: 'system',
      title: 'Export ready: Raw material lots (Excel)',
      body: 'Sample: export job EXP-5521 finished with the filters you used.',
      createdAt: '2026-10-08T00:55:00Z',
      read: false,
      actionable: false,
    },
    {
      id: 'n-104',
      category: 'qc',
      title: 'QC released 300 pcs of PART-A',
      body: 'Sample: inspection lot IL-0412 — 300 accepted, 200 still Pending QC.',
      createdAt: '2026-10-07T09:20:00Z',
      read: true,
      actionable: false,
      href: '/quality/inspection-lots',
      module: 'quality',
    },
    {
      id: 'n-105',
      category: 'task',
      title: 'Delivery schedule revision: CPO-1001 rev 2',
      body: 'Sample: W43 changed 250 → 300 pcs; W44 cancelled. Pegged WO-2214 needs review.',
      createdAt: '2026-10-07T07:05:00Z',
      read: true,
      actionable: false,
      href: '/demand/delivery-schedules',
      module: 'demand',
    },
  ],
  'quality-inspector': [
    {
      id: 'n-201',
      category: 'qc',
      title: '4 inspection lots waiting',
      body: 'Sample: oldest lot IL-0415 has waited 3 h 10 min.',
      createdAt: '2026-10-08T02:30:00Z',
      read: false,
      actionable: true,
      href: '/quality/pending-qc',
      module: 'quality',
    },
    {
      id: 'n-202',
      category: 'variance',
      title: 'Variance case RC-0031 mentions NG rework',
      body: 'Sample: shared for information. Opening the case requires PPIC access.',
      createdAt: '2026-10-08T01:41:00Z',
      read: true,
      actionable: false,
      href: '/ppic/variance-investigation',
      module: 'ppic',
    },
  ],
  'materials-pic': [
    {
      id: 'n-301',
      category: 'task',
      title: 'Issue request MI-0088 for WO-2210',
      body: 'Sample: 80 sheet SPHC 2.0 requested to WC-PRESS3.',
      createdAt: '2026-10-08T02:05:00Z',
      read: false,
      actionable: true,
      href: '/dashboard/my-tasks',
      module: 'dashboard',
    },
    {
      id: 'n-302',
      category: 'system',
      title: 'Lot C-12 received as Blocked',
      body: 'Sample: mill certificate pending; manual release needed before issue.',
      createdAt: '2026-10-08T00:10:00Z',
      read: true,
      actionable: false,
      href: '/inventory/raw-material',
      module: 'inventory',
    },
  ],
  'company-super-admin': [
    {
      id: 'n-401',
      category: 'approval',
      title: 'Approval requested: stock adjustment ADJ-0192',
      body: 'Sample: 12.000 kg physical stock discrepancy on lot C-09.',
      createdAt: '2026-10-08T02:12:00Z',
      read: false,
      actionable: true,
      href: '/dashboard/approvals',
      module: 'dashboard',
    },
    {
      id: 'n-402',
      category: 'approval',
      title: 'Approval requested: shipment reversal SHP-0042',
      body: 'Sample: reversal of 100 pcs on DSL-1 requested by Delivery.',
      createdAt: '2026-10-08T01:58:00Z',
      read: false,
      actionable: true,
      href: '/dashboard/approvals',
      module: 'dashboard',
    },
    {
      id: 'n-403',
      category: 'variance',
      title: 'Variance case RC-0031 above tolerance',
      body: 'Sample: Gross Yield Variance 25 pcs (3.16 %), provisional.',
      createdAt: '2026-10-08T01:40:00Z',
      read: false,
      actionable: true,
      href: '/ppic/variance-investigation',
      module: 'ppic',
    },
  ],
};

const state: Record<string, InboxNotification[]> = {};
const wait = (ms: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new DOMException('Aborted', 'AbortError'));
    });
  });

export const mockNotificationSource: NotificationSource = {
  isMock: true,
  async list(role, signal) {
    await wait(150, signal);
    state[role] ??= SEED[role].map((n) => ({ ...n }));
    const items = state[role].map((n) => ({ ...n }));
    return { items, unread: items.filter((n) => !n.read).length, actionRequired: items.filter((n) => n.actionable).length };
  },
  async markRead(role, ids) {
    await wait(80);
    state[role] ??= SEED[role].map((n) => ({ ...n }));
    state[role] = state[role].map((n) => (ids.includes(n.id) ? { ...n, read: true } : n));
  },
  async markAllRead(role) {
    await wait(80);
    state[role] ??= SEED[role].map((n) => ({ ...n }));
    state[role] = state[role].map((n) => ({ ...n, read: true }));
  },
};

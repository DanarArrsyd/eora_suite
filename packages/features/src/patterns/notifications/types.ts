import type { RoleKey } from '../../navigation';

export type NotificationCategory = 'approval' | 'qc' | 'variance' | 'task' | 'system';

/** Persisted inbox item (doc 07): delivered via outbox; push is optional; deep links re-check permission on open. */
export type InboxNotification = {
  id: string;
  category: NotificationCategory;
  title: string;
  body: string;
  createdAt: string; // ISO
  read: boolean;
  /** Stays until the underlying task is done — reading does not clear it (D-025 actionable badges). */
  actionable: boolean;
  /** Server severity; WARNING / CRITICAL are labelled in the list (colour never alone). */
  severity?: 'INFO' | 'WARNING' | 'CRITICAL';
  href?: string;
  /** Module whose access is required to open `href`. */
  module?: string;
};

/** One inbox read: the latest items plus totals over the whole inbox (the list may be truncated, the totals never are). */
export type InboxPage = { items: InboxNotification[]; unread: number; actionRequired: number };

export interface NotificationSource {
  readonly isMock: boolean;
  list(role: RoleKey, signal?: AbortSignal): Promise<InboxPage>;
  markRead(role: RoleKey, ids: string[]): Promise<void>;
  /** Every unread notification of the inbox, not only the loaded ones. */
  markAllRead(role: RoleKey): Promise<void>;
}

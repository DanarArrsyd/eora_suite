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
  href?: string;
  /** Module whose access is required to open `href`. */
  module?: string;
};

export interface NotificationSource {
  readonly isMock: boolean;
  list(role: RoleKey, signal?: AbortSignal): Promise<InboxNotification[]>;
  markRead(role: RoleKey, ids: string[]): Promise<void>;
}

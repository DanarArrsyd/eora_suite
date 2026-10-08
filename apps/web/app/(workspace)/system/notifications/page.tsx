import type { Metadata } from 'next';
import { NotificationCenter, EnterprisePage } from '@eora/features/patterns';

export const metadata: Metadata = { title: 'Notifications' };

export default function NotificationsPage() {
  return (
    <EnterprisePage
      title="Notifications"
      description="Persisted inbox. Items that need action stay until the task is done; deep links re-check your access when opened."
      mock
    >
      <section aria-label="Inbox" className="max-w-[960px] rounded-lg border border-line bg-surface shadow-[var(--shadow-card)]">
        <NotificationCenter variant="page" />
      </section>
    </EnterprisePage>
  );
}

import type { paths } from '@eora/api-client';
import { api, call } from '../../auth/api';
import type { InboxNotification, NotificationCategory, NotificationSource } from './types';

type ApiNotification = paths['/inbox']['get']['responses'][200]['content']['application/json']['data'][number];

/** Notification kind (`<domain>.<event>`) → inbox category icon. Unknown domains stay "system". */
export function categoryOf(kind: string): NotificationCategory {
  const domain = kind.split('.')[0];
  if (domain === 'approval') return 'approval';
  if (domain === 'quality' || domain === 'qc') return 'qc';
  if (domain === 'variance' || domain === 'reconciliation') return 'variance';
  if (domain === 'task') return 'task';
  return 'system';
}

/** Maps the API row; a deep link is only followed when it is a same-origin path (never an absolute URL). */
export function fromApi(n: ApiNotification): InboxNotification {
  const href = n.deepLink && n.deepLink.startsWith('/') && !n.deepLink.startsWith('//') ? n.deepLink : undefined;
  return {
    id: n.id,
    category: categoryOf(n.kind),
    title: n.title,
    body: n.body ?? '',
    createdAt: n.createdAt,
    read: n.readAt !== null,
    actionable: n.actionRequired,
    severity: n.severity,
    href,
    module: href?.split('/')[1],
  };
}

/**
 * Live inbox of the signed-in user in the active tenant (`GET /inbox`, `POST /inbox/read`; doc 07, doc 24 §7). The role
 * argument of the source contract is unused: the server knows the user and RLS returns only their notifications.
 */
export const liveNotificationSource: NotificationSource = {
  isMock: false,
  async list(_role, signal) {
    const res = await call(() => api.GET('/inbox', { signal }));
    return res.data.map(fromApi);
  },
  async markRead(_role, ids) {
    await call(() => api.POST('/inbox/read', { body: { ids } }));
  },
};

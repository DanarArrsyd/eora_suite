'use client';

import { useQuery } from '@tanstack/react-query';
import { api, call } from '@eora/features/auth';
import { InlineAlert } from '@eora/ui';

/** Documents that wait for a second person (D-129); the names match the API's `kind`. */
export type ApprovalKind =
  | 'routing_version'
  | 'bom_version'
  | 'bom_routing_map'
  | 'item_weight'
  | 'conversion_profile_version'
  | 'mps_version'
  | 'work_order'
  | 'stock_adjustment'
  | 'reversal'
  | 'quality_release'
  | 'wo_close_exception'
  | 'period_close'
  | 'period_reopen';

type ApprovalStatus = {
  waiting: boolean;
  requestedBy: string | null;
  roles: { code: string; name: string }[];
  approvers: { name: string; roles: string[] }[];
};

function list(words: string[], last: string): string {
  if (words.length < 2) return words[0] ?? '';
  return `${words.slice(0, -1).join(', ')} ${last} ${words[words.length - 1]}`;
}

/**
 * Who decides a document that waits for a second person, by role and by name, so nobody guesses whom to ask (D-129).
 * Shown only while it waits; the people named were told in their inbox when it was submitted.
 */
export function ApprovalNotice({ kind, id, waiting, className }: { kind: ApprovalKind; id: string; waiting: boolean; className?: string }) {
  const query = useQuery({
    queryKey: ['approvals', kind, id],
    enabled: waiting,
    queryFn: async () => (await call(() => api.GET('/approvals/{kind}/{id}', { params: { path: { kind, id } } }))) as unknown as { data: ApprovalStatus },
  });
  const status = query.data?.data;
  if (!waiting || !status?.waiting) return null;
  const roles = list(
    status.roles.map((r) => r.name),
    'or',
  );
  const names = status.approvers.map((a) => a.name);
  return names.length ? (
    <InlineAlert tone="info" title={`Waiting for approval by ${roles || 'an approver'}`} className={className}>
      {`Approvers: ${list(names, 'and')}. They were notified in their inbox.`}
    </InlineAlert>
  ) : (
    <InlineAlert tone="warning" title="No one can approve this yet" className={className}>
      {`Ask a tenant administrator to give someone ${roles ? `the ${roles} role` : 'an approver role'}. You cannot approve your own request.`}
    </InlineAlert>
  );
}

import * as React from 'react';
import { StatusBadge } from '@eora/ui';

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';
type Entry = { label: string; tone: Tone };

/**
 * EoraStatusBadge catalogue — one mapping per status domain so every module shows the same label and tone.
 * Stock statuses (DD-01) and document states (doc 16) are separate domains (ADR-0002) and must not be mixed.
 * Adding a status requires a decision-register / doc 16 entry first.
 */
export const STATUS_CATALOGUE = {
  stock: {
    UNRESTRICTED: { label: 'Unrestricted', tone: 'success' },
    PENDING_QC: { label: 'Pending QC', tone: 'info' },
    QC_RELEASED: { label: 'QC released', tone: 'success' },
    HOLD: { label: 'Hold', tone: 'warning' },
    REWORK: { label: 'Rework', tone: 'warning' },
    REJECTED: { label: 'Rejected', tone: 'danger' },
    BLOCKED: { label: 'Blocked', tone: 'danger' },
  },
  document: {
    DRAFT: { label: 'Draft', tone: 'neutral' },
    SUBMITTED: { label: 'Submitted', tone: 'info' },
    APPROVED: { label: 'Approved', tone: 'success' },
    RELEASED: { label: 'Released', tone: 'info' },
    POSTED: { label: 'Posted', tone: 'success' },
    REVERSED: { label: 'Reversed', tone: 'neutral' },
    CANCELLED: { label: 'Cancelled', tone: 'neutral' },
    CLOSED: { label: 'Closed', tone: 'neutral' },
  },
  /**
   * Versioned engineering master — routing versions, BOM revisions (doc 26 §2.4a): edited as Draft, approved by a second
   * person. Conversion data (net weights, conversion profiles, D-080) is validated instead, and retired by hand.
   */
  version: {
    DRAFT: { label: 'Draft', tone: 'neutral' },
    SUBMITTED: { label: 'Submitted', tone: 'info' },
    REJECTED: { label: 'Rejected', tone: 'warning' },
    APPROVED: { label: 'Approved', tone: 'success' },
    SUPERSEDED: { label: 'Superseded', tone: 'neutral' },
    VALIDATED: { label: 'Validated', tone: 'success' },
    RETIRED: { label: 'Retired', tone: 'neutral' },
  },
  /** Delivery Schedule Line (doc 16 §12, D-034). */
  schedule: {
    DRAFT: { label: 'Draft', tone: 'neutral' },
    FIRM: { label: 'Firm', tone: 'info' },
    PARTIALLY_FULFILLED: { label: 'Partially fulfilled', tone: 'warning' },
    FULFILLED: { label: 'Fulfilled', tone: 'success' },
    SUPERSEDED: { label: 'Superseded', tone: 'neutral' },
    CANCELLED: { label: 'Cancelled', tone: 'neutral' },
  },
  /** Master-data record state (doc 29): whether a plant resource takes new use; MAINTENANCE for tooling only. */
  master: {
    ACTIVE: { label: 'Active', tone: 'success' },
    INACTIVE: { label: 'Inactive', tone: 'neutral' },
    MAINTENANCE: { label: 'In maintenance', tone: 'warning' },
  },
  /** Tenant membership (doc 25 §2.6, doc 17): access to the workspace, independent of role assignments. */
  membership: {
    INVITED: { label: 'Invited', tone: 'info' },
    ACTIVE: { label: 'Active', tone: 'success' },
    SUSPENDED: { label: 'Suspended', tone: 'warning' },
    REVOKED: { label: 'Revoked', tone: 'neutral' },
  },
  /** Variance indicator finality (ADR-0005). Never labelled as loss. */
  indicator: {
    NOT_CALCULABLE: { label: 'Not calculable', tone: 'neutral' },
    PROVISIONAL: { label: 'Provisional', tone: 'warning' },
    FINAL: { label: 'Final', tone: 'success' },
  },
} as const satisfies Record<string, Record<string, Entry>>;

export type StatusDomain = keyof typeof STATUS_CATALOGUE;
export type StatusOf<D extends StatusDomain> = keyof (typeof STATUS_CATALOGUE)[D];

export function statusEntry<D extends StatusDomain>(domain: D, status: StatusOf<D>): Entry {
  return (STATUS_CATALOGUE[domain] as Record<string, Entry>)[status as string] ?? { label: String(status), tone: 'neutral' };
}

/** Text is always rendered; colour is supplementary (WCAG 1.4.1). */
export function DomainStatusBadge<D extends StatusDomain>({ domain, status, className }: { domain: D; status: StatusOf<D>; className?: string }) {
  const e = statusEntry(domain, status);
  return (
    <StatusBadge tone={e.tone} className={className} data-status={String(status)}>
      {e.label}
    </StatusBadge>
  );
}

'use client';

import * as React from 'react';
import { MockDataLabel, cn } from '@eora/ui';
import { PageHeader } from '../shell/page-header';
import { StatusTabs, type StatusTabItem } from './status-tabs';

type EnterprisePageProps = {
  title: React.ReactNode;
  description?: React.ReactNode;
  /** As-of, scope and similar meta line items. */
  meta?: React.ReactNode;
  /** One primary action at most (constitution). */
  actions?: React.ReactNode;
  /** True when any data on the page comes from a mock adapter (doc 22 §2). */
  mock?: boolean;
  children: React.ReactNode;
  className?: string;
};

/**
 * EoraEnterprisePage — page frame for list/workspace pages (EORA-PAGE-TEMPLATES §1).
 * Breadcrumb lives in the topbar; this renders title → description → actions, then the page body.
 */
export function EnterprisePage({ title, description, meta, actions, mock, children, className }: EnterprisePageProps) {
  return (
    <div className={cn('p-6 max-md:p-4', className)}>
      <PageHeader
        title={
          <>
            {title}
            {mock ? <MockDataLabel /> : null}
          </>
        }
        description={description}
        meta={meta}
        actions={actions}
      />
      {children}
    </div>
  );
}

type ListWorkspaceProps = {
  /** Accessible name of the list region, e.g. "Raw material lots". */
  label: string;
  statusTabs?: { label: string; items: StatusTabItem[]; value: string; onValueChange: (v: string) => void; note?: React.ReactNode };
  toolbar: React.ReactNode;
  filterBar?: React.ReactNode;
  activeFilters?: React.ReactNode;
  bulkBar?: React.ReactNode;
  children: React.ReactNode;
  footnote?: React.ReactNode;
  pagination?: React.ReactNode;
};

/**
 * EoraListWorkspace — fixed order inside the list card: status tabs → toolbar → filter bar → active filters →
 * bulk bar → content → footnote → pagination. Pages pass configuration only; they never reorder these regions.
 */
export function ListWorkspace({ label, statusTabs, toolbar, filterBar, activeFilters, bulkBar, children, footnote, pagination }: ListWorkspaceProps) {
  const body = (
    <>
      {statusTabs?.note ? <p className="m-0 px-5 pt-2 text-xs text-ink-2 max-md:px-4">{statusTabs.note}</p> : null}
      <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-3 max-md:px-4">{toolbar}</div>
      {filterBar}
      {activeFilters}
      {bulkBar}
      {children}
      {footnote ? <p className="m-0 border-t border-line px-5 py-2 text-xs text-ink-2 max-md:px-4">{footnote}</p> : null}
      {pagination}
    </>
  );
  return (
    <section aria-label={label} className="min-w-0 rounded-lg border border-line bg-surface shadow-[var(--shadow-card)]">
      {statusTabs ? (
        <StatusTabs label={statusTabs.label} items={statusTabs.items} value={statusTabs.value} onValueChange={statusTabs.onValueChange}>
          {body}
        </StatusTabs>
      ) : (
        body
      )}
    </section>
  );
}

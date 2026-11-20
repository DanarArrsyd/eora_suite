'use client';

import * as React from 'react';
import { ArrowLeft } from 'lucide-react';
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

type FormPageProps = {
  title: React.ReactNode;
  description?: React.ReactNode;
  /** Where the page came from: a link back, told first so an unsaved form can ask before leaving. */
  back: { label: string; href: string; onNavigate?: (event: React.MouseEvent<HTMLAnchorElement>) => void };
  /** Read-only facts of the record being changed (codes, parents, status), shown beside the form. */
  facts?: { label: string; value: React.ReactNode }[];
  /** More context beside the form (help, a live summary). */
  aside?: React.ReactNode;
  /** Above the form card: a notice about the record or the result of the last save. */
  notice?: React.ReactNode;
  /** `wide` lets the content use the full page width (results tables); default keeps line lengths readable. */
  width?: 'default' | 'wide';
  children: React.ReactNode;
  className?: string;
};

/**
 * EoraFormPage — the one page frame for every input form (D-140, EORA-PAGE-TEMPLATES §6): a link back to the list,
 * title and description, the form in one card (fields in `FormGrid`, actions in a sticky bar at the card's foot) and,
 * on wide screens, the record's facts beside it. Small confirmations stay in a dialog; detail views stay in a drawer.
 */
export function FormPage({ title, description, back, facts, aside, notice, width = 'default', children, className }: FormPageProps) {
  const side = (facts && facts.length > 0) || aside;
  return (
    <div className={cn('p-6 max-md:p-4', className)}>
      <a
        href={back.href}
        onClick={back.onNavigate}
        data-form-back=""
        className="mb-3 inline-flex min-h-9 items-center gap-1.5 rounded-md text-[13px] font-medium text-link no-underline hover:underline focus-visible:outline-2 focus-visible:outline-ring max-md:min-h-11"
      >
        <ArrowLeft aria-hidden="true" className="size-4" />
        {back.label}
      </a>
      <PageHeader title={title} description={description} />
      {notice ? <div className="mb-4">{notice}</div> : null}
      <div className={cn('grid items-start gap-6', side && 'lg:grid-cols-[minmax(0,1fr)_300px]', width === 'default' && !side && 'max-w-4xl')}>
        <section
          aria-label={typeof title === 'string' ? title : undefined}
          className="min-w-0 rounded-lg border border-line bg-surface p-5 shadow-[var(--shadow-card)] max-md:p-4"
        >
          {children}
        </section>
        {side ? (
          <aside className="flex min-w-0 flex-col gap-4 max-lg:order-first lg:sticky lg:top-4">
            {facts && facts.length > 0 ? (
              <section aria-label="Record" className="rounded-lg border border-line bg-surface p-4 shadow-[var(--shadow-card)]">
                <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-[13px]">
                  {facts.map((f) => (
                    <React.Fragment key={f.label}>
                      <dt className="text-ink-2">{f.label}</dt>
                      <dd className="m-0 min-w-0 break-words text-ink">{f.value}</dd>
                    </React.Fragment>
                  ))}
                </dl>
              </section>
            ) : null}
            {aside}
          </aside>
        ) : null}
      </div>
    </div>
  );
}

/** Fields of a form page: two columns from tablet width, one on phones; `FormGrid.Wide` spans both columns. */
export function FormGrid({ children, className }: { children: React.ReactNode; className?: string }) {
  return <div className={cn('grid gap-x-5 gap-y-4 md:grid-cols-2', className)}>{children}</div>;
}

export function FormGridWide({ children }: { children: React.ReactNode }) {
  return <div className="min-w-0 md:col-span-2">{children}</div>;
}

/**
 * The action bar of a form page that is not an `EoraForm` (Generate SPK, New SPK): the same sticky foot of the card as
 * `EoraForm stickyActions`, secondary actions first, the primary last.
 */
export function FormActions({ children }: { children: React.ReactNode }) {
  return (
    <div className="sticky bottom-0 z-10 -mx-5 mt-4 -mb-5 flex flex-wrap justify-end gap-2 rounded-b-lg border-t border-line bg-surface px-5 py-3 max-md:-mx-4 max-md:-mb-4 max-md:px-4">
      {children}
    </div>
  );
}

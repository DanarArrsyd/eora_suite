import * as React from 'react';
import Link from 'next/link';
import { ArrowRight, Info } from 'lucide-react';
import { cn } from '@eora/ui';
import type { KpiDefinition } from '../contracts';
import { fmtQty } from './format';

const TONE = { danger: 'border-t-stat-err', warning: 'border-t-stat-warn', neutral: 'border-t-line-strong' } as const;

/**
 * Decision KPI card (EORA-PAGE-TEMPLATES §4): label, value + unit, definition, source domain, calculation basis, meaning,
 * as-of and a drill link. No invented deltas or trend arrows.
 */
export function KpiCard({ kpi, asOf }: { kpi: KpiDefinition; asOf: React.ReactNode }) {
  const id = React.useId();
  return (
    <article
      aria-labelledby={`${id}-l`}
      className={cn('flex min-w-0 flex-col rounded-lg border border-t-4 border-line bg-surface p-4 shadow-[var(--shadow-card)]', TONE[kpi.tone])}
    >
      <h2 id={`${id}-l`} className="m-0 text-[13px] font-semibold text-ink-2">
        {kpi.label}
      </h2>
      <p className="m-0 mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1">
        <span className="text-[28px] leading-[34px] font-semibold tabular-nums">{fmtQty(kpi.value, kpi.uom)}</span>
        <span className="text-sm text-ink-2">{kpi.uom}</span>
        {kpi.secondary ? (
          <span className="text-sm text-ink-2">
            · <span className="font-semibold text-ink tabular-nums">{fmtQty(kpi.secondary.value, kpi.secondary.uom)}</span> {kpi.secondary.uom}
          </span>
        ) : null}
      </p>
      <p className="m-0 mt-1 text-[13px] text-ink">{kpi.definition}</p>
      <details className="mt-2 text-xs text-ink-2">
        <summary className="inline-flex min-h-6 cursor-pointer items-center gap-1 font-medium text-link max-md:min-h-11">
          <Info aria-hidden="true" className="size-3.5" />
          Definition & basis
        </summary>
        <dl className="m-0 mt-1.5 grid gap-1">
          <div>
            <dt className="inline font-semibold">Source: </dt>
            <dd className="inline">{kpi.sourceDomain}</dd>
          </div>
          <div>
            <dt className="inline font-semibold">Basis: </dt>
            <dd className="inline">{kpi.basis}</dd>
          </div>
          <div>
            <dt className="inline font-semibold">Meaning: </dt>
            <dd className="inline">{kpi.meaning}</dd>
          </div>
          <div>
            <dt className="inline font-semibold">As of: </dt>
            <dd className="inline">{asOf}</dd>
          </div>
        </dl>
      </details>
      <Link
        href={kpi.href}
        className="mt-auto inline-flex items-center gap-1 pt-3 text-[13px] font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
      >
        Open source list<span className="sr-only">: {kpi.label}</span>
        <ArrowRight aria-hidden="true" className="size-3.5" />
      </Link>
    </article>
  );
}

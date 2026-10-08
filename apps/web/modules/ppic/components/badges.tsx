import * as React from 'react';
import { CircleAlert, Clock } from 'lucide-react';
import { DomainStatusBadge } from '@eora/features/patterns';
import { Badge, cn } from '@eora/ui';
import type { ConversionStatus, IndicatorStatus, MeasurementMethod, RiskReason, Uom } from '../contracts';
import { fmtDateTime, fmtQty, fmtSigned } from './format';

/** ConversionBadge (EORA-COMPONENT-STANDARDS §7): Calculated / Provisional / Not calculable — missing inputs. Planning estimate only. */
export function ConversionBadge({ status, missing }: { status: ConversionStatus; missing?: string[] }) {
  if (status === 'CALCULATED') return <Badge tone="success">Calculated</Badge>;
  if (status === 'PROVISIONAL') return <Badge tone="warning">Provisional</Badge>;
  return (
    <Badge tone="neutral" title={missing?.length ? `Missing: ${missing.join(', ')}` : undefined}>
      <CircleAlert aria-hidden="true" />
      Not calculable{missing?.length ? ` — missing: ${missing.join(', ')}` : ''}
    </Badge>
  );
}

/** Estimated pieces range — always labelled "est." so it never reads as stock. */
export function EstimatedPcs({ lower, upper }: { lower: number | null; upper: number | null }) {
  if (upper === null) return <span className="text-ink-2">—</span>;
  return (
    <span className="tabular-nums">
      <span className="mr-1 text-xs text-ink-2">est.</span>
      {lower !== null ? `${fmtQty(lower, 'pcs')}–` : ''}
      {fmtQty(upper, 'pcs')}
      <span data-sub className="ml-[3px] text-xs text-ink-2">
        pcs
      </span>
    </span>
  );
}

/** VarianceIndicator cell: signed value + % + finality status. Never labelled loss (ADR-0005, D-024). */
export function IndicatorCell({ value, pct, uom, status }: { value: number | null; pct: number | null; uom: Uom; status: IndicatorStatus }) {
  return (
    <span className="inline-flex items-center justify-end gap-2">
      {value !== null ? (
        <span className="tabular-nums">
          {fmtSigned(value, uom)}
          <span data-sub className="ml-[3px] text-xs text-ink-2">
            {uom}
          </span>
          {pct !== null ? <span className="ml-1.5 text-xs text-ink-2">({pct.toFixed(2)} %)</span> : null}
        </span>
      ) : (
        <span className="text-ink-2">—</span>
      )}
      <DomainStatusBadge domain="indicator" status={status} />
    </span>
  );
}

const METHOD: Record<MeasurementMethod | 'NONE', string> = {
  WEIGHED_REMAINING: 'Weighed remaining',
  WEIGHED_CONSUMED: 'Weighed consumed',
  COUNTED_REMAINING: 'Counted remaining',
  MEASURED_LENGTH: 'Measured length',
  UNMEASURED_BY_DIFFERENCE: 'Unmeasured (by difference)',
  NONE: 'Not measured yet',
};
export function MethodLabel({ method }: { method: MeasurementMethod | 'NONE' }) {
  const warn = method === 'UNMEASURED_BY_DIFFERENCE';
  return warn ? <Badge tone="warning">{METHOD[method]}</Badge> : <span className="text-ink-2">{METHOD[method]}</span>;
}

const REASON: Record<RiskReason, string> = {
  NO_WO: 'No WO',
  MATERIAL_SHORT: 'Material short',
  QC_BACKLOG: 'QC backlog',
  CAPACITY: 'Capacity',
  NOT_CALCULABLE_CONVERSION: 'Conversion not calculable',
};
export function RiskReasons({ reasons }: { reasons: RiskReason[] }) {
  if (!reasons.length) return <span className="text-ink-2">—</span>;
  return (
    <span className="inline-flex flex-wrap gap-1">
      {reasons.map((r) => (
        <Badge key={r} tone="neutral">
          {REASON[r]}
        </Badge>
      ))}
    </span>
  );
}

/**
 * Freshness of a read model (doc 19 §2): "Updated 09:40 WIB · 2 min". The lag is measured when the response arrived
 * (`receivedAt`), so rendering stays pure. Lag above the threshold (default 10 min) shows a warning state.
 */
export function FreshnessBadge({
  asOf,
  receivedAt,
  thresholdMin = 10,
  className,
}: {
  asOf: string;
  receivedAt: number;
  thresholdMin?: number;
  className?: string;
}) {
  const lag = Math.max(0, Math.round((receivedAt - Date.parse(asOf)) / 60_000));
  const stale = lag > thresholdMin;
  return (
    <span className={cn('inline-flex items-center gap-1.5', stale ? 'font-medium text-stat-warn-fg' : 'text-ink-2', className)}>
      <Clock aria-hidden="true" className="size-3.5" />
      Updated {fmtDateTime(asOf)} · {lag} min{stale ? ' — read model stale' : ''}
    </span>
  );
}

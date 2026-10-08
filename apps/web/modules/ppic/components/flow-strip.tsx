import * as React from 'react';
import Link from 'next/link';
import { ArrowRight, Factory } from 'lucide-react';
import type { FlowStage } from '../contracts';
import { fmtQty } from './format';

/**
 * MaterialFlowStrip (doc 19 §2): stages grouped into UOM bands. Material (kg / sheet) and pieces are never summed;
 * the transformation boundary is explicit.
 */
export function MaterialFlowStrip({ material, output }: { material: FlowStage[]; output: FlowStage[] }) {
  const stage = (s: FlowStage) => (
    <li key={s.id} className="min-w-0 flex-1">
      <Link
        href={s.href}
        className="block h-full rounded-md border border-line bg-surface px-3 py-2.5 hover:border-line-strong hover:bg-subtle focus-visible:outline-2 focus-visible:outline-ring"
      >
        <span className="block text-xs font-medium text-ink-2">{s.label}</span>
        {s.quantities.map((q) => (
          <span key={q.uom} className="block text-[15px] font-semibold tabular-nums">
            {fmtQty(q.value, q.uom)}
            <span className="ml-1 text-xs font-normal text-ink-2">{q.uom}</span>
          </span>
        ))}
        {s.note ? <span className="mt-0.5 block text-[11.5px] text-ink-2">{s.note}</span> : null}
      </Link>
    </li>
  );
  return (
    <section aria-label="Material flow" className="rounded-lg border border-line bg-surface p-4 shadow-[var(--shadow-card)]">
      <h2 className="m-0 mb-3 text-[15px] font-semibold">Material flow · where quantities sit now</h2>
      <div className="flex items-stretch gap-3 max-xl:flex-col">
        <div className="min-w-0 rounded-md bg-subtle p-2 xl:flex-[2]">
          <p className="m-0 mb-2 px-1 text-[11.5px] font-semibold tracking-[0.05em] text-ink-2 uppercase">Physical material · kg / sheet</p>
          <ol className="m-0 flex list-none gap-2 p-0 max-md:flex-col">{material.map(stage)}</ol>
        </div>
        <div
          className="flex shrink-0 flex-col items-center justify-center gap-1 px-1 text-center text-[11.5px] font-medium text-ink-2 max-xl:flex-row"
          aria-label="Transformation boundary: material in kg or sheet becomes pieces"
        >
          <Factory aria-hidden="true" className="size-4" />
          <span>Transformation</span>
          <ArrowRight aria-hidden="true" className="size-4 max-xl:rotate-90" />
        </div>
        <div className="min-w-0 rounded-md bg-subtle p-2 xl:flex-[4]">
          <p className="m-0 mb-2 px-1 text-[11.5px] font-semibold tracking-[0.05em] text-ink-2 uppercase">Production, quality, FG & shipment · pcs</p>
          <ol className="m-0 flex list-none gap-2 p-0 max-md:flex-col">{output.map(stage)}</ol>
        </div>
      </div>
      <p className="m-0 mt-2 text-xs text-ink-2">
        Quantities are never added across the boundary. Estimated pieces from raw material are planning figures and are not part of this strip.
      </p>
    </section>
  );
}

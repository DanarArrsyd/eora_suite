'use client';

import * as React from 'react';
import Link from 'next/link';
import { ArrowUpRight } from 'lucide-react';
import { Drawer, DrawerContent, MockDataLabel } from '@eora/ui';

export type CtDetail = {
  title: string;
  subtitle?: string;
  sections: { title: string; rows: [string, React.ReactNode][] }[];
  /** Source documents / drill targets (permission re-checked on open). */
  sources: { label: string; ref: string; href?: string }[];
  calculation?: { title: string; body: React.ReactNode };
};

/** Shared detail drawer for Control Tower rows: definition lists, calculation disclosure and drill links to source documents. */
export function CtDetailDrawer({ detail, onClose }: { detail: CtDetail | null; onClose: () => void }) {
  return (
    <Drawer open={!!detail} onOpenChange={(v) => !v && onClose()}>
      <DrawerContent title={detail?.title ?? 'Details'} closeLabel="Close details">
        {detail ? (
          <div className="flex min-h-0 flex-1 flex-col overflow-y-auto p-5 text-[13.5px]">
            {detail.subtitle ? <p className="m-0 mb-4 text-ink-2">{detail.subtitle}</p> : null}
            {detail.sections.map((s) => (
              <section key={s.title} className="mb-5">
                <h3 className="m-0 mb-2 text-sm font-semibold">{s.title}</h3>
                <dl className="m-0 grid grid-cols-[minmax(140px,auto)_1fr] gap-x-4 gap-y-1.5">
                  {s.rows.map(([k, v]) => (
                    <React.Fragment key={k}>
                      <dt className="text-ink-2">{k}</dt>
                      <dd className="m-0 min-w-0 text-right tabular-nums">{v}</dd>
                    </React.Fragment>
                  ))}
                </dl>
              </section>
            ))}
            {detail.calculation ? (
              <details className="mb-5 rounded-md border border-line px-3 py-2">
                <summary className="cursor-pointer text-[13px] font-semibold text-link">Show calculation · {detail.calculation.title}</summary>
                <div className="mt-2 text-[13px] text-ink-2">{detail.calculation.body}</div>
              </details>
            ) : null}
            <section>
              <h3 className="m-0 mb-2 text-sm font-semibold">Source documents</h3>
              <ul className="m-0 flex list-none flex-col gap-1.5 p-0">
                {detail.sources.map((s) => (
                  <li key={`${s.label}-${s.ref}`} className="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2">
                    <span className="min-w-0">
                      <span className="block text-xs text-ink-2">{s.label}</span>
                      <span className="font-medium">{s.ref}</span>
                    </span>
                    {s.href ? (
                      <Link
                        href={s.href}
                        className="inline-flex min-h-8 items-center gap-1 text-[13px] font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        Open<span className="sr-only"> {s.ref}</span>
                        <ArrowUpRight aria-hidden="true" className="size-3.5" />
                      </Link>
                    ) : null}
                  </li>
                ))}
              </ul>
            </section>
            <p className="mt-5 flex items-center gap-2 text-xs text-ink-2">
              <MockDataLabel /> Sample values from the typed mock adapter; production reads the ledger and read models.
            </p>
          </div>
        ) : null}
      </DrawerContent>
    </Drawer>
  );
}

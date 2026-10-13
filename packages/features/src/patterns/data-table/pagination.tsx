'use client';

import * as React from 'react';
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, CornerDownLeft } from 'lucide-react';
import { IconButton, Select, cn } from '@eora/ui';
import { PAGE_SIZES } from '../../data';

type Props = {
  page: number;
  pageSize: number;
  total: number;
  onPageChange: (page: number) => void;
  onPageSizeChange: (size: number) => void;
  /** Plural noun for the summary, e.g. "lots". */
  itemLabel: string;
};

/** Page numbers with ellipses: 1 … 4 5 [6] 7 8 … 20. Exported for tests. */
export function pageWindow(page: number, pages: number): (number | 'gap')[] {
  if (pages <= 7) return Array.from({ length: pages }, (_, i) => i + 1);
  const set = new Set([1, pages, page - 1, page, page + 1].filter((p) => p >= 1 && p <= pages));
  const sorted = [...set].sort((a, b) => a - b);
  const out: (number | 'gap')[] = [];
  sorted.forEach((p, i) => {
    if (i > 0 && p - sorted[i - 1]! > 1) out.push('gap');
    out.push(p);
  });
  return out;
}

const navBtn =
  'inline-flex h-8 items-center gap-0.5 rounded-md px-2 text-[13px] text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent max-md:h-11';

/** EoraPagination — identical in every module (EORA-COMPONENT-STANDARDS §6). */
export function Pagination({ page, pageSize, total, onPageChange, onPageSizeChange, itemLabel }: Props) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  const current = Math.min(page, pages);
  const from = total === 0 ? 0 : (current - 1) * pageSize + 1;
  const to = Math.min(total, current * pageSize);
  const [goto, setGoto] = React.useState('');
  const sizeId = React.useId();
  const gotoId = React.useId();
  const go = (p: number) => onPageChange(Math.min(pages, Math.max(1, p)));
  const fmt = (n: number) => n.toLocaleString('en-US');

  return (
    <nav
      aria-label={`Pagination, ${itemLabel}`}
      className="flex flex-wrap items-center justify-between gap-x-5 gap-y-3 border-t border-line px-5 py-3 text-[13px] text-ink-2 max-md:px-4"
    >
      <div className="flex flex-wrap items-center gap-5">
        <p className="m-0" aria-live="polite">
          Showing{' '}
          <b className="font-semibold text-ink tabular-nums">
            {fmt(from)}–{fmt(to)}
          </b>{' '}
          of <b className="font-semibold text-ink tabular-nums">{fmt(total)}</b> {itemLabel}
        </p>
        <div className="flex items-center gap-2 max-md:hidden">
          <label htmlFor={sizeId}>Items per page</label>
          <Select
            id={sizeId}
            variant="compact"
            className="w-[76px]"
            value={String(pageSize)}
            onValueChange={(v) => onPageSizeChange(Number(v))}
            options={PAGE_SIZES.map((s) => ({ value: String(s), label: String(s) }))}
          />
        </div>
        <form
          className="flex items-center gap-2 max-md:hidden"
          onSubmit={(e) => {
            e.preventDefault();
            const n = Number.parseInt(goto, 10);
            if (Number.isFinite(n)) go(n);
            setGoto('');
          }}
        >
          <label htmlFor={gotoId}>Go to page</label>
          <input
            id={gotoId}
            inputMode="numeric"
            pattern="[0-9]*"
            value={goto}
            onChange={(e) => setGoto(e.target.value.replace(/\D/g, ''))}
            className="h-8 w-16 rounded-md border border-line-control bg-surface px-2 text-[13px] text-ink tabular-nums focus:outline-2 focus:outline-ring"
          />
          <IconButton type="submit" size="icon-sm" variant="secondary" aria-label="Go to page">
            <CornerDownLeft aria-hidden="true" />
          </IconButton>
        </form>
      </div>
      <div className="flex items-center gap-0.5">
        <button type="button" className={cn(navBtn, 'max-md:hidden')} disabled={current <= 1} onClick={() => go(1)}>
          <ChevronsLeft aria-hidden="true" className="size-4" />
          First
        </button>
        <button type="button" className={navBtn} disabled={current <= 1} onClick={() => go(current - 1)}>
          <ChevronLeft aria-hidden="true" className="size-4" />
          Prev
        </button>
        {pageWindow(current, pages).map((p, i) =>
          p === 'gap' ? (
            <span key={`g${i}`} aria-hidden="true" className="px-1 max-md:hidden">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              aria-label={`Page ${p}`}
              aria-current={p === current ? 'page' : undefined}
              onClick={() => go(p)}
              className={cn(
                'h-8 min-w-8 rounded-md px-2 text-[13px] text-ink tabular-nums hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring',
                p === current ? 'bg-accent-subtle font-semibold text-accent max-md:h-11' : 'max-md:hidden',
              )}
            >
              {p}
            </button>
          ),
        )}
        <button type="button" className={navBtn} disabled={current >= pages} onClick={() => go(current + 1)}>
          Next
          <ChevronRight aria-hidden="true" className="size-4" />
        </button>
        <button type="button" className={cn(navBtn, 'max-md:hidden')} disabled={current >= pages} onClick={() => go(pages)}>
          Last
          <ChevronsRight aria-hidden="true" className="size-4" />
        </button>
      </div>
    </nav>
  );
}

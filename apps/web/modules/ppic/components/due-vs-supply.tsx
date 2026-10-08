'use client';

import * as React from 'react';
import { TriangleAlert } from 'lucide-react';
import type { DueVsSupplyPoint } from '../contracts';
import { fmtDay, fmtQty } from './format';

/**
 * "Will FG supply cover customer dues each day?" — one part at a time (pieces are only comparable within a part).
 * Bars = outstanding due that day; line = projected supply available at the start of the day (CT-8 projection).
 * One y-axis (pcs). Legend + direct risk labels + table view; hover/focus tooltip per day. Palette validated (tokens doc).
 */
export function DueVsSupplyChart({ series }: { series: { part: string; points: DueVsSupplyPoint[] }[] }) {
  const [part, setPart] = React.useState(series[0]?.part ?? '');
  const [hover, setHover] = React.useState<number | null>(null);
  const [table, setTable] = React.useState(false);
  const selectId = React.useId();
  const current = series.find((s) => s.part === part) ?? series[0];
  if (!current) return <p className="m-0 text-[13.5px] text-ink-2">No open demand in this scope.</p>;
  const pts = current.points;
  const W = 640;
  const H = 220;
  const pad = { l: 44, r: 12, t: 12, b: 34 };
  const max = Math.max(1, ...pts.map((p) => Math.max(p.due, Math.max(0, p.projected))));
  const step = (W - pad.l - pad.r) / pts.length;
  const y = (v: number) => pad.t + (H - pad.t - pad.b) * (1 - Math.max(0, v) / max);
  const ticks = [0, 0.5, 1].map((f) => Math.round(max * f));
  const line = pts.map((p, i) => `${i === 0 ? 'M' : 'L'}${pad.l + step * i + step / 2},${y(p.projected)}`).join(' ');
  const risk = pts.map((p) => p.due > 0 && p.projected < p.due);

  return (
    <figure className="m-0">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-[13px]">
          <label htmlFor={selectId} className="text-ink-2">
            Part
          </label>
          <select
            id={selectId}
            value={current.part}
            onChange={(e) => setPart(e.target.value)}
            className="h-8 rounded-md border border-line-control bg-surface px-2 text-[13px] focus:outline-2 focus:outline-ring max-md:h-11 max-md:text-base"
          >
            {series.map((s) => (
              <option key={s.part} value={s.part}>
                {s.part}
              </option>
            ))}
          </select>
        </div>
        <ul aria-label="Legend" className="m-0 flex list-none gap-4 p-0 text-xs text-ink-2">
          <li className="flex items-center gap-1.5">
            <span aria-hidden="true" className="inline-block size-3 rounded-[3px] bg-[var(--viz-demand)]" />
            Outstanding due
          </li>
          <li className="flex items-center gap-1.5">
            <span aria-hidden="true" className="inline-block h-0.5 w-4 bg-[var(--viz-supply)]" />
            Projected supply
          </li>
          <li className="flex items-center gap-1.5">
            <TriangleAlert aria-hidden="true" className="size-3.5 text-stat-err" />
            At risk
          </li>
        </ul>
      </div>
      <div className="relative max-w-[720px]">
        <svg
          viewBox={`0 0 ${W} ${H}`}
          className="block h-auto w-full"
          role="group"
          aria-label={`Outstanding due versus projected supply per day for ${current.part}, next ${pts.length} days. Each day is focusable; the table view lists all values.`}
        >
          {ticks.map((t) => (
            <g key={t}>
              <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="var(--viz-grid)" strokeWidth={1} />
              <text x={pad.l - 6} y={y(t) + 4} textAnchor="end" fontSize={11} fill="var(--color-text-secondary)">
                {fmtQty(t, 'pcs')}
              </text>
            </g>
          ))}
          {pts.map((p, i) => {
            const x = pad.l + step * i;
            const bw = Math.min(28, step * 0.55);
            return (
              <g key={p.date}>
                {p.due > 0 ? (
                  <rect
                    x={x + (step - bw) / 2}
                    y={y(p.due)}
                    width={bw}
                    height={Math.max(0, H - pad.b - y(p.due))}
                    rx={4}
                    fill="var(--viz-demand)"
                    opacity={hover === null || hover === i ? 1 : 0.55}
                  />
                ) : null}
                {risk[i] ? (
                  <text x={x + step / 2} y={y(p.due) - 5} textAnchor="middle" fontSize={11} fontWeight={600} fill="var(--color-danger-fg)">
                    ▲ risk
                  </text>
                ) : null}
                <text x={x + step / 2} y={H - pad.b + 16} textAnchor="middle" fontSize={11} fill="var(--color-text-secondary)">
                  {fmtDay(p.date).slice(0, 6)}
                </text>
                <rect
                  x={x}
                  y={pad.t}
                  width={step}
                  height={H - pad.t - pad.b}
                  fill="transparent"
                  tabIndex={0}
                  role="button"
                  aria-label={`${fmtDay(p.date)}: due ${fmtQty(p.due, 'pcs')} pcs, projected ${fmtQty(p.projected, 'pcs')} pcs${risk[i] ? ', at risk' : ''}`}
                  onPointerEnter={() => setHover(i)}
                  onPointerLeave={() => setHover(null)}
                  onFocus={() => setHover(i)}
                  onBlur={() => setHover(null)}
                  className="outline-none focus-visible:stroke-[var(--color-focus-ring)] focus-visible:stroke-2"
                />
              </g>
            );
          })}
          <path d={line} fill="none" stroke="var(--viz-supply)" strokeWidth={2} pointerEvents="none" />
          {pts.map((p, i) => (
            <circle
              key={p.date}
              cx={pad.l + step * i + step / 2}
              cy={y(p.projected)}
              r={hover === i ? 5 : 4}
              fill="var(--viz-supply)"
              stroke="var(--color-bg-surface)"
              strokeWidth={2}
              pointerEvents="none"
            />
          ))}
        </svg>
        {hover !== null ? (
          <div
            role="status"
            className="pointer-events-none absolute top-1 right-1 rounded-md border border-line bg-surface px-3 py-2 text-xs shadow-[var(--shadow-popover)]"
          >
            <p className="m-0 font-semibold">{fmtDay(pts[hover]!.date)}</p>
            <p className="m-0">
              <b className="tabular-nums">{fmtQty(pts[hover]!.due, 'pcs')}</b> <span className="text-ink-2">pcs outstanding due</span>
            </p>
            <p className="m-0">
              <b className="tabular-nums">{fmtQty(pts[hover]!.projected, 'pcs')}</b> <span className="text-ink-2">pcs projected supply</span>
            </p>
          </div>
        ) : null}
      </div>
      <figcaption className="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-ink-2">
        <span>Projected = FG available + QC released + planned output before the day − earlier dues. Pending QC is not projected.</span>
        <button
          type="button"
          aria-expanded={table}
          onClick={() => setTable(!table)}
          className="font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring"
        >
          {table ? 'Hide table' : 'Show as table'}
        </button>
      </figcaption>
      {table ? (
        <table className="mt-2 w-full border-separate border-spacing-0 text-[13px]">
          <caption className="sr-only">Due versus projected supply for {current.part}</caption>
          <thead>
            <tr>
              {['Date', 'Outstanding due (pcs)', 'Projected supply (pcs)', 'Status'].map((h) => (
                <th key={h} scope="col" className="border-b border-line bg-subtle px-3 py-2 text-left text-xs font-semibold text-ink-2">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pts.map((p, i) => (
              <tr key={p.date}>
                <td className="border-b border-line px-3 py-1.5">{fmtDay(p.date)}</td>
                <td className="border-b border-line px-3 py-1.5 tabular-nums">{fmtQty(p.due, 'pcs')}</td>
                <td className="border-b border-line px-3 py-1.5 tabular-nums">{fmtQty(p.projected, 'pcs')}</td>
                <td className="border-b border-line px-3 py-1.5">{risk[i] ? 'At risk' : p.due ? 'Covered' : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
    </figure>
  );
}

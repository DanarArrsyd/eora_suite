'use client';

import * as React from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '../lib/cn';
import { addDays, addMonths, clampDate, formatDateLong, formatMonth, isoWeek, isoWeekday, monthGrid, startOfMonth, todayIn, type IsoDate } from '../lib/dates';

export type DateRange = { from: IsoDate | ''; to: IsoDate | '' };

type Common = {
  /** Earliest / latest selectable dates (inclusive). */
  min?: IsoDate;
  max?: IsoDate;
  /** Today in the relevant zone (tenant zone, D-044); marked in the grid and used by the "Today" action. */
  today?: IsoDate;
  /** Accessible name of the grid, e.g. "Production date". */
  label: string;
  /** Move focus into the grid on mount (popovers). */
  autoFocus?: boolean;
  /** Touch layout: 44 px day cells. */
  touch?: boolean;
  className?: string;
};

export type CalendarProps = Common &
  ({ mode: 'single'; value: IsoDate | ''; onChange: (value: IsoDate) => void } | { mode: 'range'; value: DateRange; onChange: (value: DateRange) => void });

const WEEKDAYS = [
  ['Mo', 'Monday'],
  ['Tu', 'Tuesday'],
  ['We', 'Wednesday'],
  ['Th', 'Thursday'],
  ['Fr', 'Friday'],
  ['Sa', 'Saturday'],
  ['Su', 'Sunday'],
] as const;

function initialFocus(p: CalendarProps): IsoDate {
  const chosen = p.mode === 'single' ? p.value : p.value.from || p.value.to;
  return clampDate(chosen || p.today || todayIn(), p.min, p.max);
}

/**
 * EoraCalendar — month grid with ISO weeks (Monday first, ISO week numbers; D-044, D-041 weekly buckets). WAI-ARIA grid
 * pattern: one tab stop, arrows move by day/week, Home/End to the week's start/end, PageUp/PageDown by month
 * (+Shift: year), Enter/Space chooses. Range mode: first choice starts, second ends (swapped when earlier).
 */
export function Calendar(props: CalendarProps) {
  const { min, max, today, label, autoFocus, touch, className } = props;
  const [focused, setFocused] = React.useState<IsoDate>(() => initialFocus(props));
  const [month, setMonth] = React.useState<IsoDate>(() => startOfMonth(initialFocus(props)));
  const [hover, setHover] = React.useState<IsoDate | null>(null);
  const gridRef = React.useRef<HTMLTableElement>(null);
  const moved = React.useRef(false);
  const titleId = React.useId();

  React.useEffect(() => {
    if (autoFocus || moved.current) gridRef.current?.querySelector<HTMLButtonElement>('button[tabindex="0"]')?.focus();
    moved.current = false;
  }, [focused, autoFocus]);

  const disabled = (d: IsoDate) => (min ? d < min : false) || (max ? d > max : false);
  const focusDate = (d: IsoDate) => {
    const next = clampDate(d, min, max);
    moved.current = true;
    setFocused(next);
    if (startOfMonth(next) !== month) setMonth(startOfMonth(next));
  };
  const showMonth = (delta: number) => {
    setMonth(addMonths(month, delta));
    setFocused(clampDate(addMonths(focused, delta), min, max));
  };

  const range = props.mode === 'range' ? props.value : null;
  const pendingEnd = range && range.from && !range.to ? (hover ?? null) : null;
  const span = (() => {
    if (!range) return null;
    const a = range.from;
    const b = range.to || pendingEnd || '';
    if (!a || !b) return null;
    return a <= b ? [a, b] : [b, a];
  })();

  const choose = (d: IsoDate) => {
    if (disabled(d)) return;
    setFocused(d);
    if (props.mode === 'single') {
      props.onChange(d);
      return;
    }
    const { from, to } = props.value;
    if (!from || to) props.onChange({ from: d, to: '' });
    else props.onChange(d < from ? { from: d, to: from } : { from, to: d });
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    const moves: Record<string, () => IsoDate> = {
      ArrowLeft: () => addDays(focused, -1),
      ArrowRight: () => addDays(focused, 1),
      ArrowUp: () => addDays(focused, -7),
      ArrowDown: () => addDays(focused, 7),
      Home: () => addDays(focused, 1 - isoWeekday(focused)),
      End: () => addDays(focused, 7 - isoWeekday(focused)),
      PageUp: () => addMonths(focused, e.shiftKey ? -12 : -1),
      PageDown: () => addMonths(focused, e.shiftKey ? 12 : 1),
    };
    const move = moves[e.key];
    if (move) {
      e.preventDefault();
      focusDate(move());
    }
  };

  const selected = (d: IsoDate) => (props.mode === 'single' ? props.value === d : d === props.value.from || d === props.value.to);
  const inSpan = (d: IsoDate) => !!span && d > span[0]! && d < span[1]!;
  const weeks = monthGrid(month);
  const cell = touch ? 'size-11' : 'size-9';

  return (
    <div className={cn('flex w-max flex-col gap-2 select-none', className)}>
      <div className="flex items-center justify-between gap-2">
        <button
          type="button"
          onClick={() => showMonth(-1)}
          disabled={!!min && addMonths(month, -1).slice(0, 7) < min.slice(0, 7)}
          aria-label="Previous month"
          className={cn(
            'inline-grid place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink disabled:opacity-40 disabled:hover:bg-transparent',
            cell,
          )}
        >
          <ChevronLeft aria-hidden="true" className="size-4" />
        </button>
        <p id={titleId} aria-live="polite" className="m-0 text-sm font-semibold text-ink">
          {formatMonth(month)}
        </p>
        <button
          type="button"
          onClick={() => showMonth(1)}
          disabled={!!max && addMonths(month, 1).slice(0, 7) > max.slice(0, 7)}
          aria-label="Next month"
          className={cn(
            'inline-grid place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink disabled:opacity-40 disabled:hover:bg-transparent',
            cell,
          )}
        >
          <ChevronRight aria-hidden="true" className="size-4" />
        </button>
      </div>
      <table ref={gridRef} role="grid" aria-label={`${label}, ${formatMonth(month)}`} className="border-collapse">
        <thead>
          <tr>
            <th scope="col" className="pb-1 text-[11px] font-medium text-ink-2">
              <abbr title="ISO week" className="no-underline">
                Wk
              </abbr>
            </th>
            {WEEKDAYS.map(([short, long]) => (
              <th key={short} scope="col" className="pb-1 text-xs font-medium text-ink-2">
                <abbr title={long} className="no-underline">
                  {short}
                </abbr>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {weeks.map((week) => (
            <tr key={week[0]}>
              <th scope="row" className="pr-1 text-center text-[11px] font-normal text-ink-2 tabular-nums">
                <span className="sr-only">Week </span>
                {isoWeek(week[0]!)}
              </th>
              {week.map((d) => {
                const outside = d.slice(0, 7) !== month.slice(0, 7);
                const off = disabled(d);
                const sel = selected(d);
                const mid = inSpan(d);
                const isToday = d === today;
                return (
                  <td key={d} className={cn('p-0', mid && 'bg-accent-subtle')} role="gridcell" aria-selected={sel || mid || undefined}>
                    <button
                      type="button"
                      tabIndex={d === focused ? 0 : -1}
                      disabled={off}
                      aria-label={`${formatDateLong(d)}${isToday ? ', today' : ''}`}
                      aria-current={isToday ? 'date' : undefined}
                      onClick={() => choose(d)}
                      onKeyDown={onKeyDown}
                      onMouseEnter={() => setHover(d)}
                      onMouseLeave={() => setHover(null)}
                      onFocus={() => {
                        setFocused(d);
                        setHover(d); // keyboard users see the pending range too
                      }}
                      className={cn(
                        'relative inline-grid place-items-center rounded-md text-[13px] tabular-nums outline-hidden focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring',
                        cell,
                        outside ? 'text-ink-2' : 'text-ink',
                        !sel && !off && 'hover:bg-hover',
                        sel && 'bg-accent font-semibold text-on-accent hover:bg-accent-hover',
                        isToday && !sel && 'font-semibold text-accent',
                        off && 'cursor-not-allowed text-ink-muted opacity-60',
                      )}
                    >
                      {Number(d.slice(8))}
                      {isToday ? <span aria-hidden="true" className={cn('absolute bottom-1 size-1 rounded-full', sel ? 'bg-on-accent' : 'bg-accent')} /> : null}
                    </button>
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

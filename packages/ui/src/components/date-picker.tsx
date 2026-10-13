'use client';

import * as React from 'react';
import { Popover as P } from 'radix-ui';
import { CalendarDays } from 'lucide-react';
import { cn } from '../lib/cn';
import { formatDate, formatDateRange, type IsoDate } from '../lib/dates';
import { Calendar, type DateRange } from './calendar';

type TriggerVariant = 'field' | 'segment';

const triggerCls = {
  field:
    'h-9 rounded-md border border-line-control bg-surface px-3 text-[13.5px] hover:border-ink-2 focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring disabled:cursor-not-allowed disabled:bg-hover disabled:text-ink-2 aria-[invalid=true]:border-stat-err aria-[invalid=true]:ring-1 aria-[invalid=true]:ring-stat-err max-md:h-11 max-md:text-base',
  segment:
    'h-9 min-w-40 border-0 bg-transparent px-3 text-[13px] hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring',
} as const;

type Aria = { ref?: React.Ref<HTMLButtonElement>; id?: string; 'aria-describedby'?: string; 'aria-invalid'?: true | boolean; 'aria-required'?: true | boolean };

type Base = Aria & {
  /** What the date means, e.g. "Access ends" — the trigger's and the calendar's accessible name. */
  label: string;
  placeholder?: string;
  min?: IsoDate;
  max?: IsoDate;
  today?: IsoDate;
  disabled?: boolean;
  variant?: TriggerVariant;
  /** Highlight while a value is applied (filter bar). */
  active?: boolean;
  className?: string;
  onBlur?: () => void;
};

function Trigger({
  text,
  placeholder,
  variant,
  active,
  label,
  className,
  'aria-required': _required, // a button cannot be "required"; the field label carries the marker
  ...rest
}: Aria & { text: string; placeholder: string; variant: TriggerVariant; active?: boolean; label: string; className?: string; disabled?: boolean }) {
  return (
    <P.Trigger
      type="button"
      data-slot="date-picker"
      aria-label={`${label}: ${text || 'not set'}. Choose date`}
      className={cn(
        'inline-flex w-full min-w-0 items-center justify-between gap-2 text-left text-ink outline-hidden data-[state=open]:border-ring',
        triggerCls[variant],
        !text && 'text-ink-2',
        active && 'font-semibold text-accent',
        className,
      )}
      {...rest}
    >
      <span className="truncate tabular-nums">{text || placeholder}</span>
      <CalendarDays aria-hidden="true" className="size-4 shrink-0 text-ink-2" />
    </P.Trigger>
  );
}

function Panel({ children, label }: { children: React.ReactNode; label: string }) {
  return (
    <P.Portal>
      <P.Content
        align="start"
        sideOffset={6}
        collisionPadding={12}
        aria-label={label}
        className="z-90 rounded-lg border border-line bg-surface p-3 shadow-[var(--shadow-popover)] focus-visible:outline-none"
      >
        {children}
      </P.Content>
    </P.Portal>
  );
}

const footerBtn = 'h-8 rounded-md px-2.5 text-[13px] font-medium text-link hover:bg-hover disabled:text-ink-2 disabled:hover:bg-transparent max-md:h-11';

/** EoraDatePicker — one calendar date in a popover calendar (ISO weeks). Value is `YYYY-MM-DD` or `''`. */
export function DatePicker({
  value,
  onChange,
  clearable = true,
  label,
  placeholder = 'Choose a date',
  min,
  max,
  today,
  disabled,
  variant = 'field',
  active,
  className,
  onBlur,
  ...aria
}: Base & { value: IsoDate | ''; onChange: (value: IsoDate | '') => void; clearable?: boolean }) {
  const [open, setOpen] = React.useState(false);
  const set = (v: IsoDate | '') => {
    onChange(v);
    setOpen(false);
  };
  const todayAllowed = !!today && !(min && today < min) && !(max && today > max);
  return (
    <P.Root
      open={open}
      onOpenChange={(o) => {
        setOpen(o);
        if (!o) onBlur?.();
      }}
    >
      <Trigger
        text={value ? formatDate(value) : ''}
        placeholder={placeholder}
        variant={variant}
        active={active}
        label={label}
        className={className}
        disabled={disabled}
        {...aria}
      />
      <Panel label={label}>
        <Calendar mode="single" label={label} value={value} onChange={set} min={min} max={max} today={today} autoFocus />
        <div className="mt-2 flex items-center justify-between gap-2 border-t border-line pt-2">
          {today ? (
            <button type="button" className={footerBtn} disabled={!todayAllowed} onClick={() => set(today)}>
              Today
            </button>
          ) : (
            <span />
          )}
          {clearable ? (
            <button type="button" className={footerBtn} disabled={!value} onClick={() => set('')}>
              Clear
            </button>
          ) : null}
        </div>
      </Panel>
    </P.Root>
  );
}

/**
 * EoraDateRangePicker — from/to calendar dates in one popover calendar: the first click starts the range, the second ends
 * it (earlier second click swaps) and closes the popover. Either end may stay open (`from` only = from that date on).
 */
export function DateRangePicker({
  value,
  onChange,
  label,
  placeholder = 'Any date',
  min,
  max,
  today,
  disabled,
  variant = 'field',
  active,
  className,
  onBlur,
  ...aria
}: Base & { value: DateRange; onChange: (value: DateRange) => void }) {
  const [open, setOpen] = React.useState(false);
  const [draft, setDraft] = React.useState<DateRange>(value);
  const change = (o: boolean) => {
    if (o) setDraft(value);
    setOpen(o);
    if (!o) onBlur?.();
  };
  const pick = (r: DateRange) => {
    setDraft(r);
    if (r.from && r.to) {
      onChange(r);
      setOpen(false);
    }
  };
  const hint = !draft.from ? 'Choose the first day.' : !draft.to ? 'Choose the last day, or keep only the start.' : formatDateRange(draft.from, draft.to);
  return (
    <P.Root open={open} onOpenChange={change}>
      <Trigger
        text={formatDateRange(value.from, value.to)}
        placeholder={placeholder}
        variant={variant}
        active={active}
        label={label}
        className={className}
        disabled={disabled}
        {...aria}
      />
      <Panel label={label}>
        <Calendar mode="range" label={label} value={draft} onChange={pick} min={min} max={max} today={today} autoFocus />
        <p className="m-0 mt-2 text-xs text-ink-2" aria-live="polite">
          {hint}
        </p>
        <div className="mt-2 flex items-center justify-between gap-2 border-t border-line pt-2">
          <button
            type="button"
            className={footerBtn}
            disabled={!draft.from && !draft.to}
            onClick={() => {
              onChange({ from: '', to: '' });
              setOpen(false);
            }}
          >
            Clear
          </button>
          <button
            type="button"
            className={cn(footerBtn, 'bg-accent text-on-accent hover:bg-accent-hover disabled:bg-hover disabled:text-ink-2')}
            disabled={!draft.from}
            onClick={() => {
              onChange(draft);
              setOpen(false);
            }}
          >
            Done
          </button>
        </div>
      </Panel>
    </P.Root>
  );
}

export { Calendar, type DateRange };

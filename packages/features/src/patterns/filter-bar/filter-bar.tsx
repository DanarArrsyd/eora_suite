'use client';

import * as React from 'react';
import { ChevronDown, SlidersHorizontal, X } from 'lucide-react';
import {
  Button,
  Checkbox,
  DateRangePicker,
  DialogPrimitive as D,
  useReturnFocus,
  Field,
  IconButton,
  Input,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Select,
  cn,
  isIsoDate,
  type DateRange,
} from '@eora/ui';
import { activeFilterCount, useMediaQuery, type FilterValue, type FilterValues } from '../../data';
import { formatRange, parseRange, type FilterField } from './types';
import { optionLabel, useFieldOptions } from './use-field-options';

/* ------------------------------------------------------------------ panel state ------------------------------------------------------------------ */

export type FilterPanel = { barOpen: boolean; setBarOpen: (v: boolean) => void; sheetOpen: boolean; setSheetOpen: (v: boolean) => void };

/** Desktop bar starts expanded; the phone sheet starts closed (EORA-COMPONENT-STANDARDS §4). */
export function useFilterPanel(initiallyExpanded = true): FilterPanel {
  const [barOpen, setBarOpen] = React.useState(initiallyExpanded);
  const [sheetOpen, setSheetOpen] = React.useState(false);
  return { barOpen, setBarOpen, sheetOpen, setSheetOpen };
}

/** Toolbar trigger(s): icon toggle on ≥ 768 px, "Filters (n)" button opening the bottom sheet on phones. */
export function FilterToggle({ panel, count, controlsId }: { panel: FilterPanel; count: number; controlsId: string }) {
  return (
    <>
      <IconButton
        variant="secondary"
        aria-label={panel.barOpen ? 'Hide filters' : 'Show filters'}
        aria-pressed={panel.barOpen}
        aria-controls={controlsId}
        onClick={() => panel.setBarOpen(!panel.barOpen)}
        className="max-md:hidden"
      >
        <SlidersHorizontal aria-hidden="true" />
      </IconButton>
      <Button aria-haspopup="dialog" aria-expanded={panel.sheetOpen} onClick={() => panel.setSheetOpen(true)} className="md:hidden">
        <SlidersHorizontal aria-hidden="true" />
        Filters
        {count ? (
          <span className="ml-0.5 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-accent px-1 text-[11px] font-semibold text-on-accent tabular-nums">
            {count}
          </span>
        ) : null}
        {count ? <span className="sr-only">{` (${count} active)`}</span> : null}
      </Button>
    </>
  );
}

/* ------------------------------------------------------------------ draft logic ------------------------------------------------------------------ */

function children(fields: FilterField[], key: string): string[] {
  const direct = fields.filter((f) => 'dependsOn' in f && f.dependsOn?.includes(key)).map((f) => f.key);
  return direct.flatMap((k) => [k, ...children(fields, k)]);
}

/** Pure: set one draft value and clear dependent children (plant → warehouse → location). Exported for tests. */
export function setDraftValue(fields: FilterField[], draft: FilterValues, key: string, value: FilterValue | undefined): FilterValues {
  const next: Record<string, FilterValue | undefined> = { ...draft, [key]: value };
  for (const child of children(fields, key)) next[child] = undefined;
  return next;
}

function editable(fields: FilterField[], values: FilterValues): FilterValues {
  return Object.fromEntries(Object.entries(values).filter(([k]) => !fields.find((f) => f.key === k)?.locked));
}

/* ------------------------------------------------------------------ controls ------------------------------------------------------------------ */

type ControlProps = { field: FilterField; value: FilterValue | undefined; draft: FilterValues; onChange: (v: FilterValue | undefined) => void };

const segCls = 'relative flex items-center border-r border-line last:border-r-0';
const segInput =
  'h-9 min-w-32 cursor-pointer appearance-none border-0 bg-transparent pr-8 pl-3 text-left text-[13px] text-ink focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:text-ink-2';
const segActive = 'font-semibold text-accent';

function SegmentControl({ field, value, draft, onChange }: ControlProps) {
  const { options, loading } = useFieldOptions(field, draft);
  const str = typeof value === 'string' ? value : '';
  if (field.type === 'text' || field.type === 'search') {
    return (
      <div className={segCls}>
        <input
          aria-label={field.label}
          placeholder={field.placeholder ?? field.label}
          value={str}
          onChange={(e) => onChange(e.target.value || undefined)}
          className={cn(segInput, 'cursor-text placeholder:text-ink-2', str && segActive)}
        />
      </div>
    );
  }
  if (field.type === 'daterange') {
    return (
      <div className={segCls}>
        <DateRangePicker
          variant="segment"
          label={field.label}
          placeholder={field.label}
          active={!!str}
          value={toRange(str)}
          onChange={(r) => onChange(fromRange(r))}
        />
      </div>
    );
  }
  if (field.type === 'multiselect') {
    const selected = Array.isArray(value) ? value : value ? [value] : [];
    const text = selected.length === 0 ? field.label : selected.length === 1 ? optionLabel(options, selected[0]!) : `${field.label}: ${selected.length}`;
    return (
      <Popover>
        <div className={segCls}>
          <PopoverTrigger
            className={cn(segInput, selected.length && segActive)}
            aria-label={`${field.label}${selected.length ? `, ${selected.length} selected` : ''}`}
          >
            {text}
          </PopoverTrigger>
          <ChevronDown aria-hidden="true" className="pointer-events-none absolute right-2.5 size-4 text-ink-2" />
        </div>
        <PopoverContent align="start" aria-label={field.label} className="w-64 p-2">
          <CheckboxList legend={field.label} options={options} loading={loading} selected={selected} onChange={(v) => onChange(v.length ? v : undefined)} />
        </PopoverContent>
      </Popover>
    );
  }
  // select / entity
  return (
    <div className={segCls} title={field.locked ? 'From your workspace' : undefined}>
      <Select
        variant="segment"
        aria-label={field.locked ? `${field.label} (from your workspace)` : field.label}
        value={str}
        onValueChange={(v) => onChange(v || undefined)}
        options={options}
        placeholder={loading ? `${field.label}: loading…` : field.label}
        emptyLabel={`Any ${field.label.toLowerCase()}`}
        disabled={loading}
        locked={field.locked}
        active={!!str}
      />
    </div>
  );
}

function StackedControl({ field, value, draft, onChange }: ControlProps) {
  const { options, loading } = useFieldOptions(field, draft);
  const str = typeof value === 'string' ? value : '';
  if (field.type === 'multiselect') {
    const selected = Array.isArray(value) ? value : value ? [value] : [];
    return (
      <CheckboxList legend={field.label} options={options} loading={loading} selected={selected} onChange={(v) => onChange(v.length ? v : undefined)} touch />
    );
  }
  if (field.type === 'daterange') {
    return (
      <Field label={field.label}>{(c) => <DateRangePicker {...c} label={field.label} value={toRange(str)} onChange={(r) => onChange(fromRange(r))} />}</Field>
    );
  }
  return (
    <Field label={field.label} hint={field.locked ? 'From your workspace' : undefined}>
      {(c) =>
        field.type === 'text' || field.type === 'search' ? (
          <Input {...c} value={str} placeholder={field.placeholder} onChange={(e) => onChange(e.target.value || undefined)} />
        ) : (
          <Select
            {...c}
            value={str}
            onValueChange={(v) => onChange(v || undefined)}
            options={options}
            placeholder={loading ? 'Loading…' : `Any ${field.label.toLowerCase()}`}
            emptyLabel={`Any ${field.label.toLowerCase()}`}
            disabled={loading}
            locked={field.locked}
          />
        )
      }
    </Field>
  );
}

function CheckboxList({
  legend,
  options,
  loading,
  selected,
  onChange,
  touch,
}: {
  legend: string;
  options: { value: string; label: string }[];
  loading: boolean;
  selected: string[];
  onChange: (v: string[]) => void;
  touch?: boolean;
}) {
  return (
    <fieldset className="m-0 min-w-0 border-0 p-0">
      <legend className={cn('mb-1 text-[13px] font-medium', !touch && 'sr-only')}>{legend}</legend>
      {loading ? <p className="m-0 px-2 py-1.5 text-[13px] text-ink-2">Loading options…</p> : null}
      {options.map((o) => (
        <label
          key={o.value}
          className={cn('flex cursor-pointer items-center gap-2.5 rounded-md px-2 text-[13.5px] hover:bg-hover', touch ? 'min-h-11' : 'min-h-9')}
        >
          <Checkbox
            checked={selected.includes(o.value)}
            onChange={(e) => onChange(e.target.checked ? [...selected, o.value] : selected.filter((s) => s !== o.value))}
          />
          {o.label}
        </label>
      ))}
    </fieldset>
  );
}

/** URL values are untrusted: a malformed or stale date (`?f.date=abc..`) is dropped instead of reaching the calendar. */
export function toRange(str: string): DateRange {
  const r = parseRange(str);
  return { from: isIsoDate(r.from) ? r.from : '', to: isIsoDate(r.to) ? r.to : '' };
}

function fromRange(r: DateRange): string | undefined {
  return r.from || r.to ? formatRange(r.from, r.to) : undefined;
}

/* ------------------------------------------------------------------ FilterBar ------------------------------------------------------------------ */

type FilterBarProps = {
  id: string;
  fields: FilterField[];
  /** Applied values (from the URL). */
  values: FilterValues;
  onApply: (values: FilterValues) => void;
  panel: FilterPanel;
};

/**
 * EoraFilterBar — schema-driven; one form definition, two presentations: segmented bar (≥ 768 px) and a bottom sheet
 * on phones (dialog semantics, focus trap, Esc, scroll lock via Radix Dialog). Filtering happens server-side on Apply.
 */
export function FilterBar({ id, fields, values, onApply, panel }: FilterBarProps) {
  const isPhone = useMediaQuery('(max-width: 767px)');
  const focus = useReturnFocus();
  const [draft, setDraft] = React.useState<FilterValues>(values);
  const appliedKey = JSON.stringify(values);
  const [applied, setApplied] = React.useState(appliedKey);
  if (applied !== appliedKey) {
    // Applied values changed outside the form (chip removed, saved view, back button): restart the draft.
    setApplied(appliedKey);
    setDraft(values);
  }
  const change = (key: string) => (v: FilterValue | undefined) => setDraft((d) => setDraftValue(fields, d, key, v));
  const apply = (next: FilterValues) => {
    onApply(editable(fields, next));
    panel.setSheetOpen(false);
  };
  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    apply(draft);
  };
  const reset = () => apply({});

  if (isPhone) {
    return (
      <D.Root open={panel.sheetOpen} onOpenChange={panel.setSheetOpen}>
        <D.Portal>
          <D.Overlay className="fixed inset-0 z-70 bg-overlay" />
          <D.Content
            id={id}
            aria-describedby={undefined}
            {...focus}
            className="fixed inset-x-0 bottom-0 z-71 flex max-h-[85dvh] flex-col rounded-t-xl bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none"
          >
            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col" aria-label="Filters">
              <div className="flex min-h-14 items-center justify-between px-4">
                <D.Title className="text-base font-semibold">Filters</D.Title>
                <D.Close
                  className="inline-grid size-11 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring"
                  aria-label="Close filters"
                >
                  <X aria-hidden="true" className="size-5" />
                </D.Close>
              </div>
              <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4">
                {fields.map((f) => (
                  <StackedControl key={f.key} field={f} value={draft[f.key]} draft={draft} onChange={change(f.key)} />
                ))}
              </div>
              <div className="flex gap-2 border-t border-line px-4 pt-3 pb-[calc(16px+env(safe-area-inset-bottom))]">
                <Button className="h-12 flex-1" onClick={reset}>
                  Reset
                </Button>
                <Button type="submit" variant="primary" className="h-12 flex-1">
                  Apply
                </Button>
              </div>
            </form>
          </D.Content>
        </D.Portal>
      </D.Root>
    );
  }

  if (!panel.barOpen) return null;
  return (
    <form
      id={id}
      aria-label="Filters"
      onSubmit={submit}
      className="mx-5 mb-3 flex w-fit max-w-[calc(100%-40px)] flex-wrap items-stretch overflow-hidden rounded-md border border-line-control bg-surface max-md:hidden"
    >
      <span className="flex items-center border-r border-line bg-subtle px-3 text-[12.5px] whitespace-nowrap text-ink-2">Filter by</span>
      {fields.map((f) => (
        <SegmentControl key={f.key} field={f} value={draft[f.key]} draft={draft} onChange={change(f.key)} />
      ))}
      <div className="flex">
        <button
          type="submit"
          className="px-4 text-[13px] font-semibold text-accent hover:bg-accent-subtle focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
        >
          Apply
        </button>
        <button
          type="button"
          onClick={reset}
          className="border-l border-line px-3 text-[13px] text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
        >
          Reset
        </button>
      </div>
    </form>
  );
}

export { activeFilterCount };

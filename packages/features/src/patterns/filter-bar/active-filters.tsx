'use client';

import * as React from 'react';
import { Lock, X } from 'lucide-react';
import type { FilterValues } from '../../data';
import { parseRange, type FilterField } from './types';
import { optionLabel, useFieldOptions } from './use-field-options';

type Props = {
  fields: FilterField[];
  values: FilterValues;
  search?: string;
  onRemove: (key: string, value?: string) => void;
  onClearSearch?: () => void;
  onClearAll: () => void;
  /** e.g. "Save as view" link. */
  extra?: React.ReactNode;
};

function Chip({ field, value, values, onRemove }: { field: FilterField; value: string; values: FilterValues; onRemove?: () => void }) {
  const { options } = useFieldOptions(field, values);
  const r = field.type === 'daterange' ? parseRange(value) : null;
  const text = r ? `${r.from || '…'} – ${r.to || '…'}` : field.type === 'text' || field.type === 'search' ? `“${value}”` : optionLabel(options, value);
  const full = `${field.label}: ${text}`;
  if (field.locked || !onRemove) {
    return (
      <span title="From your workspace" className="inline-flex h-7 items-center gap-1 rounded-full bg-accent-subtle px-2.5 font-medium text-accent">
        <Lock aria-hidden="true" className="size-3" />
        <span className="sr-only">Locked filter </span>
        {full}
      </span>
    );
  }
  return (
    <span className="inline-flex h-7 items-center gap-1 rounded-full bg-accent-subtle pr-0.5 pl-2.5 font-medium text-accent">
      {full}
      <button
        type="button"
        onClick={onRemove}
        aria-label={`Remove filter ${full}`}
        className="grid size-6 place-items-center rounded-full hover:bg-accent/15 focus-visible:outline-2 focus-visible:outline-ring max-md:size-8"
      >
        <X aria-hidden="true" className="size-3.5" />
      </button>
    </span>
  );
}

/** Active-filter chips: scope chips locked, others removable; "Clear all" keeps locked scope. Renders nothing when empty. */
export function ActiveFilters({ fields, values, search, onRemove, onClearSearch, onClearAll, extra }: Props) {
  const chips = fields.flatMap((f) => {
    const v = values[f.key];
    const list = Array.isArray(v) ? v : v ? [v] : [];
    return list.map((item) => ({ f, item, multi: Array.isArray(v) }));
  });
  const removable = chips.some((c) => !c.f.locked) || !!search;
  if (!chips.length && !search) return null;
  return (
    <div
      role="group"
      aria-label="Active filters"
      className="flex min-h-10 flex-wrap items-center gap-2 border-b border-line px-5 py-2 text-[12.5px] text-ink-2 max-md:px-4"
    >
      <span>Active:</span>
      {chips.map(({ f, item, multi }) => (
        <Chip
          key={`${f.key}:${item}`}
          field={f}
          value={item}
          values={values}
          onRemove={f.locked ? undefined : () => onRemove(f.key, multi ? item : undefined)}
        />
      ))}
      {search ? (
        <span className="inline-flex h-7 items-center gap-1 rounded-full bg-accent-subtle pr-0.5 pl-2.5 font-medium text-accent">
          Search: “{search}”
          <button
            type="button"
            onClick={onClearSearch}
            aria-label={`Remove search ${search}`}
            className="grid size-6 place-items-center rounded-full hover:bg-accent/15 focus-visible:outline-2 focus-visible:outline-ring max-md:size-8"
          >
            <X aria-hidden="true" className="size-3.5" />
          </button>
        </span>
      ) : null}
      {removable ? (
        <button type="button" onClick={onClearAll} className="min-h-6 font-medium text-link hover:underline focus-visible:outline-2 focus-visible:outline-ring">
          Clear all
        </button>
      ) : null}
      {extra}
    </div>
  );
}

'use client';

import * as React from 'react';
import { Columns3, Rows3, Rows4 } from 'lucide-react';
import { Button, Checkbox, IconButton, Popover, PopoverContent, PopoverTrigger, cn } from '@eora/ui';
import type { Density } from '../../data';
import type { BulkAction, ColumnVisibility, EoraColumn } from './types';

/** Comfortable | Compact segmented control (D-029; aria-pressed). Hidden on phones, where rows reflow to cards. */
export function DensityToggle({ value, onChange }: { value: Density; onChange: (d: Density) => void }) {
  const opts: { v: Density; label: string; Icon: typeof Rows3 }[] = [
    { v: 'comfortable', label: 'Comfortable', Icon: Rows3 },
    { v: 'compact', label: 'Compact', Icon: Rows4 },
  ];
  return (
    <div role="group" aria-label="Row density" className="inline-flex overflow-hidden rounded-md border border-line-strong max-md:hidden">
      {opts.map(({ v, label, Icon }) => (
        <button
          key={v}
          type="button"
          aria-pressed={value === v}
          onClick={() => onChange(v)}
          className={cn(
            'inline-flex h-[34px] items-center gap-1.5 px-2.5 text-[12.5px] font-medium text-ink-2 not-first:border-l not-first:border-line-strong hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring max-md:h-11',
            value === v && 'bg-accent-subtle font-semibold text-accent hover:bg-accent-subtle',
          )}
        >
          <Icon aria-hidden="true" className="size-4" />
          <span className="max-xl:sr-only">{label}</span>
        </button>
      ))}
    </div>
  );
}

/** Column visibility; critical/identity columns are always shown (EORA-COMPONENT-STANDARDS §5). */
export function ColumnSettings<T>({
  columns,
  value,
  onChange,
}: {
  columns: EoraColumn<T>[];
  value: ColumnVisibility;
  onChange: (v: ColumnVisibility) => void;
}) {
  const hiddenCount = columns.filter((c) => value[c.id] === false).length;
  return (
    <Popover>
      <PopoverTrigger asChild>
        <IconButton variant="secondary" className="max-md:hidden" aria-label={hiddenCount ? `Column settings, ${hiddenCount} hidden` : 'Column settings'}>
          <Columns3 aria-hidden="true" />
        </IconButton>
      </PopoverTrigger>
      <PopoverContent aria-label="Column settings" className="w-64 p-2">
        <fieldset className="m-0 border-0 p-0">
          <legend className="px-2 pt-1 pb-2 text-xs font-semibold text-ink-2">Show columns</legend>
          {columns
            .filter((c) => !c.hideHeader)
            .map((c) => {
              const locked = c.critical || c.lead || c.hideable === false;
              return (
                <label
                  key={c.id}
                  className={cn('flex min-h-9 items-center gap-2.5 rounded-md px-2 text-[13.5px]', locked ? 'text-ink-2' : 'cursor-pointer hover:bg-hover')}
                >
                  <Checkbox
                    checked={value[c.id] !== false}
                    disabled={locked}
                    onChange={(e) => onChange({ ...value, [c.id]: e.target.checked })}
                    aria-describedby={locked ? `${c.id}-locked` : undefined}
                  />
                  <span className="flex-1">{c.header}</span>
                  {locked ? (
                    <span id={`${c.id}-locked`} className="text-xs">
                      Always shown
                    </span>
                  ) : null}
                </label>
              );
            })}
        </fieldset>
        <div className="mt-1 border-t border-line px-2 pt-2">
          <Button size="sm" variant="ghost" className="text-link" onClick={() => onChange({})}>
            Show all columns
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}

/** Bulk-action bar; permission-aware actions keep their reason visible when unavailable. */
export function BulkBar({ count, actions, selectedIds, onClear }: { count: number; actions: BulkAction[]; selectedIds: string[]; onClear: () => void }) {
  if (!count) return null;
  return (
    <div
      role="region"
      aria-label="Bulk actions"
      className="flex flex-wrap items-center gap-3 border-b border-line bg-accent-subtle px-5 py-2 text-[13px] font-medium text-accent max-md:px-4"
    >
      <span aria-live="polite">{count} selected on this page</span>
      {actions.map((a) => (
        <Button
          key={a.id}
          size="sm"
          aria-disabled={a.disabledReason ? true : undefined}
          title={a.disabledReason}
          onClick={() => {
            if (!a.disabledReason) a.onSelect(selectedIds);
          }}
        >
          {a.icon ? <a.icon aria-hidden="true" /> : null}
          {a.label}
          {a.disabledReason ? <span className="sr-only">{` — unavailable: ${a.disabledReason}`}</span> : null}
        </Button>
      ))}
      <Button size="sm" variant="link" className="ml-auto" onClick={onClear}>
        Clear selection
      </Button>
    </div>
  );
}

'use client';

import * as React from 'react';
import { Tabs as TB } from 'radix-ui';

export type StatusTabItem = { value: string; label: string; count?: number };

type StatusTabsProps = {
  /** Visible group label, e.g. "View by" (hidden on phones). */
  label: string;
  items: StatusTabItem[];
  value: string;
  onValueChange: (value: string) => void;
  /** Content of the active view (usually the shared DataTable). Rendered in one tab panel. */
  children: React.ReactNode;
  className?: string;
};

/**
 * EoraStatusTabs — saved-filter views over approved dimensions (EORA-PAGE-TEMPLATES §2).
 * Radix Tabs: arrow keys / Home / End move between views; counts are announced with the label.
 */
export function StatusTabs({ label, items, value, onValueChange, children, className }: StatusTabsProps) {
  const labelId = React.useId();
  return (
    <TB.Root value={value} onValueChange={onValueChange} className={className}>
      <div className="flex items-center gap-4 border-b border-line px-5 max-md:px-4">
        <span id={labelId} className="text-xs font-semibold tracking-[0.05em] whitespace-nowrap text-ink-2 uppercase max-md:hidden">
          {label}
        </span>
        <TB.List aria-labelledby={labelId} className="flex min-w-0 gap-1 overflow-x-auto">
          {items.map((it) => (
            <TB.Trigger
              key={it.value}
              value={it.value}
              aria-label={it.count !== undefined ? `${it.label}, ${it.count}` : undefined}
              className="group -mb-px inline-flex h-10 items-center gap-1.5 border-b-2 border-transparent px-3 text-[13.5px] font-medium whitespace-nowrap text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-ring data-[state=active]:border-accent data-[state=active]:font-semibold data-[state=active]:text-accent max-md:h-11"
            >
              {it.label}
              {it.count !== undefined ? (
                <span
                  aria-hidden="true"
                  className="rounded-full bg-stat-neutral-bg px-[7px] py-px text-[11.5px] font-semibold text-stat-neutral-fg tabular-nums group-data-[state=active]:bg-accent-subtle group-data-[state=active]:text-accent"
                >
                  {it.count}
                </span>
              ) : null}
            </TB.Trigger>
          ))}
        </TB.List>
      </div>
      {items.map((it) => (
        <TB.Content key={it.value} value={it.value} className="focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring">
          {it.value === value ? children : null}
        </TB.Content>
      ))}
    </TB.Root>
  );
}

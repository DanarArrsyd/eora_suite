'use client';

import * as React from 'react';
import { Popover as P } from 'radix-ui';
import { Check, ChevronDown, LoaderCircle, Search } from 'lucide-react';
import { cn } from '../lib/cn';
import type { SelectOption } from './select';

export type ComboboxProps = {
  value: string;
  onValueChange: (value: string) => void;
  /** Label of the chosen value, shown on the trigger even when it is not in the current results. */
  selectedLabel?: string;
  /** What the user typed; the caller looks it up (on the server for long lists) and passes the results as `options`. */
  query: string;
  onQueryChange: (query: string) => void;
  options: readonly SelectOption[];
  /** The results for the current query are on their way: the rows shown belong to an earlier search and cannot be chosen. */
  loading?: boolean;
  /** Called each time the list opens (e.g. to retry a lookup that failed). */
  onOpen?: () => void;
  /** Told under the results when the lookup failed. */
  error?: string;
  /** More rows match than are shown: ask for a more precise search. */
  more?: boolean;
  placeholder?: string;
  searchPlaceholder?: string;
  /** Adds a first item that clears the value; without it a chosen value cannot be cleared. */
  emptyLabel?: string;
  disabled?: boolean;
  id?: string;
  name?: string;
  className?: string;
  onBlur?: () => void;
  /** Focus target for form error summaries (React 19 ref prop). */
  ref?: React.Ref<HTMLButtonElement>;
  'aria-label'?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: true | boolean;
  'aria-required'?: true | boolean;
};

const NONE = '__eora_none__';

/**
 * EoraCombobox — single choice from a long list: the trigger looks like EoraSelect; opening it shows a search box over
 * the results, which the caller looks up as the user types (server-side, a page at a time). Keyboard: type to search,
 * ↑/↓ to move, Enter to choose, Esc to close; focus returns to the trigger. Use inside <Field> for label/hint/error.
 */
export function Combobox({
  value,
  onValueChange,
  selectedLabel,
  query,
  onQueryChange,
  options,
  loading,
  onOpen,
  error,
  more,
  placeholder = 'Select…',
  searchPlaceholder = 'Type to search…',
  emptyLabel,
  disabled,
  id,
  name,
  className,
  onBlur,
  ref,
  'aria-label': ariaLabel,
  'aria-describedby': describedBy,
  'aria-invalid': invalid,
  'aria-required': _required, // a button cannot be "required"; the field label carries the marker
}: ComboboxProps) {
  const [open, setOpen] = React.useState(false);
  const [active, setActive] = React.useState(0);
  const listId = React.useId();
  const items = React.useMemo(() => [...(emptyLabel ? [{ value: NONE, label: emptyLabel }] : []), ...options], [emptyLabel, options]);
  // a new set of results starts at its first row
  const [seen, setSeen] = React.useState(items);
  if (seen !== items) {
    setSeen(items);
    setActive(0);
  }
  const listRef = React.useRef<HTMLUListElement>(null);
  React.useEffect(() => {
    listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  const choose = (v: string) => {
    // rows of an earlier search stay on screen while the new one runs; choosing one could pick the wrong record
    if (loading) return;
    onValueChange(v === NONE ? '' : v);
    setOpen(false);
  };
  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (items.length) setActive((a) => (a + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const item = items[active];
      if (item && !('disabled' in item && item.disabled)) choose(item.value);
    }
  };
  const optionId = (i: number) => `${listId}-${i}`;
  const text = value ? (selectedLabel ?? options.find((o) => o.value === value)?.label ?? '') : '';

  return (
    <P.Root
      open={open}
      onOpenChange={(next) => {
        setOpen(next);
        // each opening starts from the first results, not from an earlier search
        if (next) {
          onQueryChange('');
          onOpen?.();
        } else onBlur?.();
      }}
    >
      <P.Trigger
        ref={ref}
        type="button"
        id={id}
        name={name}
        disabled={disabled}
        data-slot="combobox"
        role="combobox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-haspopup="listbox"
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        aria-invalid={invalid}
        className={cn(
          'group inline-flex h-9 w-full min-w-0 items-center justify-between gap-2 rounded-md border border-line-control bg-surface px-3 text-left text-[13.5px] text-ink outline-hidden hover:border-ink-2 focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring disabled:cursor-not-allowed disabled:border-line-strong disabled:bg-hover disabled:text-ink-2 aria-[invalid=true]:border-stat-err aria-[invalid=true]:ring-1 aria-[invalid=true]:ring-stat-err data-[state=open]:border-ring max-md:h-11 max-md:text-base',
          className,
        )}
      >
        <span className={cn('truncate', !text && 'text-ink-2')}>{text || placeholder}</span>
        <ChevronDown
          aria-hidden="true"
          className="size-4 shrink-0 text-ink-2 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none"
        />
      </P.Trigger>
      <P.Portal>
        <P.Content
          align="start"
          sideOffset={6}
          collisionPadding={12}
          className="z-90 flex max-h-[min(var(--radix-popover-content-available-height),380px)] w-[max(var(--radix-popover-trigger-width),240px)] max-w-[min(560px,calc(100vw-24px))] flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-[var(--shadow-popover)]"
        >
          <div className="flex items-center gap-2 border-b border-line px-3">
            <Search aria-hidden="true" className="size-4 shrink-0 text-ink-2" />
            <input
              role="combobox"
              aria-expanded="true"
              aria-controls={listId}
              aria-autocomplete="list"
              aria-activedescendant={items.length ? optionId(active) : undefined}
              aria-label={ariaLabel ? `Search ${ariaLabel}` : 'Search'}
              autoComplete="off"
              spellCheck={false}
              value={query}
              placeholder={searchPlaceholder}
              onChange={(e) => onQueryChange(e.target.value)}
              onKeyDown={onKeyDown}
              className="h-10 min-w-0 flex-1 bg-transparent text-[13.5px] text-ink outline-hidden placeholder:text-ink-2 max-md:h-11 max-md:text-base"
            />
            {loading ? <LoaderCircle aria-hidden="true" className="size-4 shrink-0 animate-spin text-ink-2 motion-reduce:animate-none" /> : null}
          </div>
          <ul ref={listRef} id={listId} role="listbox" aria-label={ariaLabel} aria-busy={loading || undefined} className="m-0 list-none overflow-y-auto p-1">
            {items.map((o, i) => {
              const disabledItem = 'disabled' in o && Boolean(o.disabled);
              return (
                <li
                  key={o.value}
                  id={optionId(i)}
                  role="option"
                  aria-selected={o.value === (value || NONE)}
                  aria-disabled={disabledItem || undefined}
                  data-active={i === active}
                  data-stale={loading || undefined}
                  onMouseMove={() => setActive(i)}
                  // keep focus in the search box: choosing happens on click, not on the focus change
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => (disabledItem ? undefined : choose(o.value))}
                  // keys reach the list through the search box (aria-activedescendant); this covers assistive tech that focuses an option
                  onKeyDown={(e) => (e.key === 'Enter' && !disabledItem ? choose(o.value) : undefined)}
                  className={cn(
                    'relative flex min-h-9 cursor-pointer items-center rounded-md py-1.5 pr-3 pl-8 text-[13.5px] text-ink select-none aria-selected:font-semibold data-[active=true]:bg-hover data-[stale=true]:cursor-wait data-[stale=true]:opacity-60 max-md:min-h-11',
                    o.value === NONE && 'text-ink-2',
                    disabledItem && 'cursor-not-allowed text-ink-2',
                  )}
                >
                  {o.value === (value || NONE) ? <Check aria-hidden="true" className="absolute left-2.5 size-4 text-accent" /> : null}
                  <span className="flex min-w-0 flex-col">
                    <span className="break-words">{o.label}</span>
                    {'description' in o && o.description ? <span className="text-xs font-normal text-ink-2">{o.description}</span> : null}
                  </span>
                </li>
              );
            })}
          </ul>
          {error ? (
            <p role="alert" className="m-0 border-t border-line px-3 py-2 text-[13px] text-stat-err">
              {error}
            </p>
          ) : !loading && options.length === 0 ? (
            <p className="m-0 px-3 py-2 text-[13px] text-ink-2">{query.trim() ? `Nothing matches "${query.trim()}".` : 'Nothing to choose from.'}</p>
          ) : more ? (
            <p className="m-0 border-t border-line px-3 py-2 text-[13px] text-ink-2">More match. Type more of the code or name to narrow the list.</p>
          ) : null}
        </P.Content>
      </P.Portal>
    </P.Root>
  );
}

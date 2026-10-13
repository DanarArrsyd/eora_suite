'use client';

import type * as React from 'react';
import { Select as S } from 'radix-ui';
import { Check, ChevronDown, Lock } from 'lucide-react';
import { cn } from '../lib/cn';

export type SelectOption = { value: string; label: string; description?: string; disabled?: boolean };

/** Radix items cannot carry an empty value; this sentinel stands for "no value" (the `emptyLabel` item). */
const NONE = '__eora_none__';

export type SelectProps = {
  value: string;
  onValueChange: (value: string) => void;
  options: readonly SelectOption[];
  /** Shown in the trigger while nothing is chosen. */
  placeholder?: string;
  /** Adds a first item that clears the value (e.g. "Any customer"); without it a chosen value cannot be cleared. */
  emptyLabel?: string;
  disabled?: boolean;
  /** Locked workspace scope: read-only, lock icon instead of the chevron. */
  locked?: boolean;
  /** `field`: bordered form control. `segment`: borderless cell of the segmented filter bar. `compact`: small toolbar control. */
  variant?: 'field' | 'segment' | 'compact';
  /** Highlights the trigger while a value is applied (filter bar). */
  active?: boolean;
  id?: string;
  name?: string;
  className?: string;
  contentClassName?: string;
  onBlur?: () => void;
  /** Focus target for form error summaries (React 19 ref prop). */
  ref?: React.Ref<HTMLButtonElement>;
  'aria-label'?: string;
  'aria-describedby'?: string;
  'aria-invalid'?: true | boolean;
  'aria-required'?: true | boolean;
};

const triggerBase =
  'group inline-flex w-full min-w-0 items-center justify-between gap-2 text-left text-ink outline-hidden disabled:cursor-not-allowed data-[placeholder]:text-ink-2 [&>span]:truncate';
const variants = {
  field:
    'h-9 rounded-md border border-line-control bg-surface px-3 text-[13.5px] hover:border-ink-2 focus-visible:border-ring focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring disabled:border-line-strong disabled:bg-hover disabled:text-ink-2 aria-[invalid=true]:border-stat-err aria-[invalid=true]:ring-1 aria-[invalid=true]:ring-stat-err max-md:h-11 max-md:text-base',
  segment:
    'h-9 min-w-32 border-0 bg-transparent px-3 text-[13px] hover:bg-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring disabled:hover:bg-transparent disabled:text-ink-2',
  compact:
    'h-8 rounded-md border border-line-control bg-surface px-2.5 text-[13px] hover:border-ink-2 focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-ring max-md:h-11',
} as const;

/**
 * EoraSelect — single choice from a short list (Radix Select): styled list in a popover, keyboard (arrows, Home/End,
 * typeahead, Enter, Esc), focus return, screen-reader roles. Use inside <Field> for label/hint/error wiring. Long or
 * searchable lists belong in a combobox, not here.
 */
export function Select({
  value,
  onValueChange,
  options,
  placeholder = 'Select…',
  emptyLabel,
  disabled,
  locked,
  variant = 'field',
  active,
  id,
  name,
  className,
  contentClassName,
  onBlur,
  ref,
  ...aria
}: SelectProps) {
  return (
    <S.Root
      value={value}
      onValueChange={(v) => onValueChange(v === NONE ? '' : v)}
      disabled={disabled || locked}
      name={name}
      onOpenChange={(open) => (open ? undefined : onBlur?.())}
    >
      <S.Trigger
        ref={ref}
        id={id}
        data-slot="select"
        className={cn(triggerBase, variants[variant], active && 'font-semibold text-accent', className)}
        {...aria}
      >
        <S.Value placeholder={placeholder} />
        <S.Icon asChild>
          {locked ? (
            <Lock aria-hidden="true" className="size-3.5 shrink-0 text-ink-2" />
          ) : (
            <ChevronDown
              aria-hidden="true"
              className="size-4 shrink-0 text-ink-2 transition-transform group-data-[state=open]:rotate-180 motion-reduce:transition-none"
            />
          )}
        </S.Icon>
      </S.Trigger>
      <S.Portal>
        <S.Content
          position="popper"
          sideOffset={6}
          align="start"
          className={cn(
            'z-90 max-h-[min(var(--radix-select-content-available-height),360px)] min-w-[max(var(--radix-select-trigger-width),180px)] overflow-hidden rounded-lg border border-line bg-surface shadow-[var(--shadow-popover)]',
            contentClassName,
          )}
        >
          <S.Viewport className="p-1">
            {emptyLabel ? <Item value={NONE} label={emptyLabel} muted /> : null}
            {options.map((o) => (
              <Item key={o.value} value={o.value} label={o.label} description={o.description} disabled={o.disabled} />
            ))}
          </S.Viewport>
        </S.Content>
      </S.Portal>
    </S.Root>
  );
}

function Item({ value, label, description, disabled, muted }: { value: string; label: string; description?: string; disabled?: boolean; muted?: boolean }) {
  return (
    <S.Item
      value={value}
      disabled={disabled}
      className="relative flex min-h-9 cursor-pointer items-center gap-2 rounded-md py-1.5 pr-3 pl-8 text-[13.5px] text-ink outline-hidden select-none data-[disabled]:cursor-not-allowed data-[disabled]:text-ink-2 data-[highlighted]:bg-hover data-[state=checked]:font-semibold max-md:min-h-11"
    >
      <S.ItemIndicator className="absolute left-2.5 inline-flex">
        <Check aria-hidden="true" className="size-4 text-accent" />
      </S.ItemIndicator>
      <span className="flex min-w-0 flex-col">
        <S.ItemText>
          <span className={muted ? 'text-ink-2' : undefined}>{label}</span>
        </S.ItemText>
        {description ? <span className="text-xs font-normal text-ink-2">{description}</span> : null}
      </span>
    </S.Item>
  );
}

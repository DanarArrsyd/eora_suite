'use client';

import * as React from 'react';
import { Search } from 'lucide-react';
import { cn } from '@eora/ui';

type Props = { value: string; onChange: (v: string) => void; label: string; placeholder?: string; delay?: number; className?: string };

/** Debounced server-side search (300 ms); the visible text keeps focus while the URL catches up. */
export function ListSearch({ value, onChange, label, placeholder, delay = 300, className }: Props) {
  const [text, setText] = React.useState(value);
  const [prop, setProp] = React.useState(value);
  const timer = React.useRef<ReturnType<typeof setTimeout>>(undefined);
  if (prop !== value) {
    setProp(value);
    setText(value);
  }
  React.useEffect(() => () => clearTimeout(timer.current), []);
  return (
    <label className={cn('relative flex w-[300px] items-center max-md:w-full', className)}>
      <span className="sr-only">{label}</span>
      <Search aria-hidden="true" className="pointer-events-none absolute left-3 size-4 text-ink-2" />
      <input
        type="search"
        value={text}
        placeholder={placeholder}
        onChange={(e) => {
          const v = e.target.value;
          setText(v);
          clearTimeout(timer.current);
          timer.current = setTimeout(() => onChange(v.trim()), delay);
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            clearTimeout(timer.current);
            onChange(text.trim());
          }
        }}
        className="h-9 w-full rounded-md border border-line-control bg-surface pr-3 pl-9 text-[13.5px] text-ink placeholder:text-ink-2 focus:border-ring focus:outline-2 focus:outline-ring max-md:h-11 max-md:text-base"
      />
    </label>
  );
}

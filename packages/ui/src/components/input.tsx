import * as React from 'react';
import { cn } from '../lib/cn';

const control =
  'w-full rounded-md border border-line-control bg-surface px-3 text-[13.5px] text-ink placeholder:text-ink-2 placeholder:opacity-100 focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring disabled:cursor-not-allowed disabled:border-line-strong disabled:bg-hover disabled:text-ink-2 aria-[invalid=true]:border-stat-err aria-[invalid=true]:ring-1 aria-[invalid=true]:ring-stat-err max-md:h-11 max-md:text-base';

/** EoraInput — text input; use inside <Field> for label/hint/error wiring. */
export function Input({ className, ...props }: React.ComponentPropsWithRef<'input'>) {
  return <input data-slot="input" className={cn(control, 'h-9', className)} {...props} />;
}

/** EoraSelect — native select (Phase 1); searchable combobox arrives with the filter system (Phase 2). */
export function Select({ className, children, ...props }: React.ComponentPropsWithRef<'select'>) {
  return (
    <select
      data-slot="select"
      className={cn(
        control,
        "bg-[url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%2364748B' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' viewBox='0 0 24 24'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")] h-9 appearance-none bg-[position:right_10px_center] bg-no-repeat pr-8",
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
}

/** EoraTextarea */
export function Textarea({ className, ...props }: React.ComponentPropsWithRef<'textarea'>) {
  return <textarea data-slot="textarea" className={cn(control, 'min-h-21 py-2 leading-5', className)} {...props} />;
}

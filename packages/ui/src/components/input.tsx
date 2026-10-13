import * as React from 'react';
import { cn } from '../lib/cn';

const control =
  'w-full rounded-md border border-line-control bg-surface px-3 text-[13.5px] text-ink placeholder:text-ink-2 placeholder:opacity-100 focus:border-ring focus:outline-2 focus:outline-offset-0 focus:outline-ring disabled:cursor-not-allowed disabled:border-line-strong disabled:bg-hover disabled:text-ink-2 aria-[invalid=true]:border-stat-err aria-[invalid=true]:ring-1 aria-[invalid=true]:ring-stat-err max-md:h-11 max-md:text-base';

/** EoraInput — text input; use inside <Field> for label/hint/error wiring. */
export function Input({ className, ...props }: React.ComponentPropsWithRef<'input'>) {
  return <input data-slot="input" className={cn(control, 'h-9', className)} {...props} />;
}

/** EoraTextarea */
export function Textarea({ className, ...props }: React.ComponentPropsWithRef<'textarea'>) {
  return <textarea data-slot="textarea" className={cn(control, 'min-h-21 py-2 leading-5', className)} {...props} />;
}

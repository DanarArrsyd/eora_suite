import * as React from 'react';
import { cn } from '../lib/cn';

/** EoraCard — section container; elevation only for hierarchy. */
export function Card({ className, ...props }: React.ComponentPropsWithRef<'section'>) {
  return <section data-slot="card" className={cn('min-w-0 rounded-lg border border-line bg-surface shadow-[var(--shadow-card)]', className)} {...props} />;
}
export function CardHeader({ className, ...props }: React.ComponentPropsWithRef<'div'>) {
  return <div className={cn('flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4 max-md:px-4', className)} {...props} />;
}
export function CardTitle({ className, ...props }: React.ComponentPropsWithRef<'h2'>) {
  return <h2 className={cn('m-0 text-base font-semibold leading-6', className)} {...props} />;
}
export function CardDescription({ className, ...props }: React.ComponentPropsWithRef<'p'>) {
  return <p className={cn('mt-0.5 text-xs text-ink-2', className)} {...props} />;
}
export function CardContent({ className, ...props }: React.ComponentPropsWithRef<'div'>) {
  return <div className={cn('p-5 max-md:p-4', className)} {...props} />;
}

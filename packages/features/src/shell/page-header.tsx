import * as React from 'react';
import { cn } from '@eora/ui';

type PageHeaderProps = { title: React.ReactNode; description?: React.ReactNode; meta?: React.ReactNode; actions?: React.ReactNode; className?: string };

/** EoraPageHeader — title, description, meta line (as-of/scope) and actions. Server-safe. */
export function PageHeader({ title, description, meta, actions, className }: PageHeaderProps) {
  return (
    <div className={cn('mb-5 flex flex-wrap items-start justify-between gap-4', className)}>
      <div className="min-w-0">
        <h1 className="m-0 flex flex-wrap items-center gap-3 text-2xl leading-8 font-semibold tracking-[-0.015em] max-md:text-xl max-md:leading-7">{title}</h1>
        {description ? <p className="mt-1 max-w-[760px] text-sm text-ink-2">{description}</p> : null}
        {meta ? <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-[13px] text-ink-2">{meta}</div> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
    </div>
  );
}

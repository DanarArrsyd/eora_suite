import * as React from 'react';
import { CircleAlert, Inbox, Loader2 } from 'lucide-react';
import { cn } from '../lib/cn';

type StateProps = { title: string; description?: React.ReactNode; action?: React.ReactNode; icon?: React.ReactNode; className?: string };

function StateFrame({
  title,
  description,
  action,
  icon,
  className,
  tone = 'neutral',
  role,
}: StateProps & { tone?: 'neutral' | 'error'; role?: 'alert' | 'status' }) {
  return (
    <div role={role} className={cn('flex flex-col items-center gap-2 px-6 py-10 text-center', className)}>
      <div
        className={cn(
          'grid size-11 place-items-center rounded-full [&_svg]:size-5',
          tone === 'error' ? 'bg-stat-err-bg text-stat-err-fg' : 'bg-stat-neutral-bg text-ink-2',
        )}
        aria-hidden="true"
      >
        {icon}
      </div>
      <h3 className="mt-2 text-[15px] font-semibold">{title}</h3>
      {description ? <p className="m-0 max-w-[460px] text-[13.5px] text-ink-2">{description}</p> : null}
      {action ? <div className="mt-2 flex gap-2">{action}</div> : null}
    </div>
  );
}

/** EoraEmptyState — says why it is empty and what fills it. */
export function EmptyState(props: StateProps) {
  return <StateFrame icon={props.icon ?? <Inbox />} {...props} />;
}
/** EoraErrorState — what failed and the next action; announced to assistive tech. */
export function ErrorState(props: StateProps) {
  return <StateFrame icon={props.icon ?? <CircleAlert />} tone="error" role="alert" {...props} />;
}
/** EoraLoadingState — names what is loading. */
export function LoadingState({ title, className }: { title: string; className?: string }) {
  return (
    <div role="status" aria-live="polite" className={cn('flex items-center justify-center gap-2 px-6 py-10 text-[13.5px] text-ink-2', className)}>
      <Loader2 aria-hidden="true" className="size-4 animate-spin motion-reduce:animate-none" />
      {title}
    </div>
  );
}
/** EoraSkeleton — placeholder block for known layouts. */
export function Skeleton({ className }: { className?: string }) {
  return <span aria-hidden="true" className={cn('block h-3 animate-pulse rounded bg-hover motion-reduce:animate-none', className)} />;
}
/** EoraKbd */
export function Kbd({ children, className }: { children: React.ReactNode; className?: string }) {
  return <kbd className={cn('rounded-sm border border-line px-1.5 font-sans text-[11px] leading-[18px] text-ink-2', className)}>{children}</kbd>;
}
/** Visible label for screens backed by mock adapters (doc 22 §2). */
export function MockDataLabel({ className }: { className?: string }) {
  return (
    <span className={cn('inline-flex h-[22px] items-center rounded-sm bg-stat-warn-bg px-2 text-xs font-semibold text-stat-warn-fg', className)}>
      Mock data
    </span>
  );
}

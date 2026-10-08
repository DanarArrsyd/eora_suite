import * as React from 'react';
import { CircleAlert, CircleCheck, Inbox, Info, Loader2, ShieldX, TriangleAlert } from 'lucide-react';
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

/** EoraPermissionDeniedState — names the missing permission and who can grant it; never reveals the data. */
export function PermissionDeniedState({ title = 'You do not have access to this view', description, action, className }: Partial<StateProps>) {
  return (
    <StateFrame
      icon={<ShieldX />}
      title={title}
      description={description ?? 'Ask your company administrator for the required role. No data from this view was loaded.'}
      action={action}
      className={className}
    />
  );
}

type AlertTone = 'info' | 'success' | 'warning' | 'danger';
const ALERT_ICON: Record<AlertTone, React.ReactNode> = { info: <Info />, success: <CircleCheck />, warning: <TriangleAlert />, danger: <CircleAlert /> };
const ALERT_CLS: Record<AlertTone, string> = {
  info: 'border-stat-info/40 bg-stat-info-bg text-stat-info-fg',
  success: 'border-stat-ok/40 bg-stat-ok-bg text-stat-ok-fg',
  warning: 'border-stat-warn/40 bg-stat-warn-bg text-stat-warn-fg',
  danger: 'border-stat-err/40 bg-stat-err-bg text-stat-err-fg',
};

/**
 * EoraInlineAlert — persistent message inside a page or form (stale data, validation summary, posting result).
 * `danger` is announced immediately (role=alert); other tones politely (role=status).
 */
export function InlineAlert({
  tone = 'info',
  title,
  children,
  action,
  className,
  ...props
}: { tone?: AlertTone; title: React.ReactNode; children?: React.ReactNode; action?: React.ReactNode; className?: string } & Omit<
  React.ComponentPropsWithRef<'div'>,
  'title'
>) {
  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={cn('flex items-start gap-3 rounded-md border px-4 py-3 text-[13.5px]', ALERT_CLS[tone], className)}
      {...props}
    >
      <span aria-hidden="true" className="mt-0.5 [&_svg]:size-4">
        {ALERT_ICON[tone]}
      </span>
      <div className="min-w-0 flex-1">
        <p className="m-0 font-semibold">{title}</p>
        {children ? <div className="mt-0.5 text-ink">{children}</div> : null}
      </div>
      {action ? <div className="shrink-0">{action}</div> : null}
    </div>
  );
}

import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../lib/cn';

export const badgeVariants = cva('inline-flex h-[22px] items-center gap-1 whitespace-nowrap rounded-sm px-2 text-xs font-medium leading-none [&_svg]:size-[13px]', {
  variants: {
    tone: {
      success: 'bg-stat-ok-bg text-stat-ok-fg',
      warning: 'bg-stat-warn-bg text-stat-warn-fg',
      danger: 'bg-stat-err-bg text-stat-err-fg',
      info: 'bg-stat-info-bg text-stat-info-fg',
      neutral: 'bg-stat-neutral-bg text-stat-neutral-fg',
    },
  },
  defaultVariants: { tone: 'neutral' },
});

export type BadgeProps = React.ComponentPropsWithRef<'span'> & VariantProps<typeof badgeVariants>;

/** EoraBadge / EoraStatusBadge — text is always present; colour never carries meaning alone. */
export function Badge({ className, tone, ...props }: BadgeProps) {
  return <span data-slot="badge" className={cn(badgeVariants({ tone }), className)} {...props} />;
}
export const StatusBadge = Badge;

type NumericBadgeProps = { count: number; /** Accessible text, e.g. "to action". */ label: string; className?: string };

/** EoraNumericBadge — actionable count only (D-025). Renders nothing for 0. */
export function NumericBadge({ count, label, className }: NumericBadgeProps) {
  if (!count || count < 0) return null;
  return (
    <span data-slot="numeric-badge" className={cn('inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-stat-err px-[5px] text-[11px] font-semibold leading-none text-white tabular-nums', className)}>
      <span aria-hidden="true">{count > 99 ? '99+' : count}</span>
      <span className="sr-only">{`${count} ${label}`}</span>
    </span>
  );
}

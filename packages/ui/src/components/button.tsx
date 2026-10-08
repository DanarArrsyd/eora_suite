import * as React from 'react';
import { Slot } from 'radix-ui';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '../lib/cn';

export const buttonVariants = cva(
  'inline-flex items-center justify-center gap-1.5 rounded-md border font-medium whitespace-nowrap transition-colors duration-[var(--dur-fast)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:border-line disabled:bg-hover disabled:text-ink-2 aria-disabled:cursor-not-allowed [&_svg]:size-4 [&_svg]:shrink-0',
  {
    variants: {
      variant: {
        primary: 'border-accent bg-accent text-on-accent hover:border-accent-hover hover:bg-accent-hover',
        secondary: 'border-line-strong bg-surface text-ink hover:bg-hover',
        ghost: 'border-transparent bg-transparent text-ink hover:bg-hover',
        link: 'h-auto border-0 bg-transparent p-0 text-link underline-offset-2 hover:underline',
        destructive: 'border-stat-err bg-stat-err text-white hover:bg-stat-err-fg',
      },
      size: {
        md: 'h-9 px-3.5 text-[13.5px]',
        sm: 'h-8 px-2.5 text-[13px]',
        icon: 'size-9 p-0',
        'icon-sm': 'size-8 p-0',
      },
    },
    compoundVariants: [{ variant: 'link', className: 'h-auto px-0' }],
    defaultVariants: { variant: 'secondary', size: 'md' },
  },
);

export type ButtonProps = React.ComponentPropsWithRef<'button'> & VariantProps<typeof buttonVariants> & { asChild?: boolean };

/** Icon-only button: same as Button but the accessible name is required by type. */
export type IconButtonProps = Omit<ButtonProps, 'size'> & { 'aria-label': string; size?: 'icon' | 'icon-sm' };

/** EoraButton — one primary per view region; icon-only sizes require aria-label. */
export function Button({ className, variant, size, asChild = false, type, ...props }: ButtonProps) {
  const Comp = asChild ? Slot.Root : 'button';
  return <Comp data-slot="button" type={asChild ? undefined : (type ?? 'button')} className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

/** EoraIconButton — icon-only action; `aria-label` is mandatory. */
export function IconButton({ size = 'icon', variant = 'ghost', ...props }: IconButtonProps) {
  return <Button size={size} variant={variant} {...props} />;
}

'use client';

import * as React from 'react';
import { Dialog as D, Popover as P, Tooltip as T, DropdownMenu as M, Tabs as TB } from 'radix-ui';
import { X } from 'lucide-react';
import { cn } from '../lib/cn';
import { useReturnFocus } from '../lib/return-focus';

/* ---------------- Tooltip (hover + focus; supplementary only) ---------------- */
export const TooltipProvider = T.Provider;
type TooltipProps = { content: React.ReactNode; side?: 'top' | 'right' | 'bottom' | 'left'; children: React.ReactElement };
export function Tooltip({ content, side = 'top', children }: TooltipProps) {
  return (
    <T.Root>
      <T.Trigger asChild>{children}</T.Trigger>
      <T.Portal>
        <T.Content side={side} sideOffset={8} className="z-60 rounded-sm bg-ink px-2 py-1 text-xs font-medium text-white shadow-[var(--shadow-popover)]">
          {content}
        </T.Content>
      </T.Portal>
    </T.Root>
  );
}

/* ---------------- Popover ---------------- */
export const Popover = P.Root;
export const PopoverTrigger = P.Trigger;
export function PopoverContent({ className, align = 'end', ...props }: React.ComponentPropsWithRef<typeof P.Content>) {
  return (
    <P.Portal>
      <P.Content
        align={align}
        sideOffset={6}
        className={cn('z-50 w-72 rounded-lg border border-line bg-surface p-1 shadow-[var(--shadow-popover)] focus-visible:outline-none', className)}
        {...props}
      />
    </P.Portal>
  );
}

/* ---------------- DropdownMenu ---------------- */
export const DropdownMenu = M.Root;
export const DropdownMenuTrigger = M.Trigger;
export const DropdownMenuGroup = M.Group;
export const DropdownMenuRadioGroup = M.RadioGroup;
export function DropdownMenuContent({ className, align = 'end', ...props }: React.ComponentPropsWithRef<typeof M.Content>) {
  return (
    <M.Portal>
      <M.Content
        align={align}
        sideOffset={6}
        className={cn('z-50 min-w-56 rounded-lg border border-line bg-surface p-1 shadow-[var(--shadow-popover)]', className)}
        {...props}
      />
    </M.Portal>
  );
}
const itemCls =
  'flex min-h-9 w-full cursor-pointer select-none items-center gap-2 rounded-md px-3 text-[13.5px] text-ink outline-none data-[highlighted]:bg-hover data-[highlighted]:ring-2 data-[highlighted]:ring-inset data-[highlighted]:ring-ring data-[disabled]:cursor-not-allowed data-[disabled]:text-ink-2 max-md:min-h-11 [&_svg]:size-4 [&_svg]:shrink-0';
export function DropdownMenuItem({ className, ...props }: React.ComponentPropsWithRef<typeof M.Item>) {
  return <M.Item className={cn(itemCls, className)} {...props} />;
}
export function DropdownMenuRadioItem({ className, children, ...props }: React.ComponentPropsWithRef<typeof M.RadioItem>) {
  return (
    <M.RadioItem className={cn(itemCls, 'relative pl-8', className)} {...props}>
      <M.ItemIndicator className="absolute left-3 inline-flex size-2 rounded-full bg-accent" />
      {children}
    </M.RadioItem>
  );
}
export function DropdownMenuLabel({ className, ...props }: React.ComponentPropsWithRef<typeof M.Label>) {
  return <M.Label className={cn('px-3 pt-1.5 pb-1 text-xs font-medium text-ink-2', className)} {...props} />;
}
export function DropdownMenuSeparator({ className, ...props }: React.ComponentPropsWithRef<typeof M.Separator>) {
  return <M.Separator className={cn('my-1 h-px bg-line', className)} {...props} />;
}

/* ---------------- Modal (Dialog) ---------------- */
export const Modal = D.Root;
export const ModalTrigger = D.Trigger;
export const ModalClose = D.Close;
type ModalContentProps = React.ComponentPropsWithRef<typeof D.Content> & {
  title: React.ReactNode;
  description?: React.ReactNode;
  hideTitle?: boolean;
  size?: 'md' | 'lg';
  /** Accessible name of the header close button; omit to hide it (e.g. confirmations with explicit Cancel). */
  closeLabel?: string;
};
/** EoraModal — focus trap, Esc, scroll lock and focus return handled by Radix Dialog. Compose with ModalBody / ModalFooter. */
export function ModalContent({
  className,
  title,
  description,
  hideTitle,
  size = 'md',
  closeLabel,
  children,
  onOpenAutoFocus,
  onCloseAutoFocus,
  ...props
}: ModalContentProps) {
  const focus = useReturnFocus({ onOpenAutoFocus, onCloseAutoFocus });
  return (
    <D.Portal>
      <D.Overlay className="fixed inset-0 z-80 bg-overlay" />
      <D.Content
        className={cn(
          'fixed top-[10vh] left-1/2 z-80 flex max-h-[80vh] w-[calc(100vw-32px)] -translate-x-1/2 flex-col overflow-hidden rounded-lg bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none max-md:inset-0 max-md:top-0 max-md:h-dvh max-md:max-h-none max-md:w-full max-md:translate-x-0 max-md:rounded-none',
          size === 'md' ? 'max-w-xl' : 'max-w-3xl',
          className,
        )}
        {...(description ? {} : { 'aria-describedby': undefined })}
        {...props}
        {...focus}
      >
        <div className={cn('flex items-start justify-between gap-3 px-5 pt-5 pb-2', hideTitle && 'sr-only')}>
          <D.Title className="m-0 text-base font-semibold">{title}</D.Title>
          {closeLabel ? (
            <D.Close
              className="-mt-1.5 -mr-1.5 inline-grid size-9 shrink-0 place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring max-md:size-11"
              aria-label={closeLabel}
            >
              <X aria-hidden="true" className="size-4" />
            </D.Close>
          ) : null}
        </div>
        {description ? <D.Description className="sr-only">{description}</D.Description> : null}
        {children}
      </D.Content>
    </D.Portal>
  );
}
export function ModalBody({ className, ...props }: React.ComponentPropsWithRef<'div'>) {
  return <div className={cn('min-h-0 flex-1 overflow-y-auto px-5 pt-1 pb-5 text-[13.5px]', className)} {...props} />;
}
export function ModalFooter({ className, ...props }: React.ComponentPropsWithRef<'div'>) {
  return (
    <div
      className={cn('flex flex-wrap justify-end gap-2 border-t border-line px-5 py-3 max-md:pb-[calc(12px+env(safe-area-inset-bottom))]', className)}
      {...props}
    />
  );
}

/* ---------------- Drawer (side sheet) ---------------- */
export const Drawer = D.Root;
export const DrawerTrigger = D.Trigger;
export const DrawerClose = D.Close;
type DrawerContentProps = React.ComponentPropsWithRef<typeof D.Content> & {
  title: React.ReactNode;
  side?: 'left' | 'right';
  closeLabel?: string;
  hideHeader?: boolean;
};
/** EoraDrawer — side sheet with overlay, Esc, focus trap/return, scroll lock (Radix Dialog). */
export function DrawerContent({
  className,
  title,
  side = 'right',
  closeLabel = 'Close',
  hideHeader,
  children,
  onOpenAutoFocus,
  onCloseAutoFocus,
  ...props
}: DrawerContentProps) {
  const focus = useReturnFocus({ onOpenAutoFocus, onCloseAutoFocus });
  return (
    <D.Portal>
      <D.Overlay className="fixed inset-0 z-70 bg-overlay" />
      <D.Content
        aria-describedby={undefined}
        className={cn(
          'fixed top-0 bottom-0 z-71 flex w-[520px] max-w-full flex-col bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none',
          side === 'right' ? 'right-0' : 'left-0',
          className,
        )}
        {...props}
        {...focus}
      >
        <div className={cn('flex items-start justify-between gap-3 border-b border-line p-5', hideHeader && 'sr-only')}>
          <D.Title className="text-lg font-semibold">{title}</D.Title>
          <D.Close
            className="inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring"
            aria-label={closeLabel}
          >
            <X aria-hidden="true" className="size-4" />
          </D.Close>
        </div>
        {children}
      </D.Content>
    </D.Portal>
  );
}
export { D as DialogPrimitive };

/* ---------------- Tabs ---------------- */
export const Tabs = TB.Root;
export function TabsList({ className, ...props }: React.ComponentPropsWithRef<typeof TB.List>) {
  return <TB.List className={cn('flex gap-1 overflow-x-auto border-b border-line', className)} {...props} />;
}
export function TabsTrigger({ className, ...props }: React.ComponentPropsWithRef<typeof TB.Trigger>) {
  return (
    <TB.Trigger
      className={cn(
        '-mb-px inline-flex h-10 items-center gap-1.5 border-b-2 border-transparent px-3 text-[13.5px] font-medium whitespace-nowrap text-ink-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-ring data-[state=active]:border-accent data-[state=active]:font-semibold data-[state=active]:text-accent max-md:h-11',
        className,
      )}
      {...props}
    />
  );
}
export const TabsContent = TB.Content;

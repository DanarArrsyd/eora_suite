'use client';

import * as React from 'react';

/**
 * Focus return for Radix dialogs opened from state (no Trigger): remember the element focused when the dialog opened
 * and restore it on close (WCAG 2.4.3). Dialogs opened by their own Trigger keep Radix's default behaviour.
 */
export function useReturnFocus(handlers?: { onOpenAutoFocus?: (e: Event) => void; onCloseAutoFocus?: (e: Event) => void }) {
  const previous = React.useRef<HTMLElement | null>(null);
  return {
    onOpenAutoFocus: (e: Event) => {
      previous.current = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
      handlers?.onOpenAutoFocus?.(e);
    },
    onCloseAutoFocus: (e: Event) => {
      handlers?.onCloseAutoFocus?.(e);
      if (e.defaultPrevented) return;
      const el = previous.current;
      if (el && el.isConnected) {
        e.preventDefault();
        el.focus();
      }
    },
  };
}

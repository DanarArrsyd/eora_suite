import * as React from 'react';
import { createPortal } from 'react-dom';
import { FormPage } from '@eora/features/patterns';
import { useUnsavedGuard } from './unsaved-guard';

type Guard = ReturnType<typeof useUnsavedGuard>;

/**
 * A form page opened from within another page (a routing's operation, a BOM line, a Daily Recovery plan): the same
 * `FormPage` template (D-140), laid over the page's content area while the URL names it (`?form=…`), so it can be
 * reloaded or shared and the browser's Back closes it. The page underneath stays mounted but inert.
 */
export function FormLayer({
  open,
  title,
  description,
  back,
  facts,
  aside,
  width,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  description?: React.ReactNode;
  back: { label: string; href: string };
  facts?: { label: string; value: React.ReactNode }[];
  aside?: React.ReactNode;
  width?: 'default' | 'wide';
  onClose: () => void;
  /** The form: wire `guard.setDirty` to EoraForm `onDirtyChange`, `guard.leave` to Cancel and `guard.saved` after a save. */
  children: (guard: Guard) => React.ReactNode;
}) {
  const guard = useUnsavedGuard({ active: open, backHref: back.href, onClose });
  // the shell's content area exists only in the browser: the server renders no layer
  const client = React.useSyncExternalStore(
    noSubscribe,
    () => true,
    () => false,
  );
  const host = client ? document.getElementById('main') : null;
  const layer = React.useRef<HTMLDivElement>(null);
  React.useEffect(() => {
    // runs once the layer is in the page (after hydration on a direct link too)
    const main = open && host ? document.getElementById('main') : null;
    if (!main || !layer.current) return;
    // the control that opened the form gets focus back when it closes, as a drawer's did (WCAG 2.4.3)
    const opener = document.activeElement instanceof HTMLElement && !layer.current.contains(document.activeElement) ? document.activeElement : null;
    // the page under the form keeps its state but takes no focus or reading while the form is open
    const others = Array.from(main.children).filter((c) => c !== layer.current?.parentElement && c !== layer.current);
    for (const el of others) el.setAttribute('inert', '');
    const overflow = main.style.overflow;
    main.scrollTop = 0;
    main.style.overflow = 'hidden';
    // the title takes focus (as a drawer's did), so it is announced and Tab starts in the form
    const heading = layer.current?.querySelector<HTMLElement>('h1');
    heading?.setAttribute('tabindex', '-1');
    heading?.focus({ preventScroll: true });
    return () => {
      for (const el of others) el.removeAttribute('inert');
      main.style.overflow = overflow;
      if (opener?.isConnected && opener !== document.body) opener.focus({ preventScroll: true });
    };
  }, [open, host]);
  if (!open || !host) return null;
  return createPortal(
    <div ref={layer} role="region" aria-label={title} className="absolute inset-0 z-30 overflow-auto bg-[var(--color-bg-workspace)]">
      <FormPage
        title={title}
        description={description}
        back={{
          label: back.label,
          href: back.href,
          onNavigate: (e) => {
            e.preventDefault();
            guard.leave();
          },
        }}
        facts={facts}
        aside={aside}
        width={width}
      >
        {children(guard)}
      </FormPage>
      {guard.dialog}
    </div>,
    host,
  );
}

const noSubscribe = () => () => undefined;

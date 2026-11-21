import * as React from 'react';
import { useRouter } from 'next/navigation';
import { setNavigationGuard } from '@eora/features/navigation';
import { ConfirmDialog } from '@eora/features/patterns';

/**
 * The unsaved-changes guard of every form page (D-140, EORA-PAGE-TEMPLATES §6): while the form differs from what was
 * loaded, every way out asks "Leave without saving?" first — Back and Cancel (`leave`), any in-app link, navigation in
 * code (global search), the browser's Back — and the browser itself warns on reload or close.
 *
 * `active` is false for forms that are not pages (dialogs). `backHref` is where the form came from (the list or the
 * document); `onClose` leaves the form when nothing is unsaved or the user discards.
 */
export function useUnsavedGuard({ active, backHref, onClose }: { active: boolean; backHref: string; onClose: () => void }) {
  const router = useRouter();
  const [dirty, setDirty] = React.useState(false);
  const [leaveTo, setLeaveTo] = React.useState<string | null>(null);
  const released = React.useRef(false);
  const dirtyRef = React.useRef(false);
  React.useEffect(() => {
    dirtyRef.current = dirty;
  }, [dirty]);
  // one guard entry of this same page per form, pushed the first time it gets dirty and marked in its history state, so a
  // reload on it reuses it: the browser's Back lands on the form's own entry and the page asks before going further back;
  // once the form is clean again, that Back simply carries on
  const guarded = React.useRef(false);
  React.useEffect(() => {
    // a form laid over its page (FormLayer) keeps this hook mounted between openings: each opening starts afresh, so a
    // later edit pushes its own guard entry again
    if (!active) {
      guarded.current = false;
      released.current = false;
      return;
    }
    if (onGuardEntry()) guarded.current = true;
  }, [active]);
  React.useEffect(() => {
    if (!dirty || !active || guarded.current) return;
    guarded.current = true;
    if (!onGuardEntry()) pushGuardEntry();
  }, [dirty, active]);
  React.useEffect(() => {
    if (!active) return;
    let hash = window.location.hash;
    const onPop = () => {
      // a move within the page (`#main`) changes only the fragment: it is not a way out
      if (window.location.hash !== hash) {
        hash = window.location.hash;
        return;
      }
      if (!guarded.current || released.current || onGuardEntry()) return;
      if (!dirtyRef.current) {
        guarded.current = false;
        window.history.back();
        return;
      }
      pushGuardEntry();
      setLeaveTo('history');
    };
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [active]);
  React.useEffect(() => {
    if (!dirty || !active) return;
    released.current = false;
    const warn = (e: BeforeUnloadEvent) => e.preventDefault();
    const onClick = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const link = e.target instanceof Element ? e.target.closest('a[href]') : null;
      if (!(link instanceof HTMLAnchorElement) || link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-form-back')) return;
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin) return;
      // a link within this page (e.g. Skip to main content, `#main`) does not leave it
      if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;
      e.preventDefault();
      e.stopPropagation();
      setLeaveTo(url.pathname + url.search + url.hash);
    };
    // navigation in code (the global search) asks through the same dialog
    const release = setNavigationGuard((href) => {
      setLeaveTo(href);
      return true;
    });
    window.addEventListener('beforeunload', warn);
    document.addEventListener('click', onClick, true);
    return () => {
      release();
      window.removeEventListener('beforeunload', warn);
      document.removeEventListener('click', onClick, true);
    };
  }, [dirty, active]);

  const leave = () => (dirty && active ? setLeaveTo('close') : onClose());
  const discard = () => {
    const target = leaveTo;
    released.current = true;
    setLeaveTo(null);
    setDirty(false);
    // the browser's Back: go to the page the form came from, however many entries (in-page jumps, the guard) lie behind
    if (target === 'history') router.replace(backHref);
    else if (target && target !== 'close') router.push(target);
    else onClose();
  };
  /** After a save: nothing is unsaved any more, so leaving no longer asks. */
  const saved = () => setDirty(false);

  const dialog = (
    <ConfirmDialog
      open={leaveTo !== null}
      onOpenChange={(open) => (open ? undefined : setLeaveTo(null))}
      title="Leave without saving?"
      description="Your changes on this form are not saved yet. Leaving discards them."
      confirmLabel="Discard changes"
      tone="danger"
      onConfirm={async () => discard()}
    />
  );
  return { dirty, setDirty, leave, saved, dialog };
}

/** Whether the current history entry is a form page's guard entry (kept across reloads in its history state). */
function onGuardEntry(): boolean {
  const state: unknown = window.history.state;
  return typeof state === 'object' && state !== null && (state as { eoraFormGuard?: boolean }).eoraFormGuard === true;
}

function pushGuardEntry(): void {
  const state: unknown = window.history.state;
  window.history.pushState({ ...(typeof state === 'object' && state !== null ? state : {}), eoraFormGuard: true }, '', window.location.href);
}

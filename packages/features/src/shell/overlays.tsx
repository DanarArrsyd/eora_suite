'use client';

import * as React from 'react';
import Link from 'next/link';
import { ChevronLeft, ChevronRight, Menu, Search, X } from 'lucide-react';
import { DialogPrimitive, EmptyState, Kbd, NumericBadge, cn } from '@eora/ui';
import { moduleHref, type BadgeCounts, type NavModule } from '../navigation';
import { ModuleMenu } from './sidebars';

/* ===================== EoraGlobalSearch dialog (shell; results arrive in Phase 2) ===================== */
type SearchDialogProps = { open: boolean; onOpenChange: (open: boolean) => void; returnFocusTo?: React.RefObject<HTMLElement | null> };

/** Command-style search dialog. Phase 1 delivers the shell (shortcut, focus, a11y); Phase 2 connects the permission-aware search adapter. */
export function GlobalSearchDialog({ open, onOpenChange, returnFocusTo }: SearchDialogProps) {
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-80 bg-overlay" />
        <DialogPrimitive.Content
          aria-describedby="global-search-scope"
          onCloseAutoFocus={(e) => {
            // Opened by keyboard shortcut or a non-Radix trigger: return focus to where the user was (WCAG 2.4.3).
            if (returnFocusTo?.current) {
              e.preventDefault();
              returnFocusTo.current.focus();
            }
          }}
          className="fixed left-1/2 top-[10vh] z-80 flex max-h-[75vh] w-[calc(100vw-32px)] max-w-[600px] -translate-x-1/2 flex-col overflow-hidden rounded-lg bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none max-md:inset-0 max-md:top-0 max-md:h-dvh max-md:max-h-none max-md:w-full max-md:max-w-none max-md:translate-x-0 max-md:rounded-none"
        >
          <DialogPrimitive.Title className="sr-only">Search records</DialogPrimitive.Title>
          <SearchBody />
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

/** Mounted only while the dialog is open, so the query resets on close without effects. */
function SearchBody() {
  const [query, setQuery] = React.useState('');
  return (
    <>
          <div className="flex items-center gap-3 border-b border-line pl-4 pr-2">
            <Search aria-hidden="true" className="size-5 text-ink-2" />
            <input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              aria-label="Search records"
              placeholder="Search Customer PO, Sales Order, WO, part, material, lot, run, inspection lot, delivery order"
              className="h-13 min-w-0 flex-1 bg-transparent text-[15px] outline-none placeholder:text-ink-2 max-md:text-base"
            />
            <DialogPrimitive.Close className="inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring max-md:size-11" aria-label="Close search">
              <X aria-hidden="true" className="size-4" />
            </DialogPrimitive.Close>
          </div>
          <div className="overflow-y-auto">
            <EmptyState
              title={query ? `No search service connected yet` : 'Search across EORA records'}
              description="Results, debounced queries and loading/error states are delivered in Phase 2 with a permission-aware mock adapter. Nothing is searched in Phase 1."
            />
          </div>
          <div id="global-search-scope" className="flex flex-wrap justify-between gap-3 border-t border-line px-4 py-2 text-xs text-ink-2">
            <span>Results will only include records you may view in this tenant.</span>
            <span className="flex items-center gap-1"><Kbd>Esc</Kbd> to close</span>
          </div>
    </>
  );
}

/* ===================== Secondary menu overlay (768–1279 px) ===================== */
type SecondaryOverlayProps = { open: boolean; onOpenChange: (open: boolean) => void; module: NavModule | undefined; counts: BadgeCounts; pathname: string; returnFocusTo?: React.RefObject<HTMLElement | null> };

/** Below 1280 px the module menu opens as an overlay next to the primary sidebar (backdrop, Esc, focus return). */
export function SecondaryOverlay({ open, onOpenChange, module, counts, pathname, returnFocusTo }: SecondaryOverlayProps) {
  if (!module) return null;
  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 left-[var(--shell-primary-w)] z-44 bg-overlay" />
        <DialogPrimitive.Content
          aria-describedby={undefined}
          onCloseAutoFocus={(e) => {
            if (returnFocusTo?.current) {
              e.preventDefault();
              returnFocusTo.current.focus();
            }
          }}
          className="fixed bottom-0 left-[var(--shell-primary-w)] top-0 z-45 flex w-[var(--shell-secondary-w)] flex-col bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none">
          <div className="flex h-[var(--shell-topbar-h)] shrink-0 items-center justify-between border-b border-line pl-4 pr-3">
            <DialogPrimitive.Title className="flex items-center gap-2 text-[13px] font-semibold uppercase tracking-[0.06em]">
              <Menu aria-hidden="true" className="size-4" />
              {module.label}
            </DialogPrimitive.Title>
            <DialogPrimitive.Close className="inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring" aria-label="Close module menu">
              <X aria-hidden="true" className="size-4" />
            </DialogPrimitive.Close>
          </div>
          <div className="overflow-y-auto px-2 pb-6 pt-2">
            <ModuleMenu module={module} counts={counts} pathname={pathname} onNavigate={() => onOpenChange(false)} />
          </div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

/* ===================== Mobile navigation sheet (< 768 px) ===================== */
type MobileNavProps = {
  modules: NavModule[];
  activeModule: NavModule | undefined;
  counts: BadgeCounts;
  badgeFor: (m: NavModule) => number;
  pathname: string;
};

/**
 * Labelled "Menu" trigger + drill-down sheet (current module menu ⇄ module list).
 * Radix Dialog provides aria-modal, focus trap, Esc, scroll lock and focus return (navigation doc §9).
 */
export function MobileNavSheet({ modules, activeModule, counts, badgeFor, pathname }: MobileNavProps) {
  const [open, setOpen] = React.useState(false);
  const [level, setLevel] = React.useState<'menu' | 'modules'>('menu');
  const [moduleId, setModuleId] = React.useState<string | undefined>(activeModule?.id);
  const closeRef = React.useRef<HTMLButtonElement>(null);
  const shown = modules.find((m) => m.id === moduleId) ?? activeModule ?? modules[0];

  const onOpenChange = (next: boolean) => {
    if (next) {
      setLevel('menu');
      setModuleId(activeModule?.id);
    }
    setOpen(next);
  };

  React.useEffect(() => {
    const mq = window.matchMedia('(min-width: 768px)');
    const onChange = (e: MediaQueryListEvent) => e.matches && setOpen(false);
    mq.addEventListener('change', onChange);
    return () => mq.removeEventListener('change', onChange);
  }, []);

  return (
    <DialogPrimitive.Root open={open} onOpenChange={onOpenChange}>
      <DialogPrimitive.Trigger className="inline-flex h-11 items-center gap-1.5 rounded-md pl-1.5 pr-2.5 text-sm font-semibold hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring md:hidden">
        <Menu aria-hidden="true" className="size-5" />
        Menu
      </DialogPrimitive.Trigger>
      <DialogPrimitive.Portal>
        <DialogPrimitive.Overlay className="fixed inset-0 z-46 bg-overlay" />
        <DialogPrimitive.Content
          aria-describedby={undefined}
          onOpenAutoFocus={(e) => {
            e.preventDefault();
            closeRef.current?.focus();
          }}
          className="fixed bottom-0 left-0 top-0 z-47 flex w-[min(340px,88vw)] flex-col bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none"
        >
          <div className="flex h-[var(--shell-topbar-h)] shrink-0 items-center justify-between bg-sidebar pl-4 pr-2 text-white">
            <DialogPrimitive.Title className="flex items-center gap-2 font-semibold">
              <span aria-hidden="true" className="grid size-[30px] place-items-center rounded-lg bg-accent text-[13px] font-bold">E</span>
              EORA Suite
            </DialogPrimitive.Title>
            <DialogPrimitive.Close ref={closeRef} className="inline-grid size-11 place-items-center rounded-md text-white hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white" aria-label="Close navigation">
              <X aria-hidden="true" className="size-5" />
            </DialogPrimitive.Close>
          </div>
          <div className="flex-1 overflow-y-auto overscroll-contain p-2">
            {level === 'modules' || !shown ? (
              <>
                <p id="mnav-modules" className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-[0.06em] text-ink-2">Modules</p>
                <ul aria-labelledby="mnav-modules">
                  {modules.map((m) => {
                    const n = badgeFor(m);
                    const Icon = m.icon;
                    return (
                      <li key={m.id}>
                        <button
                          type="button"
                          data-mod={m.id}
                          aria-current={m.id === shown?.id ? 'true' : undefined}
                          onClick={() => {
                            setModuleId(m.id);
                            setLevel('menu');
                          }}
                          className={cn('flex min-h-12 w-full items-center gap-3 rounded-md px-3 text-left text-[15px] font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring', m.id === shown?.id && 'bg-selected font-semibold text-accent')}
                        >
                          <Icon aria-hidden="true" className="size-5" />
                          <span className="flex-1">{m.label}</span>
                          {n ? <NumericBadge count={n} label="to action" /> : null}
                          <ChevronRight aria-hidden="true" className="size-4 text-ink-2" />
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </>
            ) : (
              <>
                <button type="button" onClick={() => setLevel('modules')} className="flex min-h-12 w-full items-center gap-2 rounded-md px-2 text-sm font-semibold text-link hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring">
                  <ChevronLeft aria-hidden="true" className="size-4" />
                  All modules
                </button>
                <p className="flex items-center justify-between px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-[0.06em] text-ink-2">
                  {shown.label}
                  <Link href={moduleHref(shown.id)} onClick={() => setOpen(false)} className="inline-flex min-h-8 items-center normal-case tracking-normal text-link">Module overview</Link>
                </p>
                <ModuleMenu module={shown} counts={counts} pathname={pathname} size="touch" onNavigate={() => setOpen(false)} />
              </>
            )}
          </div>
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

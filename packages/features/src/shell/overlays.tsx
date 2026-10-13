'use client';

import * as React from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ChevronLeft, ChevronRight, CornerDownLeft, Menu, Search, X } from 'lucide-react';
import { DialogPrimitive, EmptyState, Kbd, NumericBadge, cn } from '@eora/ui';
import { buildPageIndex, moduleHref, searchPages, type BadgeCounts, type NavModule } from '../navigation';
import { ModuleMenu } from './sidebars';

/* ===================== EoraGlobalSearch dialog ===================== */
type SearchDialogProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Modules the user may open (already permission-filtered navigation). */
  modules: readonly NavModule[];
  returnFocusTo?: React.RefObject<HTMLElement | null>;
};

/**
 * Global search (Ctrl/⌘ K): jump to any page the user may open — ranked matches on page names, their initials (MPS, WO)
 * and module/group names, with the matched text highlighted. WAI-ARIA combobox + listbox: arrows move, Enter opens,
 * Esc closes. Record search joins here when the module APIs exist; nothing is presented as a record match before that.
 */
export function GlobalSearchDialog({ open, onOpenChange, modules, returnFocusTo }: SearchDialogProps) {
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
          className="fixed top-[12vh] left-1/2 z-80 flex max-h-[70vh] w-[calc(100vw-32px)] max-w-[640px] -translate-x-1/2 flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none max-md:inset-0 max-md:top-0 max-md:h-dvh max-md:max-h-none max-md:w-full max-md:max-w-none max-md:translate-x-0 max-md:rounded-none max-md:border-0"
        >
          <DialogPrimitive.Title className="sr-only">Search</DialogPrimitive.Title>
          <SearchBody modules={modules} onDone={() => onOpenChange(false)} />
        </DialogPrimitive.Content>
      </DialogPrimitive.Portal>
    </DialogPrimitive.Root>
  );
}

function Highlight({ text, ranges }: { text: string; ranges: [number, number][] }) {
  if (!ranges.length) return <>{text}</>;
  const parts: React.ReactNode[] = [];
  let at = 0;
  ranges.forEach(([a, b], i) => {
    if (a > at) parts.push(text.slice(at, a));
    parts.push(
      <mark key={i} className="rounded-[2px] bg-accent-subtle font-semibold text-accent">
        {text.slice(a, b)}
      </mark>,
    );
    at = b;
  });
  if (at < text.length) parts.push(text.slice(at));
  return <>{parts}</>;
}

/** Mounted only while the dialog is open, so the query resets on close without effects. */
function SearchBody({ modules, onDone }: { modules: readonly NavModule[]; onDone: () => void }) {
  const router = useRouter();
  const [query, setQuery] = React.useState('');
  const [active, setActive] = React.useState(0);
  const listId = React.useId();
  const index = React.useMemo(() => buildPageIndex(modules), [modules]);
  const hits = React.useMemo(() => searchPages(index, query), [index, query]);
  const shown = query.trim() ? hits : index.filter((e) => e.kind === 'module').map((e) => ({ ...e, score: 0, ranges: [] as [number, number][] }));
  const current = Math.min(active, Math.max(shown.length - 1, 0));
  const listRef = React.useRef<HTMLUListElement>(null);

  React.useEffect(() => {
    listRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [current]);

  const open = (href: string) => {
    onDone();
    router.push(href);
  };
  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (!shown.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      setActive((current + (e.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length);
    } else if (e.key === 'Home' && e.ctrlKey) {
      setActive(0);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      open(shown[current]!.href);
    }
  };

  return (
    <>
      <div className="flex h-14 shrink-0 items-center gap-3 border-b border-line pr-2 pl-4 max-md:h-16">
        <Search aria-hidden="true" className="size-5 shrink-0 text-ink-2" />
        <input
          autoFocus
          role="combobox"
          aria-expanded={shown.length > 0}
          aria-controls={listId}
          aria-activedescendant={shown.length ? `${listId}-${current}` : undefined}
          aria-autocomplete="list"
          aria-label="Search"
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setActive(0);
          }}
          onKeyDown={onKeyDown}
          placeholder="Search pages, e.g. work orders, MPS, delivery"
          autoComplete="off"
          spellCheck={false}
          className="h-full min-w-0 flex-1 border-0 bg-transparent text-[15px] text-ink outline-none placeholder:text-ink-2 focus:outline-none focus-visible:outline-none max-md:text-base"
        />
        {query ? (
          <button
            type="button"
            onClick={() => {
              setQuery('');
              setActive(0);
            }}
            className="h-8 rounded-md px-2 text-[13px] text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring"
          >
            Clear
          </button>
        ) : null}
        <DialogPrimitive.Close
          className="inline-grid size-9 shrink-0 place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring max-md:size-11"
          aria-label="Close search"
        >
          <X aria-hidden="true" className="size-4" />
        </DialogPrimitive.Close>
      </div>
      {/* focusable so the result list scrolls by keyboard too (axe scrollable-region-focusable) */}
      <div
        tabIndex={0}
        aria-label="Search results"
        role="region"
        className="min-h-0 flex-1 overflow-y-auto p-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
      >
        {shown.length ? (
          <>
            <p className="m-0 px-2 pt-1 pb-1.5 text-xs font-medium text-ink-2" aria-live="polite">
              {query.trim() ? `${hits.length} ${hits.length === 1 ? 'page' : 'pages'}` : 'Modules'}
            </p>
            <ul ref={listRef} id={listId} role="listbox" aria-label="Search results" className="m-0 list-none p-0">
              {shown.map((h, i) => {
                const Icon = h.icon;
                return (
                  // Keyboard selection runs on the combobox input (aria-activedescendant); the click is the pointer shortcut.
                  // eslint-disable-next-line jsx-a11y/click-events-have-key-events
                  <li
                    key={h.id}
                    id={`${listId}-${i}`}
                    role="option"
                    aria-selected={i === current}
                    onMouseMove={() => setActive(i)}
                    onClick={() => open(h.href)}
                    className={cn(
                      'flex min-h-12 cursor-pointer items-center gap-3 rounded-md px-2.5 py-2',
                      i === current ? 'bg-selected ring-1 ring-ring ring-inset' : 'hover:bg-hover',
                    )}
                  >
                    <span className="inline-grid size-8 shrink-0 place-items-center rounded-md border border-line bg-surface text-ink-2">
                      <Icon aria-hidden="true" className="size-4" />
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col">
                      <span className="truncate text-[13.5px] text-ink">
                        <Highlight text={h.label} ranges={h.ranges} />
                      </span>
                      <span className={cn('truncate text-xs', i === current ? 'text-ink-2s' : 'text-ink-2')}>{h.kind === 'module' ? 'Module' : h.path}</span>
                    </span>
                    {i === current ? <CornerDownLeft aria-hidden="true" className="size-4 shrink-0 text-ink-2" /> : null}
                  </li>
                );
              })}
            </ul>
          </>
        ) : (
          <EmptyState
            title={`No page matches “${query.trim()}”`}
            description="Try another word or a page initial such as WO or MPS. Only pages your roles can open are searched."
          />
        )}
      </div>
      <div id="global-search-scope" className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-subtle px-4 py-2 text-xs text-ink-2">
        <span>Pages you can open in this workspace. Record search (PO, WO, lots) comes with each module’s live data.</span>
        <span className="flex items-center gap-2 max-md:hidden">
          <span className="flex items-center gap-1">
            <Kbd>↑</Kbd>
            <Kbd>↓</Kbd> move
          </span>
          <span className="flex items-center gap-1">
            <Kbd>↵</Kbd> open
          </span>
          <span className="flex items-center gap-1">
            <Kbd>Esc</Kbd> close
          </span>
        </span>
      </div>
    </>
  );
}

/* ===================== Secondary menu overlay (768–1279 px) ===================== */
type SecondaryOverlayProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  module: NavModule | undefined;
  counts: BadgeCounts;
  pathname: string;
  returnFocusTo?: React.RefObject<HTMLElement | null>;
};

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
          className="fixed top-0 bottom-0 left-[var(--shell-primary-w)] z-45 flex w-[var(--shell-secondary-w)] flex-col bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none"
        >
          <div className="flex h-[var(--shell-topbar-h)] shrink-0 items-center justify-between border-b border-line pr-3 pl-4">
            <DialogPrimitive.Title className="flex items-center gap-2 text-[13px] font-semibold tracking-[0.06em] uppercase">
              <Menu aria-hidden="true" className="size-4" />
              {module.label}
            </DialogPrimitive.Title>
            <DialogPrimitive.Close
              className="inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring"
              aria-label="Close module menu"
            >
              <X aria-hidden="true" className="size-4" />
            </DialogPrimitive.Close>
          </div>
          <div className="overflow-y-auto px-2 pt-2 pb-6">
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
      <DialogPrimitive.Trigger className="inline-flex h-11 items-center gap-1.5 rounded-md pr-2.5 pl-1.5 text-sm font-semibold hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring md:hidden">
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
          className="fixed top-0 bottom-0 left-0 z-47 flex w-[min(340px,88vw)] flex-col bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none"
        >
          <div className="flex h-[var(--shell-topbar-h)] shrink-0 items-center justify-between bg-sidebar pr-2 pl-4 text-white">
            <DialogPrimitive.Title className="flex items-center gap-2 font-semibold">
              <span aria-hidden="true" className="grid size-[30px] place-items-center rounded-lg bg-accent text-[13px] font-bold">
                E
              </span>
              EORA Suite
            </DialogPrimitive.Title>
            <DialogPrimitive.Close
              ref={closeRef}
              className="inline-grid size-11 place-items-center rounded-md text-white hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white"
              aria-label="Close navigation"
            >
              <X aria-hidden="true" className="size-5" />
            </DialogPrimitive.Close>
          </div>
          <div className="flex-1 overflow-y-auto overscroll-contain p-2">
            {level === 'modules' || !shown ? (
              <>
                <p id="mnav-modules" className="px-3 pt-3 pb-1 text-xs font-semibold tracking-[0.06em] text-ink-2 uppercase">
                  Modules
                </p>
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
                          className={cn(
                            'flex min-h-12 w-full items-center gap-3 rounded-md px-3 text-left text-[15px] font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring',
                            m.id === shown?.id && 'bg-selected font-semibold text-accent',
                          )}
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
                <button
                  type="button"
                  onClick={() => setLevel('modules')}
                  className="flex min-h-12 w-full items-center gap-2 rounded-md px-2 text-sm font-semibold text-link hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring"
                >
                  <ChevronLeft aria-hidden="true" className="size-4" />
                  All modules
                </button>
                <p className="flex items-center justify-between px-3 pt-3 pb-1 text-xs font-semibold tracking-[0.06em] text-ink-2 uppercase">
                  {shown.label}
                  <Link
                    href={moduleHref(shown.id)}
                    onClick={() => setOpen(false)}
                    className="inline-flex min-h-8 items-center tracking-normal text-link normal-case"
                  >
                    Module overview
                  </Link>
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

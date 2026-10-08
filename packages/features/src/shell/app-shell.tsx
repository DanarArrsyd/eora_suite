'use client';

import * as React from 'react';
import { FlaskConical } from 'lucide-react';
import { TooltipProvider, cn } from '@eora/ui';
import { trailForPath } from '../navigation';
import { GlobalSearchDialog, MobileNavSheet, SecondaryOverlay } from './overlays';
import { PrimarySidebar, SecondarySidebar } from './sidebars';
import { Topbar } from './topbar';
import { useShellNavigation } from './use-navigation';

/**
 * EoraAppShell — primary sidebar (modules) + secondary sidebar (grouped features) + topbar + workspace.
 * Docked secondary ≥ 1280 px; overlay 768–1279 px; mobile sheet < 768 px (navigation doc §2, §8–9).
 */
export function AppShell({ children }: { children: React.ReactNode }) {
  const nav = useShellNavigation();
  const [collapsed, setCollapsed] = React.useState(false);
  const [overlayOpen, setOverlayOpen] = React.useState(false);
  const [searchOpen, setSearchOpenState] = React.useState(false);
  const returnFocus = React.useRef<HTMLElement | null>(null);
  /** Remember the focused element so controlled dialogs can restore focus on close. */
  const rememberFocus = () => {
    returnFocus.current = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
  };
  const setSearchOpen = React.useCallback((open: boolean) => {
    if (open) rememberFocus();
    setSearchOpenState(open);
  }, []);

  React.useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setSearchOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [setSearchOpen]);

  const isOverlayWidth = () => window.matchMedia('(max-width: 1279px)').matches;
  const toggleSecondary = () => {
    if (!isOverlayWidth()) return setCollapsed((c) => !c);
    rememberFocus();
    setOverlayOpen((o) => !o);
  };
  const previewModule = (id: string) => {
    nav.setMenuModuleId(id);
    if (isOverlayWidth()) {
      rememberFocus();
      setOverlayOpen(true);
    } else setCollapsed(false);
  };

  return (
    <TooltipProvider delayDuration={300}>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-90 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2 focus:font-semibold focus:shadow-[var(--shadow-popover)]"
      >
        Skip to main content
      </a>
      <div
        className={cn(
          'grid h-dvh grid-rows-[auto_minmax(0,1fr)] overflow-x-clip',
          'grid-cols-[minmax(0,1fr)] md:grid-cols-[var(--shell-primary-w)_minmax(0,1fr)]',
          collapsed ? 'xl:grid-cols-[var(--shell-primary-w)_minmax(0,1fr)]' : 'xl:grid-cols-[var(--shell-primary-w)_var(--shell-secondary-w)_minmax(0,1fr)]',
        )}
      >
        <div role="note" className="col-span-full flex items-center gap-2 border-b border-line bg-stat-warn-bg px-4 py-1.5 text-xs text-stat-warn-fg">
          <FlaskConical aria-hidden="true" className="size-4 shrink-0" />
          <span>
            <b className="font-semibold">EORA Suite · Phase 1 foundation build.</b> Navigation badges and workspace values come from mock adapters; no
            operational data is shown.
          </span>
        </div>
        <div className="hidden md:block">
          <PrimarySidebar modules={nav.modules} activeModuleId={nav.activeModuleId} badgeFor={nav.badgeFor} onPreview={previewModule} />
        </div>
        {!collapsed ? (
          <div className="hidden xl:block">
            <SecondarySidebar module={nav.menuModule} counts={nav.counts} pathname={nav.pathname} onClose={() => setCollapsed(true)} />
          </div>
        ) : null}
        <div className="flex min-h-0 min-w-0 flex-col">
          <Topbar
            trail={trailForPath(nav.match, nav.pathname)}
            context={nav.match?.item?.topbarContext}
            actionCount={nav.counts.bell ?? 0}
            onOpenSearch={() => setSearchOpen(true)}
            onToggleSecondary={toggleSecondary}
            mobileMenuTrigger={
              <MobileNavSheet modules={nav.modules} activeModule={nav.match?.module} counts={nav.counts} badgeFor={nav.badgeFor} pathname={nav.pathname} />
            }
          />
          <main id="main" tabIndex={-1} className="min-h-0 flex-1 overflow-auto focus:outline-none">
            {children}
          </main>
        </div>
      </div>
      <SecondaryOverlay
        open={overlayOpen}
        onOpenChange={setOverlayOpen}
        module={nav.menuModule}
        counts={nav.counts}
        pathname={nav.pathname}
        returnFocusTo={returnFocus}
      />
      <GlobalSearchDialog open={searchOpen} onOpenChange={setSearchOpen} returnFocusTo={returnFocus} />
    </TooltipProvider>
  );
}

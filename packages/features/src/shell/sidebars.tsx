'use client';

import * as React from 'react';
import Link from 'next/link';
import { ChevronDown, Menu, PanelLeftClose } from 'lucide-react';
import { cn, IconButton, NumericBadge, Tooltip } from '@eora/ui';
import { itemBadge, itemHref, moduleHref, type BadgeCounts, type NavModule } from '../navigation';

/* ===================== EoraPrimarySidebar — modules only ===================== */
type PrimaryProps = {
  modules: NavModule[];
  activeModuleId: string | undefined;
  badgeFor: (m: NavModule) => number;
  onPreview: (moduleId: string) => void;
};

/** Slim icon rail. Roving focus with ↑/↓, → moves into the module menu. Tooltip on hover and focus. */
export function PrimarySidebar({ modules, activeModuleId, badgeFor, onPreview }: PrimaryProps) {
  const refs = React.useRef<(HTMLAnchorElement | null)[]>([]);
  const ordered = [...modules.filter((m) => !m.pinBottom), ...modules.filter((m) => m.pinBottom)];

  const onKeyDown = (e: React.KeyboardEvent, index: number) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const next = (index + (e.key === 'ArrowDown' ? 1 : -1) + ordered.length) % ordered.length;
      refs.current[next]?.focus();
    }
    if (e.key === 'ArrowRight') {
      e.preventDefault();
      document.querySelector<HTMLElement>('[data-module-menu] a, [data-module-menu] button')?.focus();
    }
  };

  const renderItem = (m: NavModule) => {
    const index = ordered.indexOf(m);
    const n = badgeFor(m);
    const Icon = m.icon;
    const active = m.id === activeModuleId;
    return (
      <li key={m.id}>
        <Tooltip side="right" content={n ? `${m.label} · ${n} to action` : m.label}>
          <Link
            ref={(el) => {
              refs.current[index] = el;
            }}
            href={moduleHref(m.id)}
            onClick={() => onPreview(m.id)}
            onKeyDown={(e) => onKeyDown(e, index)}
            aria-label={n ? `${m.label}, ${n} to action` : m.label}
            aria-current={active ? 'true' : undefined}
            className={cn(
              'relative grid size-11 place-items-center rounded-lg text-sidebar-ink transition-colors duration-[var(--dur-fast)] hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white',
              active && 'bg-accent text-white',
            )}
          >
            <Icon aria-hidden="true" className="size-5" />
            {n ? <NumericBadge count={n} label="to action" className="absolute top-0.5 right-0 shadow-[0_0_0_2px_var(--color-bg-sidebar-primary)]" /> : null}
          </Link>
        </Tooltip>
      </li>
    );
  };

  return (
    <nav aria-label="Modules" className="flex h-full flex-col items-center gap-0.5 overflow-x-hidden overflow-y-auto bg-sidebar py-3 [scrollbar-width:none]">
      <div role="img" aria-label="EORA Suite" className="mb-3 grid size-9 shrink-0 place-items-center rounded-lg bg-accent text-[15px] font-bold text-white">
        E
      </div>
      <ul className="flex w-full flex-col items-center gap-0.5">{modules.filter((m) => !m.pinBottom).map(renderItem)}</ul>
      <div className="flex-1" />
      <ul className="flex w-full flex-col items-center gap-0.5">{modules.filter((m) => m.pinBottom).map(renderItem)}</ul>
    </nav>
  );
}

/* ===================== Module menu (secondary sidebar body, overlay and mobile sheet) ===================== */
type MenuProps = { module: NavModule; counts: BadgeCounts; pathname: string; size?: 'desktop' | 'touch'; onNavigate?: () => void };

/** Grouped feature menu; groups collapsible; aria-current from the route. */
export function ModuleMenu({ module, counts, pathname, size = 'desktop', onNavigate }: MenuProps) {
  const [collapsed, setCollapsed] = React.useState<Record<string, boolean>>({});
  const baseId = React.useId();
  return (
    <div data-module-menu className="flex flex-col gap-2">
      {module.groups.map((g, gi) => {
        const listId = `${baseId}-g${gi}`;
        const open = !collapsed[g.label];
        return (
          <div key={g.label}>
            <button
              type="button"
              aria-expanded={open}
              aria-controls={listId}
              onClick={() => setCollapsed((c) => ({ ...c, [g.label]: open }))}
              className="flex min-h-8 w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-xs font-semibold text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring max-md:min-h-10"
            >
              <span>{g.label}</span>
              <ChevronDown aria-hidden="true" className={cn('size-4 transition-transform duration-[var(--dur-fast)]', !open && '-rotate-90')} />
            </button>
            <ul id={listId} hidden={!open} className="mt-0.5">
              {g.items.map((i) => {
                const href = itemHref(module.id, i.id);
                const current = pathname === href;
                const n = itemBadge(i, counts);
                const Icon = i.icon;
                return (
                  <li key={i.id}>
                    <Link
                      href={href}
                      onClick={onNavigate}
                      aria-current={current ? 'page' : undefined}
                      className={cn(
                        'flex items-center gap-2 rounded-md px-3 py-1.5 font-medium text-ink hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring',
                        size === 'touch' ? 'min-h-12 text-[15px]' : 'min-h-9 text-[13.5px]',
                        current && 'bg-selected font-semibold text-accent',
                      )}
                    >
                      <Icon aria-hidden="true" className={cn('size-4 shrink-0', current ? 'text-accent' : 'text-ink-2')} />
                      <span className="min-w-0 flex-1">{i.label}</span>
                      {n ? <NumericBadge count={n} label="to action" /> : null}
                    </Link>
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </div>
  );
}

/* ===================== EoraSecondarySidebar (docked ≥ 1280 px) ===================== */
type SecondaryProps = { module: NavModule | undefined; counts: BadgeCounts; pathname: string; onClose: () => void; closeLabel?: string };

export function SecondarySidebar({ module, counts, pathname, onClose, closeLabel = 'Collapse module menu' }: SecondaryProps) {
  if (!module) return null;
  return (
    <nav aria-label={`${module.label} menu`} className="flex h-full min-w-0 flex-col overflow-hidden border-r border-line bg-surface">
      <div className="flex h-[var(--shell-topbar-h)] shrink-0 items-center justify-between border-b border-line pr-3 pl-4">
        <span className="flex min-w-0 items-center gap-2 truncate text-[13px] font-semibold tracking-[0.06em] uppercase">
          <Menu aria-hidden="true" className="size-4" />
          {module.label}
        </span>
        <IconButton aria-label={closeLabel} onClick={onClose} className="text-ink-2">
          <PanelLeftClose aria-hidden="true" />
        </IconButton>
      </div>
      <div className="overflow-y-auto px-2 pt-2 pb-6">
        <ModuleMenu module={module} counts={counts} pathname={pathname} />
      </div>
    </nav>
  );
}

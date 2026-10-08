'use client';

import * as React from 'react';
import Link from 'next/link';
import { Bell, Building2, Calendar, ChevronDown, ChevronRight, ChevronsUpDown, LogOut, Menu, PanelLeft, Search, User, Warehouse } from 'lucide-react';
import {
  Badge, cn, DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem,
  DropdownMenuSeparator, DropdownMenuTrigger, EmptyState, Field, IconButton, Kbd, NumericBadge, Popover, PopoverContent, PopoverTrigger, Select,
} from '@eora/ui';
import { ROLE_LABELS, type RoleKey, type TopbarContext } from '../navigation';
import { useShellSession } from './session';

/* ===================== Breadcrumb ===================== */
export type Crumb = { label: string; href?: string };

/** EoraBreadcrumb — first › (middle collapses to … below 1600 px) › current. Phones show the current page only. */
export function Breadcrumb({ trail }: { trail: Crumb[] }) {
  if (!trail.length) return <nav aria-label="Breadcrumb" className="min-w-0 flex-1" />;
  const first = trail[0]!;
  const middle = trail.slice(1, -1);
  const current = trail[trail.length - 1]!;
  const sep = <ChevronRight aria-hidden="true" className="size-3.5 shrink-0 text-ink-muted max-md:hidden" />;
  return (
    <nav aria-label="Breadcrumb" className="flex min-w-[80px] flex-1 items-center overflow-hidden">
      <ol className="flex min-w-0 items-center gap-1.5 whitespace-nowrap text-xs font-medium uppercase tracking-[0.05em] text-ink-2 max-md:text-sm max-md:normal-case max-md:tracking-normal">
        {trail.length > 1 ? (
          <li className="flex items-center gap-1.5 max-md:hidden">
            {first.href ? <Link href={first.href} className="inline-flex min-h-6 items-center hover:text-ink">{first.label}</Link> : <span>{first.label}</span>}
            {sep}
          </li>
        ) : null}
        {middle.map((c) => (
          <li key={c.label} className="hidden items-center gap-1.5 3xl:flex">
            {c.href ? <Link href={c.href} className="inline-flex min-h-6 items-center hover:text-ink">{c.label}</Link> : <span>{c.label}</span>}
            {sep}
          </li>
        ))}
        {middle.length ? (
          <li className="flex items-center gap-1.5 max-md:hidden 3xl:hidden" title={middle.map((m) => m.label).join(' / ')}>
            <span aria-hidden="true">…</span>
            <span className="sr-only">{middle.map((m) => m.label).join(', ')}</span>
            {sep}
          </li>
        ) : null}
        <li className="min-w-0 truncate font-semibold text-ink" aria-current="page">
          {current.label}
        </li>
      </ol>
    </nav>
  );
}

/* ===================== Global search triggers (D-028) ===================== */
type SearchTriggerProps = { hasContext: boolean; onOpen: () => void };

/** Full input ≥1600 · compact 1440–1599 · compact 1200–1439 only when the page has no topbar context · icon otherwise. */
export function GlobalSearchTriggers({ hasContext, onOpen }: SearchTriggerProps) {
  const field = 'h-9 items-center gap-2 rounded-md border border-line-control bg-surface px-3 text-left text-[13px] text-ink-2 hover:border-ink-2 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring';
  return (
    <>
      <button type="button" data-search="full" onClick={onOpen} aria-keyshortcuts="Control+K Meta+K" className={cn(field, 'hidden w-[260px] 3xl:flex')}>
        <Search aria-hidden="true" className="size-4 shrink-0" />
        <span className="flex-1 truncate">Search PO, SO, WO, lot, part…</span>
        <Kbd>Ctrl K</Kbd>
      </button>
      <button
        type="button"
        data-search="compact"
        onClick={onOpen}
        aria-label="Search records"
        aria-keyshortcuts="Control+K Meta+K"
        className={cn(field, 'hidden 3xl:hidden', hasContext ? 'w-[200px] xxl:flex' : 'w-[180px] wide:flex xxl:w-[200px]')}
      >
        <Search aria-hidden="true" className="size-4 shrink-0" />
        <span className="flex-1 truncate">Search…</span>
      </button>
      <IconButton data-search="icon" aria-label="Search records" aria-keyshortcuts="Control+K Meta+K" onClick={onOpen} className={cn('text-ink-2 max-md:size-11', hasContext ? 'xxl:hidden' : 'wide:hidden')}>
        <Search aria-hidden="true" className="!size-5" />
      </IconButton>
    </>
  );
}

/* ===================== Page context (period / warehouse) ===================== */
function ContextFields({ kind }: { kind: TopbarContext }) {
  return kind === 'period' ? (
    <div className="flex flex-col gap-3 p-3">
      <Field label="Production date">{(p) => <Select {...p} defaultValue="2026-10-08"><option value="2026-10-08">08 Oct 2026</option><option value="2026-10-07">07 Oct 2026</option></Select>}</Field>
      <Field label="Shift" hint="Shift cut-off follows the plant shift calendar.">{(p) => <Select {...p} defaultValue="all"><option value="all">All shifts</option><option value="S1">S1 07:00–15:00</option><option value="S2">S2 15:00–23:00</option><option value="S3">S3 23:00–07:00</option></Select>}</Field>
    </div>
  ) : (
    <div className="p-3">
      <Field label="Warehouse">{(p) => <Select {...p} defaultValue="RM-WH1"><option>RM-WH1</option><option>RM-WH2</option></Select>}</Field>
    </div>
  );
}

const ctxButton = 'flex h-10 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring';

export function PageContextControl({ kind }: { kind: TopbarContext }) {
  const Icon = kind === 'period' ? Calendar : Warehouse;
  const label = kind === 'period' ? 'Production date' : 'Warehouse';
  const value = kind === 'period' ? '08 Oct · All shifts' : 'RM-WH1';
  return (
    <Popover>
      <PopoverTrigger className={cn(ctxButton, 'max-md:hidden')} aria-label={`${label}: ${value}. Change`}>
        <Icon aria-hidden="true" className="size-4 shrink-0 text-ink-2" />
        <span className="flex flex-col text-left leading-tight max-lg:hidden">
          <span className="text-[11px] font-normal text-ink-2s">{label}</span>
          <span>{value}</span>
        </span>
        <ChevronDown aria-hidden="true" className="size-4 text-ink-2" />
      </PopoverTrigger>
      <PopoverContent aria-label={label}>
        <ContextFields kind={kind} />
      </PopoverContent>
    </Popover>
  );
}

/* ===================== Workspace switcher (company + plant) ===================== */
function WorkspaceFields() {
  return (
    <div className="flex flex-col gap-3 p-3">
      <Field label="Company">{(p) => <Select {...p} defaultValue="PT Sample Manufaktur"><option>PT Sample Manufaktur</option><option>PT Sample Komponen</option></Select>}</Field>
      <Field label="Plant" hint="Only companies and plants assigned to you are listed. Switching reloads data in the new scope.">
        {(p) => <Select {...p} defaultValue="Plant A · Cikarang"><option>Plant A · Cikarang</option><option>Plant B · Karawang</option></Select>}
      </Field>
    </div>
  );
}

export function WorkspaceSwitcher() {
  const { workspace } = useShellSession();
  return (
    <Popover>
      <PopoverTrigger className={cn(ctxButton, 'max-md:hidden')} aria-label={`Workspace: ${workspace.company}, ${workspace.plant}. Change`}>
        <Building2 aria-hidden="true" className="size-4 shrink-0 text-ink-2" />
        <span className="flex max-w-[160px] flex-col text-left leading-tight max-lg:hidden">
          <span className="truncate text-[11px] font-normal text-ink-2s">{workspace.company}</span>
          <span className="truncate">{workspace.plant}</span>
        </span>
        <ChevronsUpDown aria-hidden="true" className="size-4 text-ink-2" />
      </PopoverTrigger>
      <PopoverContent aria-label="Workspace">
        <WorkspaceFields />
      </PopoverContent>
    </Popover>
  );
}

/** Phones: company, plant and page context move into one overflow popover. */
export function TopbarOverflow({ context }: { context: TopbarContext | undefined }) {
  return (
    <Popover>
      <PopoverTrigger className="inline-grid size-11 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring md:hidden" aria-label="Company, plant and page context">
        <Building2 aria-hidden="true" className="size-5" />
      </PopoverTrigger>
      <PopoverContent aria-label="Company, plant and page context" className="w-[min(320px,calc(100vw-16px))]">
        <WorkspaceFields />
        {context ? (
          <>
            <div className="mx-3 h-px bg-line" />
            <ContextFields kind={context} />
          </>
        ) : null}
      </PopoverContent>
    </Popover>
  );
}

/* ===================== Notification bell (panel list arrives in Phase 2) ===================== */
export function NotificationBell({ actionCount }: { actionCount: number }) {
  return (
    <Popover>
      <PopoverTrigger
        className="relative inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring max-md:size-11"
        aria-label={actionCount ? `Notifications: ${actionCount} need action` : 'Notifications'}
      >
        <Bell aria-hidden="true" className="size-5" />
        {actionCount ? <NumericBadge count={actionCount} label="need action" className="absolute -right-1 -top-0.5 shadow-[0_0_0_2px_#fff]" /> : null}
      </PopoverTrigger>
      <PopoverContent aria-label="Notifications" className="w-[min(400px,calc(100vw-16px))] p-0">
        <div className="flex items-center justify-between border-b border-line px-4 py-3">
          <h2 className="text-[15px] font-semibold">Notifications</h2>
          <Badge tone="warning">Mock data</Badge>
        </div>
        <EmptyState
          title={actionCount ? `${actionCount} item${actionCount === 1 ? '' : 's'} need your action` : 'Nothing needs your action'}
          description="The notification list, filters and deep links are delivered in Phase 2 (Notification Center UI). Counts here come from the mock navigation adapter."
        />
      </PopoverContent>
    </Popover>
  );
}

/* ===================== User menu (profile; prototype role switch) ===================== */
export function UserMenu() {
  const { user, role, setRole } = useShellSession();
  return (
    // Non-modal: a modal menu aria-hides focusable page content (axe aria-hidden-focus); Esc / outside click still close it.
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger className="flex min-h-11 items-center gap-2 rounded-md px-1 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring" aria-label={`Account menu, ${user.name}, ${ROLE_LABELS[role]}`}>
        <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-accent-subtle text-xs font-semibold text-accent">{user.initials}</span>
        <span className="hidden text-left leading-tight 4xl:block">
          <span className="block text-[13px] font-semibold">{user.name}</span>
          <span className="block text-[11.5px] text-ink-2s">{ROLE_LABELS[role]}</span>
        </span>
        <ChevronDown aria-hidden="true" className="size-4 text-ink-2 max-lg:hidden" />
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-64">
        <DropdownMenuLabel>{user.name} · {ROLE_LABELS[role]}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuLabel>Prototype: view navigation as role</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={role} onValueChange={(v) => setRole(v as RoleKey)}>
          {(Object.keys(ROLE_LABELS) as RoleKey[]).map((r) => (
            <DropdownMenuRadioItem key={r} value={r}>{ROLE_LABELS[r]}</DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem disabled><User aria-hidden="true" />Profile & devices (Phase 2)</DropdownMenuItem>
        <DropdownMenuItem disabled><LogOut aria-hidden="true" />Sign out (auth not connected)</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/* ===================== Topbar assembly ===================== */
type TopbarProps = {
  trail: Crumb[];
  context: TopbarContext | undefined;
  actionCount: number;
  onOpenSearch: () => void;
  onToggleSecondary: () => void;
  mobileMenuTrigger: React.ReactNode;
};

export function Topbar({ trail, context, actionCount, onOpenSearch, onToggleSecondary, mobileMenuTrigger }: TopbarProps) {
  return (
    <header className="flex h-[var(--shell-topbar-h)] min-w-0 shrink-0 items-center gap-2 border-b border-line bg-surface pl-3 pr-4 max-md:gap-0.5 max-md:px-2 [&>*:not(nav)]:shrink-0">
      {mobileMenuTrigger}
      <IconButton aria-label="Show or hide module menu" onClick={onToggleSecondary} className="text-ink-2 max-md:hidden">
        <PanelLeft aria-hidden="true" />
      </IconButton>
      <Breadcrumb trail={trail} />
      <GlobalSearchTriggers hasContext={Boolean(context)} onOpen={onOpenSearch} />
      {context ? <PageContextControl kind={context} /> : null}
      <WorkspaceSwitcher />
      <TopbarOverflow context={context} />
      <span aria-hidden="true" className="mx-1 h-6 w-px bg-line max-md:hidden" />
      <NotificationBell actionCount={actionCount} />
      <UserMenu />
    </header>
  );
}

export { Menu as MenuIcon };

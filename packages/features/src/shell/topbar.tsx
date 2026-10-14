'use client';

import * as React from 'react';
import Link from 'next/link';
import { Bell, Building2, Calendar, ChevronDown, ChevronRight, ChevronsUpDown, LogOut, Menu, PanelLeft, Search, User, Warehouse } from 'lucide-react';
import {
  cn,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Field,
  IconButton,
  InlineAlert,
  Kbd,
  NumericBadge,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Calendar as DateGrid,
  Select,
  formatDate,
  type IsoDate,
} from '@eora/ui';
import { ROLE_LABELS, type RoleKey, type TopbarContext } from '../navigation';
import { NotificationCenter, useInbox } from '../patterns/notifications';
import { useShellSession, type ShellIdentity } from './session';

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
      <ol className="flex min-w-0 items-center gap-1.5 text-xs font-medium tracking-[0.05em] whitespace-nowrap text-ink-2 uppercase max-md:text-sm max-md:tracking-normal max-md:normal-case">
        {trail.length > 1 ? (
          <li className="flex items-center gap-1.5 max-md:hidden">
            {first.href ? (
              <Link href={first.href} className="inline-flex min-h-6 items-center hover:text-ink">
                {first.label}
              </Link>
            ) : (
              <span>{first.label}</span>
            )}
            {sep}
          </li>
        ) : null}
        {middle.map((c) => (
          <li key={c.label} className="hidden items-center gap-1.5 3xl:flex">
            {c.href ? (
              <Link href={c.href} className="inline-flex min-h-6 items-center hover:text-ink">
                {c.label}
              </Link>
            ) : (
              <span>{c.label}</span>
            )}
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
  const field =
    'h-9 items-center gap-2 rounded-md border border-line-control bg-surface px-3 text-left text-[13px] text-ink-2 hover:border-ink-2 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring';
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
      <IconButton
        data-search="icon"
        aria-label="Search records"
        aria-keyshortcuts="Control+K Meta+K"
        onClick={onOpen}
        className={cn('text-ink-2 max-md:size-11', hasContext ? 'xxl:hidden' : 'wide:hidden')}
      >
        <Search aria-hidden="true" className="!size-5" />
      </IconButton>
    </>
  );
}

/* ===================== Page context (period / warehouse) ===================== */
/** Prototype page context (mock data): one shared value for the topbar control and the phone overflow panel. */
type PageContextValue = { date: IsoDate; shift: string; warehouse: string };
const SAMPLE_BUSINESS_DATE: IsoDate = '2026-10-09';
const SHIFTS = [
  { value: 'all', label: 'All shifts' },
  { value: 'S1', label: 'S1 07:00–15:00' },
  { value: 'S2', label: 'S2 15:00–23:00' },
  { value: 'S3', label: 'S3 23:00–07:00', description: 'Ends on the next calendar day' },
];
const WAREHOUSES = [
  { value: 'RM-WH1', label: 'RM-WH1' },
  { value: 'RM-WH2', label: 'RM-WH2' },
];
let pageContext: PageContextValue = { date: SAMPLE_BUSINESS_DATE, shift: 'all', warehouse: 'RM-WH1' };
const pageContextListeners = new Set<() => void>();
function setPageContext(patch: Partial<PageContextValue>) {
  pageContext = { ...pageContext, ...patch };
  pageContextListeners.forEach((l) => l());
}
function usePageContextValue(): PageContextValue {
  return React.useSyncExternalStore(
    (l) => {
      pageContextListeners.add(l);
      return () => pageContextListeners.delete(l);
    },
    () => pageContext,
    () => pageContext,
  );
}

function ContextFields({ kind }: { kind: TopbarContext }) {
  const ctx = usePageContextValue();
  return kind === 'period' ? (
    <div className="flex flex-col gap-3 p-3">
      <div className="flex flex-col gap-1.5">
        <p className="m-0 text-[13px] font-medium text-ink">Production date</p>
        <DateGrid
          mode="single"
          label="Production date"
          value={ctx.date}
          onChange={(date) => setPageContext({ date })}
          max={SAMPLE_BUSINESS_DATE}
          today={SAMPLE_BUSINESS_DATE}
        />
      </div>
      <Field label="Shift" hint="Shift cut-off follows the plant shift calendar.">
        {(p) => <Select {...p} value={ctx.shift} onValueChange={(shift) => setPageContext({ shift })} options={SHIFTS} />}
      </Field>
    </div>
  ) : (
    <div className="p-3">
      <Field label="Warehouse">
        {(p) => <Select {...p} value={ctx.warehouse} onValueChange={(warehouse) => setPageContext({ warehouse })} options={WAREHOUSES} />}
      </Field>
    </div>
  );
}

const ctxButton =
  'flex h-10 items-center gap-2 rounded-md border border-line bg-surface px-3 text-[13px] font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring';

export function PageContextControl({ kind }: { kind: TopbarContext }) {
  const Icon = kind === 'period' ? Calendar : Warehouse;
  const label = kind === 'period' ? 'Production date' : 'Warehouse';
  const ctx = usePageContextValue();
  const value = kind === 'period' ? `${formatDate(ctx.date).slice(0, 6)} · ${ctx.shift === 'all' ? 'All shifts' : ctx.shift}` : ctx.warehouse;
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
      <PopoverContent aria-label={label} className={kind === 'period' ? 'w-auto' : undefined}>
        <ContextFields kind={kind} />
      </PopoverContent>
    </Popover>
  );
}

/* ===================== Workspace switcher (tenant + plant) ===================== */
function LiveWorkspaceFields({ identity }: { identity: ShellIdentity }) {
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const run = (action: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    action()
      .catch(() => setError('The workspace could not be changed. Nothing was switched; try again.'))
      .finally(() => setBusy(false));
  };
  return (
    <div className="flex flex-col gap-3 p-3" aria-busy={busy || undefined}>
      <Field label="Company workspace" hint={identity.tenants.length > 1 ? 'Switching reloads every view in the new workspace.' : undefined}>
        {(p) => (
          <Select
            {...p}
            value={identity.tenant.id}
            disabled={busy || identity.tenants.length < 2}
            onValueChange={(id) => run(() => identity.selectTenant(id))}
            options={identity.tenants.map((t) => ({ value: t.id, label: t.name, description: t.code }))}
          />
        )}
      </Field>
      <Field label="Plant" hint="Data in plant-scoped views follows the selected plant.">
        {(p) => (
          <Select
            {...p}
            value={identity.activePlantId ?? ''}
            disabled={busy || identity.plants.length === 0}
            onValueChange={(id) => run(() => identity.selectPlant(id))}
            options={identity.plants.map((pl) => ({ value: pl.id, label: `${pl.code} · ${pl.name}` }))}
            placeholder={identity.plants.length ? 'Select a plant' : 'No plant in your scope'}
          />
        )}
      </Field>
      {error ? <InlineAlert tone="danger" title={error} /> : null}
    </div>
  );
}

/** Prototype workspace without a session (component tests, review pages): local value only. */
function SampleSelect({ initial, ...props }: Omit<React.ComponentProps<typeof Select>, 'value' | 'onValueChange'> & { initial: string }) {
  const [value, setValue] = React.useState(initial);
  return <Select {...props} value={value} onValueChange={setValue} />;
}

function WorkspaceFields() {
  const { identity } = useShellSession();
  if (identity) return <LiveWorkspaceFields identity={identity} />;
  return (
    <div className="flex flex-col gap-3 p-3">
      <Field label="Company">
        {(p) => (
          <SampleSelect
            {...p}
            initial="PT Sample Manufaktur"
            options={[
              { value: 'PT Sample Manufaktur', label: 'PT Sample Manufaktur' },
              { value: 'PT Sample Komponen', label: 'PT Sample Komponen' },
            ]}
          />
        )}
      </Field>
      <Field label="Plant" hint="Only companies and plants assigned to you are listed. Switching reloads data in the new scope.">
        {(p) => (
          <SampleSelect
            {...p}
            initial="Plant A · Cikarang"
            options={[
              { value: 'Plant A · Cikarang', label: 'Plant A · Cikarang' },
              { value: 'Plant B · Karawang', label: 'Plant B · Karawang' },
            ]}
          />
        )}
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
      <PopoverTrigger
        className="inline-grid size-11 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring md:hidden"
        aria-label="Company, plant and page context"
      >
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
/**
 * Bell: the numeric badge counts only items needing the user's action (D-025); unread information is a dot, never a
 * number. Signed in, both come from the live inbox; review pages without a session use the mock navigation counts.
 */
export function NotificationBell({ actionCount: mockCount }: { actionCount: number }) {
  const [open, setOpen] = React.useState(false);
  const { q, source } = useInbox();
  // totals of the whole inbox, never derived from the loaded page (older items keep their badge)
  const actionCount = source.isMock ? mockCount : (q.data?.actionRequired ?? 0);
  const unread = source.isMock ? 0 : (q.data?.unread ?? 0);
  const label = [actionCount ? `${actionCount} need action` : '', unread ? `${unread} unread` : ''].filter(Boolean).join(', ');
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        className="relative inline-grid size-9 place-items-center rounded-md text-ink-2 hover:bg-hover hover:text-ink focus-visible:outline-2 focus-visible:outline-ring max-md:size-11"
        aria-label={label ? `Notifications: ${label}` : 'Notifications'}
      >
        <Bell aria-hidden="true" className="size-5" />
        {actionCount ? (
          <NumericBadge count={actionCount} label="need action" className="absolute -top-0.5 -right-1 shadow-[0_0_0_2px_var(--color-bg-surface)]" />
        ) : unread ? (
          <span aria-hidden="true" className="absolute top-1.5 right-1.5 size-2 rounded-full bg-accent shadow-[0_0_0_2px_var(--color-bg-surface)]" />
        ) : null}
      </PopoverTrigger>
      <PopoverContent aria-label="Notifications" className="w-[min(420px,calc(100vw-16px))] p-0">
        <h2 className="m-0 border-b border-line px-4 pt-3 pb-2 text-[15px] font-semibold">Notifications</h2>
        <NotificationCenter variant="panel" onNavigate={() => setOpen(false)} />
      </PopoverContent>
    </Popover>
  );
}

/* ===================== User menu (profile; prototype role switch) ===================== */
export function UserMenu() {
  const { user, role, setRole, identity } = useShellSession();
  const [signingOut, setSigningOut] = React.useState(false);
  const subtitle = identity ? identity.tenant.name : ROLE_LABELS[role];
  return (
    // Non-modal: a modal menu aria-hides focusable page content (axe aria-hidden-focus); Esc / outside click still close it.
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger
        className="flex min-h-11 items-center gap-2 rounded-md px-1 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring"
        aria-label={`Account menu, ${user.name}, ${subtitle}`}
      >
        <span aria-hidden="true" className="grid size-8 place-items-center rounded-full bg-accent-subtle text-xs font-semibold text-accent">
          {user.initials}
        </span>
        <span className="hidden text-left leading-tight 4xl:block">
          <span className="block text-[13px] font-semibold">{user.name}</span>
          <span className="block text-[11.5px] text-ink-2s">{subtitle}</span>
        </span>
        <ChevronDown aria-hidden="true" className="size-4 text-ink-2 max-lg:hidden" />
      </DropdownMenuTrigger>
      <DropdownMenuContent className="w-64">
        <DropdownMenuLabel>
          <span className="block">{user.name}</span>
          {user.email ? <span className="block text-xs font-normal text-ink-2">{user.email}</span> : null}
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuLabel>Prototype: mock modules shown as role</DropdownMenuLabel>
        <DropdownMenuRadioGroup value={role} onValueChange={(v) => setRole(v as RoleKey)}>
          {(Object.keys(ROLE_LABELS) as RoleKey[]).map((r) => (
            <DropdownMenuRadioItem key={r} value={r}>
              {ROLE_LABELS[r]}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuItem disabled>
          <User aria-hidden="true" />
          Profile & devices (later)
        </DropdownMenuItem>
        {identity ? (
          <DropdownMenuItem
            disabled={signingOut}
            onSelect={() => {
              setSigningOut(true);
              void identity.signOut().finally(() => setSigningOut(false));
            }}
          >
            <LogOut aria-hidden="true" />
            {signingOut ? 'Signing out…' : 'Sign out'}
          </DropdownMenuItem>
        ) : (
          <DropdownMenuItem disabled>
            <LogOut aria-hidden="true" />
            Sign out (no session)
          </DropdownMenuItem>
        )}
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
    <header className="flex h-[var(--shell-topbar-h)] min-w-0 shrink-0 items-center gap-2 border-b border-line bg-surface pr-4 pl-3 max-md:gap-0.5 max-md:px-2 [&>*:not(nav)]:shrink-0">
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

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axeViolations } from '../../../ui/src/test/axe';

let pathname = '/ppic/control-tower';
vi.mock('next/navigation', () => ({ usePathname: () => pathname }));

import { AppShell } from './app-shell';
import { ShellSessionProvider } from './session';

function renderShell() {
  return render(
    <ShellSessionProvider>
      <AppShell>
        <h1>Page content</h1>
      </AppShell>
    </ShellSessionProvider>,
  );
}

beforeEach(() => {
  pathname = '/ppic/control-tower';
  window.localStorage.clear();
});

describe('AppShell', () => {
  it('renders primary module navigation with actionable badge names (D-025)', () => {
    renderShell();
    const modules = screen.getByRole('navigation', { name: 'Modules' });
    expect(within(modules).getByRole('link', { name: 'PPIC / Planning, 1 to action' })).toHaveAttribute('aria-current', 'true');
    expect(within(modules).getByRole('link', { name: 'Dashboard, 3 to action' })).toBeInTheDocument();
    expect(within(modules).getByRole('link', { name: 'Demand' })).toBeInTheDocument();
  });

  it('marks the current page in the module menu and breadcrumb', () => {
    renderShell();
    const menu = screen.getByRole('navigation', { name: 'PPIC / Planning menu' });
    expect(within(menu).getByRole('link', { name: 'Control Tower' })).toHaveAttribute('aria-current', 'page');
    const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });
    expect(within(crumbs).getByText('Control Tower')).toHaveAttribute('aria-current', 'page');
  });

  it('does not expose items that depend on OPEN decisions', () => {
    pathname = '/demand/customer-po';
    renderShell();
    expect(screen.queryByRole('link', { name: 'Demand Netting' })).not.toBeInTheDocument();
  });

  it('moves focus between modules with arrow keys', async () => {
    renderShell();
    const user = userEvent.setup();
    const first = screen.getByRole('link', { name: 'Dashboard, 3 to action' });
    first.focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('link', { name: 'Demand' })).toHaveFocus();
    await user.keyboard('{ArrowUp}{ArrowUp}');
    expect(screen.getByRole('link', { name: 'Analytics' })).toHaveFocus(); // wraps to last module allowed for the role
  });

  it('opens the search dialog with Ctrl+K and Cmd+K and closes with Escape', async () => {
    renderShell();
    const user = userEvent.setup();
    await user.keyboard('{Control>}k{/Control}');
    expect(screen.getByRole('dialog', { name: 'Search records' })).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Search records' })).not.toBeInTheDocument();
    await user.keyboard('{Meta>}k{/Meta}');
    expect(screen.getByRole('dialog', { name: 'Search records' })).toBeInTheDocument();
  });

  it('collapses groups with an accessible toggle', async () => {
    renderShell();
    const user = userEvent.setup();
    const menu = screen.getByRole('navigation', { name: 'PPIC / Planning menu' });
    const toggle = within(menu).getByRole('button', { name: 'Reports' });
    expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await user.click(toggle);
    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(within(menu).queryByRole('link', { name: 'Daily Delivery' })).not.toBeInTheDocument();
  });

  it('mobile navigation sheet: focus on Close, drill-down, Escape returns focus to Menu', async () => {
    renderShell();
    const user = userEvent.setup();
    const trigger = screen.getByRole('button', { name: 'Menu' });
    await user.click(trigger);
    const sheet = screen.getByRole('dialog', { name: 'EORA Suite' });
    expect(within(sheet).getByRole('button', { name: 'Close navigation' })).toHaveFocus();
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await user.click(within(sheet).getByRole('button', { name: 'All modules' }));
    expect(within(sheet).getByRole('button', { name: /Quality/ })).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'EORA Suite' })).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });

  it('switching the prototype role filters modules and badges', async () => {
    renderShell();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /Account menu/ }));
    await user.click(screen.getByRole('menuitemradio', { name: 'Quality Inspector' }));
    const modules = screen.getByRole('navigation', { name: 'Modules' });
    expect(within(modules).queryByRole('link', { name: /PPIC/ })).not.toBeInTheDocument();
    expect(within(modules).getByRole('link', { name: 'Quality, 4 to action' })).toBeInTheDocument();
  });

  it('highlights no module on pages outside module navigation', () => {
    pathname = '/system/ui-kit';
    renderShell();
    const modules = screen.getByRole('navigation', { name: 'Modules' });
    expect(within(modules).queryAllByRole('link').filter((l) => l.getAttribute('aria-current') === 'true')).toHaveLength(0);
    expect(within(screen.getByRole('navigation', { name: 'Breadcrumb' })).getByText('UI kit')).toHaveAttribute('aria-current', 'page');
  });

  it('has no axe violations in the default shell', async () => {
    const { container } = renderShell();
    expect(await axeViolations(container)).toEqual([]);
  });
});

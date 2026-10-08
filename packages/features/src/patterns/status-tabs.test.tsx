import { describe, expect, it } from 'vitest';
import * as React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StatusTabs } from './status-tabs';
import { axeViolations } from '../../../ui/src/test/axe';

const ITEMS = [
  { value: 'all', label: 'All', count: 11 },
  { value: 'unrestricted', label: 'Unrestricted', count: 6 },
  { value: 'hold', label: 'Hold', count: 1 },
];

function Harness() {
  const [v, setV] = React.useState('all');
  return (
    <StatusTabs label="View by" items={ITEMS} value={v} onValueChange={setV}>
      <p>Showing view: {v}</p>
    </StatusTabs>
  );
}

describe('StatusTabs', () => {
  it('exposes a labelled tablist with counts in the accessible names', () => {
    render(<Harness />);
    expect(screen.getByRole('tablist', { name: 'View by' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'All, 11' })).toHaveAttribute('aria-selected', 'true');
  });

  it('moves with arrow keys and renders the active view in the tab panel', async () => {
    render(<Harness />);
    const user = userEvent.setup();
    screen.getByRole('tab', { name: 'All, 11' }).focus();
    await user.keyboard('{ArrowRight}');
    expect(screen.getByRole('tab', { name: 'Unrestricted, 6' })).toHaveFocus();
    expect(screen.getByRole('tab', { name: 'Unrestricted, 6' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('tabpanel')).toHaveTextContent('Showing view: unrestricted');
  });

  it('has no axe violations', async () => {
    const { container } = render(<Harness />);
    expect(await axeViolations(container)).toEqual([]);
  });
});

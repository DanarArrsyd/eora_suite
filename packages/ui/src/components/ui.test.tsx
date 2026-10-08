import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Badge, Button, EmptyState, ErrorState, Field, IconButton, Input, LoadingState, NumericBadge } from '../index';
import { axeViolations } from '../test/axe';

describe('Button', () => {
  it('defaults to type=button and supports variants', () => {
    render(<Button variant="primary">Post</Button>);
    const b = screen.getByRole('button', { name: 'Post' });
    expect(b).toHaveAttribute('type', 'button');
    expect(b.className).toContain('bg-accent');
  });
  it('icon button exposes its accessible name', () => {
    render(<IconButton aria-label="Print">P</IconButton>);
    expect(screen.getByRole('button', { name: 'Print' })).toBeInTheDocument();
  });
});

describe('Field', () => {
  it('wires label, hint and error to the control', async () => {
    render(<Field label="Weighed remaining (kg)" hint="Scale SC-02" error="Enter a number" required>{(p) => <Input {...p} />}</Field>);
    const input = screen.getByLabelText(/Weighed remaining/);
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(input).toHaveAttribute('aria-required', 'true');
    expect(input).toHaveAccessibleDescription(/Scale SC-02.*Enter a number/);
    await userEvent.type(input, '12');
    expect(input).toHaveValue('12');
  });
  it('has no axe violations', async () => {
    const { container } = render(<Field label="Lot" hint="e.g. C-08">{(p) => <Input {...p} />}</Field>);
    expect(await axeViolations(container)).toEqual([]);
  });
});

describe('Badges', () => {
  it('status badge keeps text', () => {
    render(<Badge tone="warning">Pending QC</Badge>);
    expect(screen.getByText('Pending QC')).toBeInTheDocument();
  });
  it('numeric badge renders nothing for zero and announces count', () => {
    const { container, rerender } = render(<NumericBadge count={0} label="to action" />);
    expect(container).toBeEmptyDOMElement();
    rerender(<NumericBadge count={120} label="to action" />);
    expect(screen.getByText('99+')).toHaveAttribute('aria-hidden', 'true');
    expect(screen.getByText('120 to action')).toBeInTheDocument();
  });
});

describe('States', () => {
  it('error state is announced; loading state is a status region', async () => {
    const { container } = render(
      <div>
        <ErrorState title="Lots could not be loaded" description="Try again" />
        <LoadingState title="Loading lots" />
        <EmptyState title="No lots" />
      </div>,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('Lots could not be loaded');
    expect(screen.getByRole('status')).toHaveTextContent('Loading lots');
    expect(await axeViolations(container)).toEqual([]);
  });
});

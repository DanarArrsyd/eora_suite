import { describe, expect, it, vi } from 'vitest';
import * as React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { z } from 'zod';
import { axeViolations } from '../../../ui/src/test/axe';
import { Providers } from '../test/providers';
import { DataTable, Pagination, type EoraColumn, type RowSelection } from './data-table';
import { ActiveFilters, FilterBar, useFilterPanel, type FilterField } from './filter-bar';
import { EoraForm, FormQuantityField, FormTextField } from './form';
import { ConfirmDialog } from './feedback';
import { NotificationCenter } from './notifications';
import { SourceError, type SortRule } from '../data';

vi.mock('next/navigation', () => ({
  usePathname: () => '/system/patterns',
  useRouter: () => ({ replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

type Row = { id: string; lot: string; qty: number };
const ROWS: Row[] = [
  { id: 'a', lot: 'C-101', qty: 10 },
  { id: 'b', lot: 'C-102', qty: 0 },
];
const COLS: EoraColumn<Row>[] = [
  { id: 'lot', header: 'Lot', lead: true, sortable: true, cell: (r) => r.lot, sub: () => 'sub-line' },
  { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: (r) => `${r.qty} kg` },
];

function TableHarness(props: {
  status?: 'loading' | 'error' | 'ready';
  error?: unknown;
  rows?: Row[];
  density?: 'comfortable' | 'compact';
  onSort?: (s: SortRule[]) => void;
}) {
  const [sel, setSel] = React.useState<RowSelection>({});
  const [sort, setSort] = React.useState<SortRule[]>([{ id: 'lot', desc: false }]);
  return (
    <>
      <span data-testid="selected">{Object.keys(sel).sort().join(',')}</span>
      <DataTable
        label="Sample lots"
        columns={COLS}
        rows={props.rows ?? ROWS}
        getRowId={(r) => r.id}
        rowLabel={(r) => `lot ${r.lot}`}
        status={props.status ?? 'ready'}
        error={props.error}
        onRetry={() => {}}
        empty={<p>No lots match these filters</p>}
        sort={sort}
        onSortChange={(s) => {
          setSort(s);
          props.onSort?.(s);
        }}
        density={props.density ?? 'comfortable'}
        selection={sel}
        onSelectionChange={setSel}
        rowActions={{
          primary: () => ({ id: 'view', label: 'View', onSelect: () => {} }),
          menu: () => [
            { id: 'trace', label: 'Trace lot', onSelect: () => {} },
            { id: 'adjust', label: 'Adjust stock', onSelect: () => {}, disabledReason: 'Requires Inventory Controller role' },
          ],
        }}
      />
    </>
  );
}

describe('DataTable', () => {
  it('exposes caption, aria-sort and server sort changes', async () => {
    const onSort = vi.fn();
    render(<TableHarness onSort={onSort} />);
    const table = screen.getByRole('table', { name: 'Sample lots' });
    const lotHeader = within(table).getByRole('columnheader', { name: /Lot/ });
    expect(lotHeader).toHaveAttribute('aria-sort', 'ascending');
    await userEvent.click(within(lotHeader).getByRole('button'));
    expect(onSort).toHaveBeenLastCalledWith([{ id: 'lot', desc: true }]);
    await userEvent.click(within(lotHeader).getByRole('button'));
    expect(onSort).toHaveBeenLastCalledWith([{ id: 'lot', desc: false }]);
  });

  it('selects rows with labelled checkboxes and select-all', async () => {
    render(<TableHarness />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select lot C-102' }));
    expect(screen.getByTestId('selected')).toHaveTextContent('b');
    await userEvent.click(screen.getByRole('checkbox', { name: 'Select all rows on this page' }));
    expect(screen.getByTestId('selected')).toHaveTextContent('a,b');
  });

  it('keeps unavailable row actions focusable with their reason', async () => {
    render(<TableHarness />);
    await userEvent.click(screen.getByRole('button', { name: 'More actions for lot C-101' }));
    const item = await screen.findByRole('menuitem', { name: /Adjust stock/ });
    expect(item).toHaveAttribute('aria-disabled', 'true');
    expect(item).toHaveTextContent('Requires Inventory Controller role');
  });

  it('hides sub-lines in compact density but keeps critical columns', () => {
    const { rerender } = render(<TableHarness density="comfortable" />);
    expect(screen.getAllByText('sub-line')).toHaveLength(2);
    rerender(<TableHarness density="compact" />);
    expect(screen.queryByText('sub-line')).toBeNull();
    expect(screen.getByRole('columnheader', { name: 'Quantity' })).toBeInTheDocument();
  });

  it('renders error with reference, permission denied and empty states', () => {
    const { rerender } = render(<TableHarness status="error" error={new SourceError('Timeout.', 'server', 'ref-1')} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Reference ref-1');
    rerender(<TableHarness status="error" error={new SourceError('No.', 'forbidden', 'ref-2')} />);
    expect(screen.getByText('You do not have access to this view')).toBeInTheDocument();
    rerender(<TableHarness rows={[]} />);
    expect(screen.getByText('No lots match these filters')).toBeInTheDocument();
  });

  it('announces loading with skeleton rows and has no axe violations', async () => {
    const { container, rerender } = render(<TableHarness status="loading" rows={[]} />);
    expect(screen.getByRole('status')).toHaveTextContent('Loading sample lots');
    rerender(<TableHarness />);
    expect(await axeViolations(container)).toEqual([]);
  });
});

describe('Pagination', () => {
  it('summarises the range, marks the current page and pages', async () => {
    const onPage = vi.fn();
    const { container } = render(<Pagination page={3} pageSize={20} total={214} itemLabel="lots" onPageChange={onPage} onPageSizeChange={() => {}} />);
    expect(screen.getByText(/Showing/)).toHaveTextContent('Showing 41–60 of 214 lots');
    expect(screen.getByRole('button', { name: 'Page 3' })).toHaveAttribute('aria-current', 'page');
    await userEvent.click(screen.getByRole('button', { name: /Next/ }));
    expect(onPage).toHaveBeenLastCalledWith(4);
    await userEvent.type(screen.getByLabelText('Go to page', { selector: 'input' }), '99{Enter}');
    expect(onPage).toHaveBeenLastCalledWith(11);
    expect(await axeViolations(container)).toEqual([]);
  });
});

const FIELDS: FilterField[] = [
  {
    key: 'plant',
    type: 'select',
    source: 'static',
    label: 'Plant',
    options: [
      { value: 'PA', label: 'Plant A' },
      { value: 'PB', label: 'Plant B' },
    ],
  },
  { key: 'warehouse', type: 'select', source: 'static', label: 'Warehouse', dependsOn: ['plant'], options: [{ value: 'RM-WH1', label: 'RM-WH1' }] },
  {
    key: 'grade',
    type: 'multiselect',
    source: 'static',
    label: 'Grade',
    options: [
      { value: 'SPCC', label: 'SPCC' },
      { value: 'SPHC', label: 'SPHC' },
    ],
  },
];

function FilterHarness({ onApply }: { onApply: (v: unknown) => void }) {
  const panel = useFilterPanel();
  return <FilterBar id="f" fields={FIELDS} values={{ plant: 'PA', warehouse: 'RM-WH1' }} onApply={onApply} panel={panel} />;
}

describe('FilterBar', () => {
  it('applies the draft on submit and clears dependent children when a parent changes', async () => {
    const onApply = vi.fn();
    const { container } = render(
      <Providers>
        <FilterHarness onApply={onApply} />
      </Providers>,
    );
    const form = screen.getByRole('form', { name: 'Filters' });
    await userEvent.selectOptions(within(form).getByRole('combobox', { name: 'Plant' }), 'PB');
    expect(within(form).getByRole('combobox', { name: 'Warehouse' })).toHaveValue('');
    await userEvent.click(within(form).getByRole('button', { name: 'Apply' }));
    expect(onApply).toHaveBeenLastCalledWith({ plant: 'PB', warehouse: undefined });
    await userEvent.click(within(form).getByRole('button', { name: 'Reset' }));
    expect(onApply).toHaveBeenLastCalledWith({});
    expect(await axeViolations(container)).toEqual([]);
  });

  it('shows locked scope chips without a remove button', async () => {
    const onRemove = vi.fn();
    render(
      <Providers>
        <ActiveFilters
          fields={[{ ...FIELDS[0]!, locked: true }, FIELDS[1]!]}
          values={{ plant: 'PA', warehouse: 'RM-WH1' }}
          onRemove={onRemove}
          onClearAll={() => {}}
        />
      </Providers>,
    );
    const group = screen.getByRole('group', { name: 'Active filters' });
    expect(within(group).queryByRole('button', { name: /Remove filter Plant/ })).toBeNull();
    await userEvent.click(within(group).getByRole('button', { name: 'Remove filter Warehouse: RM-WH1' }));
    expect(onRemove).toHaveBeenCalledWith('warehouse', undefined);
  });
});

describe('EoraForm', () => {
  const schema = z.object({ lot: z.string().min(1, 'Enter a lot'), qty: z.string().regex(/^\d+(\.\d{1,3})?$/, 'Use up to 3 decimals') });

  it('validates on submit, focuses the summary and maps server field errors; one idempotency key per attempt series', async () => {
    const keys: string[] = [];
    const onSubmit = vi.fn(async (_v: unknown, ctx: { idempotencyKey: string }) => {
      keys.push(ctx.idempotencyKey);
      return { ok: false as const, message: 'Not submitted', fieldErrors: { qty: 'Exceeds available 450.000 kg' } };
    });
    const { container } = render(
      <EoraForm label="Sample" schema={schema} defaultValues={{ lot: '', qty: '' }} submitLabel="Submit" onSubmit={onSubmit}>
        <FormTextField name="lot" label="Lot" required />
        <FormQuantityField name="qty" label="Quantity" uom="kg" decimals={3} required />
      </EoraForm>,
    );
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Fix 2 fields before submitting');
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByLabelText(/Lot/)).toHaveAttribute('aria-invalid', 'true');

    await userEvent.type(screen.getByLabelText(/Lot/), 'C-101');
    await userEvent.type(screen.getByLabelText(/Quantity/), '500');
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(screen.getByText('Exceeds available 450.000 kg', { selector: 'p' })).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: 'Submit' }));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(2));
    expect(keys[0]).toBe(keys[1]);
    expect(await axeViolations(container)).toEqual([]);
  });
});

describe('ConfirmDialog', () => {
  it('requires a reason before confirming and starts focus on Cancel', async () => {
    const onConfirm = vi.fn();
    render(
      <ConfirmDialog
        open
        onOpenChange={() => {}}
        title="Request reversal?"
        description="Needs approval."
        confirmLabel="Request reversal"
        reason={{ label: 'Reason', minLength: 10 }}
        onConfirm={onConfirm}
      />,
    );
    const dialog = screen.getByRole('alertdialog', { name: 'Request reversal?' });
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Request reversal' }));
    expect(onConfirm).not.toHaveBeenCalled();
    expect(within(dialog).getByText('Enter at least 10 characters')).toBeInTheDocument();
    await userEvent.type(within(dialog).getByLabelText(/Reason/), 'Counted twice by mistake');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Request reversal' }));
    expect(onConfirm).toHaveBeenCalledWith('Counted twice by mistake');
  });
});

describe('NotificationCenter', () => {
  it('lists actionable items matching the bell count and hides links the role cannot open', async () => {
    window.localStorage.setItem('eora.prototype.role', 'quality-inspector');
    const { container } = render(
      <Providers>
        <NotificationCenter variant="page" />
      </Providers>,
    );
    expect(await screen.findByText('4 inspection lots waiting')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Needs action/ })).toHaveTextContent('1');
    expect(screen.getByText(/No access to ppic/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open: 4 inspection lots waiting' })).toHaveAttribute('href', '/quality/pending-qc');
    expect(await axeViolations(container)).toEqual([]);
    window.localStorage.clear();
  });
});

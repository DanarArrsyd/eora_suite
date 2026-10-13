import { describe, expect, it } from 'vitest';
import { readListQuery, writeListQuery, type ListUrlConfig } from './list-state';
import { setDraftValue, toRange } from './filter-bar/filter-bar';
import type { FilterField } from './filter-bar/types';
import { pageWindow } from './data-table/pagination';
import { precheckFile } from './import-export/import-wizard';
import { STATUS_CATALOGUE, statusEntry } from './status-badge';

const cfg: ListUrlConfig = {
  filterKeys: ['plant', 'warehouse', 'grade'],
  defaultSort: [{ id: 'lot', desc: false }],
  defaultPageSize: 20,
  lockedFilters: { plant: 'PA' },
};

describe('list URL state', () => {
  it('reads filters, multi-values, sort, page and view; locked scope always applies', () => {
    const q = readListQuery(
      new URLSearchParams('f.warehouse=RM-WH1&f.grade=SPCC&f.grade=SPHC&sort=receivedAt.desc&page=3&size=50&view=hold&q=c-1&f.plant=PB'),
      cfg,
    );
    expect(q).toEqual({
      page: 3,
      pageSize: 50,
      sort: [{ id: 'receivedAt', desc: true }],
      filters: { warehouse: 'RM-WH1', grade: ['SPCC', 'SPHC'], plant: 'PA' },
      search: 'c-1',
      view: 'hold',
    });
  });

  it('falls back for invalid page / size and ignores unknown keys', () => {
    const q = readListQuery(new URLSearchParams('page=-2&size=33&f.secret=x'), cfg);
    expect(q.page).toBe(1);
    expect(q.pageSize).toBe(20);
    expect(q.filters).toEqual({ plant: 'PA' });
  });

  it('writes only non-default state and never the locked scope', () => {
    const p = writeListQuery(
      { page: 1, pageSize: 20, sort: [{ id: 'lot', desc: false }], filters: { plant: 'PA', grade: ['SPCC', 'SGCC'] }, search: '', view: 'all' },
      cfg,
    );
    expect(p.toString()).toBe('f.grade=SPCC&f.grade=SGCC');
  });

  it('round-trips', () => {
    const q = readListQuery(new URLSearchParams('f.warehouse=RM-WH2&sort=lot.desc&page=2'), cfg);
    expect(readListQuery(writeListQuery(q, cfg), cfg)).toEqual(q);
  });
});

describe('dependent filters', () => {
  const fields: FilterField[] = [
    { key: 'plant', type: 'select', source: 'static', label: 'Plant', options: [] },
    { key: 'warehouse', type: 'select', source: 'static', label: 'Warehouse', options: [], dependsOn: ['plant'] },
    { key: 'location', type: 'select', source: 'static', label: 'Location', options: [], dependsOn: ['warehouse'] },
    { key: 'grade', type: 'multiselect', source: 'static', label: 'Grade', options: [] },
  ];
  it('changing a parent clears children recursively and keeps unrelated values', () => {
    const next = setDraftValue(fields, { plant: 'PA', warehouse: 'RM-WH1', location: 'A01', grade: ['SPCC'] }, 'plant', 'PB');
    expect(next).toEqual({ plant: 'PB', warehouse: undefined, location: undefined, grade: ['SPCC'] });
  });
});

describe('pagination window', () => {
  it('shows all pages up to 7', () => expect(pageWindow(2, 5)).toEqual([1, 2, 3, 4, 5]));
  it('collapses with gaps', () => expect(pageWindow(6, 20)).toEqual([1, 'gap', 5, 6, 7, 'gap', 20]));
  it('handles edges', () => expect(pageWindow(1, 11)).toEqual([1, 2, 'gap', 11]));
});

describe('import pre-check', () => {
  it('accepts xlsx/csv within 10 MB', () => expect(precheckFile({ name: 'lots.XLSX', size: 2048 })).toBeUndefined());
  it('rejects other types, empty and oversized files', () => {
    expect(precheckFile({ name: 'lots.exe', size: 10 })).toMatch(/not supported/);
    expect(precheckFile({ name: 'lots.csv', size: 0 })).toMatch(/empty/);
    expect(precheckFile({ name: 'lots.csv', size: 11 * 1024 * 1024 })).toMatch(/10 MB/);
  });
});

describe('status catalogue', () => {
  it('maps domains separately and never invents a WIP status', () => {
    expect(statusEntry('stock', 'PENDING_QC')).toEqual({ label: 'Pending QC', tone: 'info' });
    expect(statusEntry('schedule', 'PARTIALLY_FULFILLED').label).toBe('Partially fulfilled');
    expect(statusEntry('indicator', 'PROVISIONAL').tone).toBe('warning');
    expect(JSON.stringify(STATUS_CATALOGUE)).not.toMatch(/WIP|Work in progress/i);
  });
});

describe('date range from the URL', () => {
  it('keeps valid ends, including an end date only, and drops malformed or impossible dates', () => {
    expect(toRange('2026-10-01..2026-10-09')).toEqual({ from: '2026-10-01', to: '2026-10-09' });
    expect(toRange('..2026-10-09')).toEqual({ from: '', to: '2026-10-09' });
    expect(toRange('abc..def')).toEqual({ from: '', to: '' });
    expect(toRange('2026-02-30..2026-13-01')).toEqual({ from: '', to: '' });
  });
});

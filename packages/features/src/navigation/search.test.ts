import { describe, expect, it } from 'vitest';
import { NAV_MODULES } from './config';
import { buildPageIndex, searchPages } from './search';

const index = buildPageIndex(NAV_MODULES);

describe('page search', () => {
  it('ranks the exact page first and highlights the matched words', () => {
    const [first] = searchPages(index, 'work orders');
    expect(first?.label).toBe('Work Orders');
    expect(first?.ranges).toEqual([
      [0, 4],
      [5, 11],
    ]);
  });

  it('finds pages by their initials', () => {
    expect(searchPages(index, 'mps')[0]?.label).toBe('Master Production Schedule');
  });

  it('requires every word to match and returns nothing for noise', () => {
    expect(searchPages(index, 'delivery zzz')).toEqual([]);
    expect(searchPages(index, '   ')).toEqual([]);
  });

  it('ignores short fragments of module or group names', () => {
    const labels = searchPages(index, 'wo').map((h) => h.label);
    expect(labels[0]).toBe('Work Orders');
    expect(labels).not.toContain('My Tasks');
  });

  it('narrows by group name with a full word', () => {
    expect(searchPages(index, 'monitoring work').map((h) => h.label)).toContain('Work Order Progress');
  });

  it('matches word starts inside labels, not random fragments first', () => {
    const labels = searchPages(index, 'order').map((h) => h.label);
    expect(labels.slice(0, 3).every((l) => /\bOrders?\b/.test(l))).toBe(true);
  });
});

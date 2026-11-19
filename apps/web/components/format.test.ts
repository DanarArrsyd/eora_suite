import { describe, expect, it } from 'vitest';
import { formatDay, formatMoment, formatNumber, formatQty } from './format';

describe('shared formatting (UI/UX audit U-7/U-8)', () => {
  it('writes quantities one way: separator, kg with 3 decimals, the stored unit code', () => {
    expect(formatQty('1000', 'PCS')).toBe('1,000 PCS');
    expect(formatQty('44.5', 'KG')).toBe('44.500 KG');
    expect(formatQty('3.25', 'SHEET')).toBe('3.25 SHEET');
    expect(formatQty(null, 'PCS')).toBe('—');
    expect(formatNumber('1234567.5', 'M')).toBe('1,234,567.5');
    expect(formatNumber('2', 'KG', 0)).toBe('2');
  });

  it('writes plant calendar days without a timezone shift', () => {
    expect(formatDay('2026-11-27')).toBe('27 Nov 2026');
    expect(formatDay('2026-01-01')).toBe('1 Jan 2026');
    expect(formatDay(null)).toBe('—');
  });
});

describe('formatMoment', () => {
  it('reads ISO and Postgres text timestamps', () => {
    expect(formatMoment('2026-11-19T02:57:20Z')).toBe(formatMoment('2026-11-19 02:57:20.170988+00'));
    expect(formatMoment('2026-11-19 02:57:20.170988+00')).toMatch(/19 Nov 2026/);
  });
});

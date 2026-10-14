import { describe, expect, it } from 'vitest';
import { addMonths, formatDateRange, isIsoDate, isoWeek, monthGrid, startOfIsoWeek } from './dates';

describe('calendar dates (ISO weeks, D-044)', () => {
  it('validates real calendar dates only', () => {
    expect(isIsoDate('2026-02-29')).toBe(false);
    expect(isIsoDate('2028-02-29')).toBe(true);
    expect(isIsoDate('09/10/2026')).toBe(false);
  });

  it('numbers ISO weeks across year boundaries', () => {
    expect(isoWeek('2026-10-09')).toBe(41);
    expect(isoWeek('2026-12-31')).toBe(53);
    expect(isoWeek('2027-01-01')).toBe(53);
    expect(isoWeek('2027-01-04')).toBe(1);
  });

  it('starts weeks on Monday and builds a six-week month grid', () => {
    expect(startOfIsoWeek('2026-10-11')).toBe('2026-10-05'); // Sunday belongs to the week of Monday 5 Oct
    const grid = monthGrid('2026-10-15');
    expect(grid).toHaveLength(6);
    expect(grid[0]![0]).toBe('2026-09-28');
    expect(grid[0]!.includes('2026-10-01')).toBe(true);
  });

  it('clamps month steps to the last day', () => {
    expect(addMonths('2026-01-31', 1)).toBe('2026-02-28');
    expect(addMonths('2026-03-31', -1)).toBe('2026-02-28');
  });

  it('formats ranges compactly and open-ended ranges explicitly', () => {
    expect(formatDateRange('2026-10-01', '2026-10-09')).toBe('01 – 09 Oct 2026');
    expect(formatDateRange('2026-09-28', '2026-10-09')).toBe('28 Sep – 09 Oct 2026');
    expect(formatDateRange('2026-10-01', '')).toBe('from 01 Oct 2026');
    expect(formatDateRange('', '2026-10-09')).toBe('until 09 Oct 2026');
  });
});

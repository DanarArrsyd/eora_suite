import { describe, expect, it } from 'vitest';
import { unitPrecisionError } from './resources';

describe('unitPrecisionError (BR-INV-10)', () => {
  it('accepts quantities within the unit decimals, trailing zeros included', () => {
    expect(unitPrecisionError('2500', 'KG', 3)).toBeUndefined();
    expect(unitPrecisionError('12.125', 'KG', 3)).toBeUndefined();
    expect(unitPrecisionError('3.00', 'PCS', 0)).toBeUndefined();
  });
  it('names the unit and its decimals when the quantity is finer', () => {
    expect(unitPrecisionError('2.5', 'PCS', 0)).toBe('Enter a whole number of PCS.');
    expect(unitPrecisionError('1.2345', 'KG', 3)).toBe('Enter KG with at most 3 decimals.');
  });
});

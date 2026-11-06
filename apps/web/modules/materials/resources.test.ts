import { describe, expect, it } from 'vitest';
import { MATERIAL_LOTS, type LotLineRow, unitPrecisionError } from './resources';

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

describe('Material Lots split check (D-099)', () => {
  const line = (over: Partial<LotLineRow>): LotLineRow => ({
    id: 'b1',
    code: 'b1',
    version: 1,
    plantId: 'p1',
    plantCode: 'P1',
    warehouseCode: 'RM',
    workCenterCode: null,
    locationId: 'l1',
    locationCode: 'RM-A01',
    itemId: 'i1',
    itemCode: 'COIL-SPCC',
    itemName: 'Coil',
    lotId: 'lot1',
    lotNo: 'L2611-0001',
    supplierLotNo: null,
    lotOrigin: 'RECEIPT',
    parentLotNo: null,
    stockStatus: 'UNRESTRICTED',
    qty: '10',
    availableQty: '8',
    uomCode: 'KG',
    uomDecimals: 3,
    ...over,
  });
  const check = (qty: string, r: LotLineRow) => MATERIAL_LOTS.check!({ qty }, r);

  it('splits unrestricted stock up to what is not allocated', async () => {
    expect(await check('8', line({}))).toEqual({});
    expect(await check('9', line({}))).toEqual({ qty: 'Only 8 KG is available here; the rest is allocated.' });
  });
  it('splits other statuses up to what is there, at the unit decimals', async () => {
    expect(await check('10', line({ stockStatus: 'BLOCKED' }))).toEqual({});
    expect(await check('11', line({ stockStatus: 'BLOCKED' }))).toEqual({ qty: 'Only 10 KG is here.' });
    expect(await check('1.2345', line({}))).toEqual({ qty: 'Enter KG with at most 3 decimals.' });
  });
});

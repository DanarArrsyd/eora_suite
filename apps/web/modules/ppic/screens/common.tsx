import * as React from 'react';
import type { FilterField } from '@eora/features/patterns';
import { QuantityWithUom } from '@/components/quantity';
import { CUSTOMERS, PARTS } from '../mock/world';
import { PLANT_FIELD } from '../components/ct-screen';

export { PLANT_FIELD };
export const q = (value: number, uom: string) => <QuantityWithUom value={value} uom={uom} />;
export const partLabel = (p: string) => `${p} · ${PARTS[p]?.name ?? ''}`;
export const customerLabel = (c: string) => CUSTOMERS[c] ?? c;

export const CUSTOMER_FIELD: FilterField = {
  key: 'customer',
  type: 'select',
  source: 'static',
  label: 'Customer',
  options: Object.entries(CUSTOMERS).map(([value, label]) => ({ value, label })),
};
export const PART_FIELD: FilterField = {
  key: 'part',
  type: 'select',
  source: 'static',
  label: 'Part',
  options: Object.keys(PARTS).map((value) => ({ value, label: partLabel(value) })),
};
export const DUE_FIELD: FilterField = { key: 'due', type: 'daterange', label: 'Due date' };
export const SHIFT_FIELD: FilterField = {
  key: 'shift',
  type: 'select',
  source: 'static',
  label: 'Shift',
  options: [
    { value: 'S1', label: 'Shift 1' },
    { value: 'S2', label: 'Shift 2' },
    { value: 'S3', label: 'Shift 3' },
  ],
};

/** Planned module pages used as drill targets (they re-check permission and say when they are delivered). */
export const HREF = {
  customerPo: '/demand/customer-po',
  salesOrders: '/ppic/sales-orders',
  schedules: '/ppic/delivery-schedules',
  forecast: '/ppic/forecast',
  deliveryOrders: '/delivery/delivery-orders',
  shipments: '/delivery/shipments',
  rawMaterial: '/inventory/raw-material',
  movements: '/inventory/movements',
  allocations: '/inventory/allocations',
  finishedGoods: '/inventory/finished-goods',
  fgReceipt: '/warehouse/fg-receipt',
  workOrders: '/ppic/work-orders',
  runs: '/production/runs',
  postings: '/production/postings',
  measurements: '/production/measurements',
  inspectionLots: '/quality/inspection-lots',
  remnants: '/materials/remnants',
  materialIssue: '/materials/material-issue',
  cases: '/traceability/reconciliation-cases',
  backwardTrace: '/traceability/backward-trace',
};

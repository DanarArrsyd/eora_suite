/** Master Data module items on the live API (M2 steps 1c–5b, D-074, D-076); Demand → Customers / Customer Part Mapping open the same screens. */
export const MASTER_IMPLEMENTED = [
  'plants',
  'work-centers',
  'items',
  'units',
  'customers',
  'customer-parts',
  'suppliers',
  'process-types',
  'routings',
  'boms',
  'conversion-data',
] as const;
export const DEMAND_MASTER_IMPLEMENTED = ['customers', 'customer-parts'] as const;

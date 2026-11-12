'use client';

import { SalesOrdersScreen } from './sales-orders';

/** Demand → Customer Orders on the live API (doc 33 §1.1). */
export function DemandScreen({ item }: { item: string }) {
  if (item === 'sales-orders') return <SalesOrdersScreen base="/demand/sales-orders" />;
  return null;
}

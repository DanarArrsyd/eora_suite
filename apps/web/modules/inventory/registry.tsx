'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { AdjustmentsScreen } from './adjustments';
import { STOCK_OVERVIEW } from './resources';

/** Inventory → Stock and Control (doc 32 §1.4, §1.6): stock on hand from the ledger, transfers, adjustments. */
export function InventoryScreen({ item }: { item: string }) {
  if (item === 'stock-overview') return <MasterScreen config={STOCK_OVERVIEW} />;
  if (item === 'adjustments') return <AdjustmentsScreen />;
  return null;
}

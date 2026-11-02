'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { STOCK_OVERVIEW } from './resources';

/** Inventory → Stock (doc 32 §1.4): stock on hand from the ledger, moved within a plant by transfer. */
export function InventoryScreen({ item }: { item: string }) {
  if (item === 'stock-overview') return <MasterScreen config={STOCK_OVERVIEW} />;
  return null;
}

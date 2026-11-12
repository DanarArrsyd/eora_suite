'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { AdjustmentsScreen } from './adjustments';
import { MOVEMENT_LEDGER } from './ledger';
import { PeriodsScreen } from './periods';
import { STOCK_OVERVIEW } from './resources';
import { ReversalsScreen } from './reversals';

/** Inventory → Stock and Control (doc 32 §1.4, §1.6): stock on hand from the ledger, transfers, adjustments, reversals, period close, movement ledger. */
export function InventoryScreen({ item }: { item: string }) {
  if (item === 'stock-overview') return <MasterScreen config={STOCK_OVERVIEW} />;
  if (item === 'adjustments') return <AdjustmentsScreen />;
  if (item === 'reversals') return <ReversalsScreen />;
  if (item === 'period-close') return <PeriodsScreen />;
  if (item === 'movements') return <MasterScreen config={MOVEMENT_LEDGER} />;
  return null;
}

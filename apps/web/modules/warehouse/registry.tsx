'use client';

import { FgReceiptScreen } from './fg-receipt';

/** Warehouse → Inbound on the live API (doc 33 §1.6): FG receipt. */
export function WarehouseScreen({ item }: { item: string }) {
  if (item === 'fg-receipt') return <FgReceiptScreen />;
  return null;
}

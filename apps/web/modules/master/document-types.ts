/** Inventory document types by their screen names (doc 04 §Inventory ledger posting specification). */
export const DOCUMENT_TYPES: Record<string, string> = {
  RM_RECEIPT: 'Receipt',
  INCOMING_RELEASE: 'Incoming release',
  MAT_TRANSFER: 'Transfer',
  LOT_SPLIT: 'Lot split',
  STOCK_ADJUSTMENT: 'Adjustment',
  REVERSAL: 'Reversal',
};

/** Inventory document types by their screen names (doc 04 §Inventory ledger posting specification). */
export const DOCUMENT_TYPES: Record<string, string> = {
  RM_RECEIPT: 'Receipt',
  INCOMING_RELEASE: 'Incoming release',
  MAT_TRANSFER: 'Transfer',
  LOT_SPLIT: 'Lot split',
  MAT_ISSUE: 'Material issue',
  MAT_RETURN: 'Material return',
  PRODUCTION_POSTING: 'Production posting',
  QC_DISPOSITION: 'QC disposition',
  FG_RECEIPT: 'FG receipt',
  PICK: 'Pick',
  STOCK_ADJUSTMENT: 'Adjustment',
  REVERSAL: 'Reversal',
};

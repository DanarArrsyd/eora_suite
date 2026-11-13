'use client';

import { DispositionsScreen, PendingQcScreen } from './quality';

/** Quality → Inspection on the live API (doc 33 §1.5): the Pending QC Queue and dispositions. */
export function QualityScreen({ item }: { item: string }) {
  if (item === 'pending-qc') return <PendingQcScreen />;
  if (item === 'inspection-lots') return <DispositionsScreen />;
  return null;
}

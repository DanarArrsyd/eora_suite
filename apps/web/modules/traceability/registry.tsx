'use client';

import { LotTraceScreen } from './lot-trace';

/** Traceability → Lot Trace on the live API (doc 33 §1.9, D-118): backward and forward. */
export function TraceabilityScreen({ item }: { item: string }) {
  if (item === 'backward-trace') return <LotTraceScreen direction="backward" />;
  if (item === 'forward-trace') return <LotTraceScreen direction="forward" />;
  return null;
}

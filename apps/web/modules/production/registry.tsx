'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { PRODUCTION_POSTINGS, ProductionRunsScreen } from './runs';

/** Production → Execution and Output on the live API (doc 33 §1.4): runs and production postings. */
export function ProductionScreen({ item }: { item: string }) {
  if (item === 'runs') return <ProductionRunsScreen />;
  if (item === 'postings') return <MasterScreen config={PRODUCTION_POSTINGS} />;
  return null;
}

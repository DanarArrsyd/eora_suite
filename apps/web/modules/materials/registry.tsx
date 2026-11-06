'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { INCOMING_RELEASE, MATERIAL_LOTS, RM_RECEIPTS } from './resources';

/** Materials → Receiving and Lots (doc 32 §1.3, §1.5): supplier receipts, incoming release, material lots and splits. */
export function MaterialsScreen({ item }: { item: string }) {
  if (item === 'rm-receipts') return <MasterScreen config={RM_RECEIPTS} />;
  if (item === 'incoming-release') return <MasterScreen config={INCOMING_RELEASE} />;
  if (item === 'material-lots') return <MasterScreen config={MATERIAL_LOTS} />;
  return null;
}

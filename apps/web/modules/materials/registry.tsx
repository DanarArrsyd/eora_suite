'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { INCOMING_RELEASE, RM_RECEIPTS } from './resources';

/** Materials → Receiving (doc 32 §1.3): supplier receipts and the incoming release of BLOCKED material. */
export function MaterialsScreen({ item }: { item: string }) {
  if (item === 'rm-receipts') return <MasterScreen config={RM_RECEIPTS} />;
  if (item === 'incoming-release') return <MasterScreen config={INCOMING_RELEASE} />;
  return null;
}

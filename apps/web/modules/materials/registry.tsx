'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { MaterialIssueScreen, MaterialReturnScreen } from './flow';
import { INCOMING_RELEASE, MATERIAL_LOTS, RM_RECEIPTS } from './resources';

/** Materials → Receiving, Issue & Return and Lots (doc 32 §1.3, §1.5; doc 33 §1.3): receipts, incoming release, issue, return, lots. */
export function MaterialsScreen({ item }: { item: string }) {
  if (item === 'rm-receipts') return <MasterScreen config={RM_RECEIPTS} />;
  if (item === 'incoming-release') return <MasterScreen config={INCOMING_RELEASE} />;
  if (item === 'material-lots') return <MasterScreen config={MATERIAL_LOTS} />;
  if (item === 'material-issue') return <MaterialIssueScreen />;
  if (item === 'material-return') return <MaterialReturnScreen />;
  return null;
}

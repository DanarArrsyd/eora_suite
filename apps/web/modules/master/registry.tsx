'use client';

import { MasterGroupScreen } from './master-screen';
import { PART_SCREENS } from './part-resources';

/** Master Data module screens (D-074). */
export function MasterDataScreen({ item }: { item: string }) {
  const group = PART_SCREENS[item];
  return group ? <MasterGroupScreen group={group} /> : null;
}

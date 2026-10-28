'use client';

import { DIE_ITEMS, PART_SCREENS } from './part-resources';
import { PROCESS_TYPES } from './engineering-resources';
import { MasterGroupScreen, type MasterGroup } from './master-screen';
import { MASTER_SCREENS } from './resources';
import { BomsScreen } from './bom-screen';
import { ConversionDataScreen } from './conversion-screen';
import { ExportsScreen } from './exports-screen';
import { ImportScreen } from './import-screen';
import { RoutingsScreen } from './routing-screen';

/** Master Data module screens (D-074, D-076): organisation, parts, partners, engineering, import. */
const GROUPS: Record<string, MasterGroup> = {
  plants: MASTER_SCREENS.plants!,
  // Work Centers also shows which parts each die makes
  'work-centers': { ...MASTER_SCREENS['work-centers']!, parts: [...MASTER_SCREENS['work-centers']!.parts, DIE_ITEMS as never] },
  ...PART_SCREENS,
  'process-types': { title: 'Process Types', parts: [PROCESS_TYPES as never] },
};

export function MasterDataScreen({ item }: { item: string }) {
  if (item === 'routings') return <RoutingsScreen />;
  if (item === 'boms') return <BomsScreen />;
  if (item === 'conversion-data') return <ConversionDataScreen />;
  if (item === 'import') return <ImportScreen />;
  if (item === 'exports') return <ExportsScreen />;
  const group = GROUPS[item];
  return group ? <MasterGroupScreen group={group} /> : null;
}

'use client';

import { MasterGroupScreen, type MasterGroup } from '@/modules/master/master-screen';
import { DIE_ITEMS } from '@/modules/master/part-resources';
import { MASTER_SCREENS } from '@/modules/master/resources';
import { RolesScreen } from './roles-screen';
import { UsersScreen } from './users-screen';

/** Work Centers also shows which parts each die makes (step 2c). */
const ADMIN_GROUPS: Record<string, MasterGroup> = {
  ...MASTER_SCREENS,
  'work-centers': { ...MASTER_SCREENS['work-centers']!, parts: [...MASTER_SCREENS['work-centers']!.parts, DIE_ITEMS as never] },
};

export function AdminScreen({ item }: { item: string }) {
  if (item === 'roles') return <RolesScreen />;
  const group = ADMIN_GROUPS[item];
  return group ? <MasterGroupScreen group={group} /> : <UsersScreen />;
}

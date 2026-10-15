'use client';

import { MasterGroupScreen } from '@/modules/master/master-screen';
import { MASTER_SCREENS } from '@/modules/master/resources';
import { RolesScreen } from './roles-screen';
import { UsersScreen } from './users-screen';

export function AdminScreen({ item }: { item: string }) {
  if (item === 'roles') return <RolesScreen />;
  const group = MASTER_SCREENS[item];
  return group ? <MasterGroupScreen group={group} /> : <UsersScreen />;
}

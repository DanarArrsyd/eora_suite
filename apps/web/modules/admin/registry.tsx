'use client';

import { MasterGroupScreen } from '@/modules/master/master-screen';
import { MASTER_SCREENS } from '@/modules/master/resources';
import { RolesScreen } from './roles-screen';
import { UsersScreen } from './users-screen';

/** Administration: access and the company record; plants, warehouses and work centers live in Master Data (D-076). */
export function AdminScreen({ item }: { item: string }) {
  if (item === 'roles') return <RolesScreen />;
  if (item === 'company') return <MasterGroupScreen group={MASTER_SCREENS.company!} />;
  return <UsersScreen />;
}

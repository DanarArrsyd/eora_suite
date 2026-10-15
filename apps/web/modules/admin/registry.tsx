'use client';

import { MasterScreen } from '@/modules/master/master-screen';
import { MASTER_SCREENS } from '@/modules/master/resources';
import { RolesScreen } from './roles-screen';
import { UsersScreen } from './users-screen';

export function AdminScreen({ item }: { item: string }) {
  if (item === 'roles') return <RolesScreen />;
  if (item === 'users') return <UsersScreen />;
  const config = MASTER_SCREENS[item as keyof typeof MASTER_SCREENS];
  // one screen per resource: the key resets list state and drawers when switching between items
  return config ? <MasterScreen key={item} config={config as never} /> : <UsersScreen />;
}

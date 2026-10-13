'use client';

import { RolesScreen } from './roles-screen';
import { UsersScreen } from './users-screen';

export function AdminScreen({ item }: { item: string }) {
  return item === 'roles' ? <RolesScreen /> : <UsersScreen />;
}

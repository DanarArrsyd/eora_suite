import { Suspense } from 'react';
import type { Metadata } from 'next';
import { SelectTenant } from '@/modules/auth/select-tenant';

export const metadata: Metadata = { title: 'Choose a workspace' };

export default function Page() {
  return (
    <Suspense>
      <SelectTenant />
    </Suspense>
  );
}

import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { allRouteParams, matchRoute, NAV_MODULES } from '@eora/features/navigation';
import { Suspense } from 'react';
import { LoadingState } from '@eora/ui';
import { PlannedPage } from '@/components/planned-page';
import { ADMIN_IMPLEMENTED } from '@/modules/admin/implemented';
import { AdminScreen } from '@/modules/admin/registry';
import { DEMAND_MASTER_IMPLEMENTED, MASTER_IMPLEMENTED } from '@/modules/master/implemented';
import { MasterDataScreen } from '@/modules/master/registry';
import { PPIC_IMPLEMENTED } from '@/modules/ppic/implemented';
import { PpicScreen } from '@/modules/ppic/registry';

type Params = { module: string; item: string };

export const dynamicParams = false;
export function generateStaticParams(): Params[] {
  return allRouteParams(NAV_MODULES);
}

export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { module, item } = await params;
  const match = matchRoute(NAV_MODULES, `/${module}/${item}`);
  return { title: match?.item ? `${match.item.label} · ${match.module.label}` : 'Not found' };
}

export default async function ItemPage({ params }: { params: Promise<Params> }) {
  const { module, item } = await params;
  const match = matchRoute(NAV_MODULES, `/${module}/${item}`);
  if (!match?.item || !match.group) notFound();
  if (module === 'ppic' && (PPIC_IMPLEMENTED as readonly string[]).includes(item)) {
    // Phase 3 Control Tower screens (client; URL-synced list state needs a Suspense boundary).
    return (
      <Suspense fallback={<LoadingState title={`Loading ${match.item.label}`} />}>
        <PpicScreen item={item} />
      </Suspense>
    );
  }
  if (module === 'admin' && (ADMIN_IMPLEMENTED as readonly string[]).includes(item)) {
    // M1 Administration on the live API (URL-synced search/view needs a Suspense boundary).
    return (
      <Suspense fallback={<LoadingState title={`Loading ${match.item.label}`} />}>
        <AdminScreen item={item} />
      </Suspense>
    );
  }
  if (
    (module === 'master' && (MASTER_IMPLEMENTED as readonly string[]).includes(item)) ||
    (module === 'demand' && (DEMAND_MASTER_IMPLEMENTED as readonly string[]).includes(item))
  ) {
    // M2 step 2c part and partner master on the live API (D-074); Demand's master items open the same screens
    return (
      <Suspense fallback={<LoadingState title={`Loading ${match.item.label}`} />}>
        <MasterDataScreen item={item} />
      </Suspense>
    );
  }
  return <PlannedPage module={match.module} item={match.item} group={match.group} />;
}

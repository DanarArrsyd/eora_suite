import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { allRouteParams, matchRoute, NAV_MODULES } from '@eora/features/navigation';
import { Suspense } from 'react';
import { LoadingState } from '@eora/ui';
import { PlannedPage } from '@/components/planned-page';
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
  return <PlannedPage module={match.module} item={match.item} group={match.group} />;
}

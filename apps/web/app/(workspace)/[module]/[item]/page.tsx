import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { allRouteParams, matchRoute, NAV_MODULES } from '@eora/features/navigation';
import { PlannedPage } from '@/components/planned-page';

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
  return <PlannedPage module={match.module} item={match.item} group={match.group} />;
}

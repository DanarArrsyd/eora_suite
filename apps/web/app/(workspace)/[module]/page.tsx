import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { NAV_MODULES } from '@eora/features/navigation';
import { ModuleOverview } from '@/components/module-overview';

type Params = { module: string };

export const dynamicParams = false;
export function generateStaticParams(): Params[] {
  return NAV_MODULES.map((m) => ({ module: m.id }));
}

export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { module } = await params;
  return { title: NAV_MODULES.find((m) => m.id === module)?.label ?? 'Not found' };
}

/** Module overview: the module's menus the user can open (D-126). */
export default async function ModulePage({ params }: { params: Promise<Params> }) {
  const { module: moduleId } = await params;
  if (!NAV_MODULES.some((m) => m.id === moduleId)) notFound();
  return <ModuleOverview moduleId={moduleId} />;
}

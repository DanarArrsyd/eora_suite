import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Badge, Card, CardContent, CardHeader, CardTitle } from '@eora/ui';
import { PageHeader } from '@eora/features/shell';
import { itemHref, NAV_MODULES } from '@eora/features/navigation';

type Params = { module: string };

export const dynamicParams = false;
export function generateStaticParams(): Params[] {
  return NAV_MODULES.map((m) => ({ module: m.id }));
}

export async function generateMetadata({ params }: { params: Promise<Params> }): Promise<Metadata> {
  const { module } = await params;
  return { title: NAV_MODULES.find((m) => m.id === module)?.label ?? 'Not found' };
}

/** Module overview: the module's grouped features, generated from the navigation config. */
export default async function ModulePage({ params }: { params: Promise<Params> }) {
  const { module: moduleId } = await params;
  const mod = NAV_MODULES.find((m) => m.id === moduleId);
  if (!mod) notFound();
  return (
    <div className="mx-auto max-w-[1680px] p-6 max-md:p-4">
      <PageHeader title={mod.label} description="Module overview. Features are grouped by business function; each page is delivered in its planned phase." />
      <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-5">
        {mod.groups.map((g) => (
          <Card key={g.label}>
            <CardHeader>
              <CardTitle>{g.label}</CardTitle>
            </CardHeader>
            <CardContent className="p-2 max-md:p-2">
              <ul>
                {g.items.filter((i) => !i.decision).map((i) => {
                  const Icon = i.icon;
                  return (
                    <li key={i.id}>
                      <Link href={itemHref(mod.id, i.id)} className="flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring">
                        <Icon aria-hidden="true" className="size-4 text-ink-2" />
                        <span className="flex-1">{i.label}</span>
                        <Badge tone="neutral">{i.release}</Badge>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

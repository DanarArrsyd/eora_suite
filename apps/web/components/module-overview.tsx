'use client';

import * as React from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { itemHref, moduleHref } from '@eora/features/navigation';
import { PageHeader, useShellNavigation } from '@eora/features/shell';
import { Card, CardContent, CardHeader, CardTitle, EmptyState } from '@eora/ui';

/**
 * Module overview (D-126): the module's menus the user can open, grouped as in the sidebar. A module with no menu for the
 * user sends them to their first module; a user with no menu at all is told so.
 */
export function ModuleOverview({ moduleId }: { moduleId: string }) {
  const { modules } = useShellNavigation();
  const router = useRouter();
  const mod = modules.find((m) => m.id === moduleId);
  const first = modules[0];
  React.useEffect(() => {
    if (!mod && first) router.replace(moduleHref(first.id));
  }, [mod, first, router]);

  if (!mod) {
    return first ? null : (
      <div className="p-6 max-md:p-4">
        <EmptyState title="No menu for your roles yet" description="Ask a tenant administrator to give your account a role." />
      </div>
    );
  }
  return (
    <div className="mx-auto max-w-[1680px] p-6 max-md:p-4">
      <PageHeader title={mod.label} description="Choose what you want to work on." />
      <div className="grid grid-cols-[repeat(auto-fill,minmax(280px,1fr))] gap-5">
        {mod.groups.map((g) => (
          <Card key={g.label}>
            <CardHeader>
              <CardTitle>{g.label}</CardTitle>
            </CardHeader>
            <CardContent className="p-2 max-md:p-2">
              <ul>
                {g.items.map((i) => {
                  const Icon = i.icon;
                  return (
                    <li key={i.id}>
                      <Link
                        href={itemHref(mod.id, i.id)}
                        className="flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring"
                      >
                        <Icon aria-hidden="true" className="size-4 text-ink-2" />
                        <span className="flex-1">{i.label}</span>
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

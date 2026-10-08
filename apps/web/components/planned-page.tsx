import Link from 'next/link';
import { Construction } from 'lucide-react';
import { Badge, buttonVariants, Card, EmptyState } from '@eora/ui';
import { PageHeader } from '@eora/features/shell';
import { moduleHref, type NavItem, type NavModule } from '@eora/features/navigation';

const PHASE_TEXT: Record<string, string> = {
  '3': 'Phase 3 — PPIC Control Tower frontend',
  '4': 'Phase 4 — Production Reconciliation UI',
  '5': 'Phase 5 — Mobile PWA foundation',
  later: 'a later phase, after its API contract is approved',
};

/** Honest placeholder for routes that exist in navigation but are not built yet (no dead navigation, no fake data). */
export function PlannedPage({ module, item, group }: { module: NavModule; item: NavItem; group: string }) {
  const phase = PHASE_TEXT[String(item.phase ?? 'later')];
  return (
    <div className="mx-auto max-w-[1680px] p-6 max-md:p-4">
      <PageHeader
        title={<>{item.label}<Badge tone="neutral">Release {item.release}</Badge></>}
        description={`${module.label} › ${group}`}
      />
      <Card>
        <EmptyState
          icon={<Construction />}
          title="This page is not built yet"
          description={`The route and navigation are in place. The ${item.label} screen is delivered in ${phase}. No data is shown here to avoid presenting anything as operational.`}
          action={<Link href={moduleHref(module.id)} className={buttonVariants({ variant: 'secondary' })}>Back to {module.label}</Link>}
        />
      </Card>
    </div>
  );
}

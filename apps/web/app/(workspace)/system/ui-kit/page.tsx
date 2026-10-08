import type { Metadata } from 'next';
import { PageHeader } from '@eora/features/shell';
import { UiKitDemos } from '@/components/ui-kit-demos';

export const metadata: Metadata = { title: 'UI kit (Phase 1 review)' };

/** Internal review page for Phase 1 primitives. Not part of module navigation. */
export default function UiKitPage() {
  return (
    <div className="mx-auto max-w-[1280px] p-6 max-md:p-4">
      <PageHeader
        title="UI kit"
        description="Phase 1 shared primitives rendered with the Modern Corporate Blue tokens. Internal review page — not linked from module navigation."
      />
      <UiKitDemos />
    </div>
  );
}

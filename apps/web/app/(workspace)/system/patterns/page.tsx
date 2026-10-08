import type { Metadata } from 'next';
import { EnterprisePage } from '@eora/features/patterns';
import { PatternShowcase } from '@/components/pattern-showcase';

export const metadata: Metadata = { title: 'Enterprise patterns (Phase 2 review)' };

/** Phase 2 component showcase — shared reference for reusable enterprise patterns. Not part of module navigation. */
export default function PatternsPage() {
  return (
    <EnterprisePage
      title="Enterprise patterns"
      mock
      description="Phase 2 shared patterns rendered with sample data. Internal review page — not linked from module navigation."
      className="mx-auto max-w-[1280px]"
    >
      <PatternShowcase />
    </EnterprisePage>
  );
}

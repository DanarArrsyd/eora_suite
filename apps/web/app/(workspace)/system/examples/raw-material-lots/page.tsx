import type { Metadata } from 'next';
import { Suspense } from 'react';
import { LoadingState } from '@eora/ui';
import { RmLotsExample } from '@/components/examples/rm-lots-example';
import type { SimulateMode } from '@/lib/samples/rm-lots.mock';

export const metadata: Metadata = { title: 'Raw material lots (Phase 2 example)' };

const MODES: SimulateMode[] = ['none', 'error', 'forbidden', 'slow', 'empty', 'stale'];

/** Phase 2 review page: enterprise list pattern with a typed mock adapter. Not linked from module navigation. */
export default async function RawMaterialLotsExamplePage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
  const sp = await searchParams;
  const raw = typeof sp.simulate === 'string' ? sp.simulate : 'none';
  const simulate = (MODES as string[]).includes(raw) ? (raw as SimulateMode) : 'none';
  return (
    <Suspense fallback={<LoadingState title="Loading raw material lots" />}>
      <RmLotsExample simulate={simulate} />
    </Suspense>
  );
}

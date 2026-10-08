'use client';

import * as React from 'react';
import { useSearchParams } from 'next/navigation';
import { createControlTowerSources, SIMULATE_MODES, type SimulateMode } from '../mock/sources';

/** Typed mock sources; review pages may simulate adapter responses with `?simulate=` (doc 22 §2). */
export function useCtSources() {
  const params = useSearchParams();
  const raw = params.get('simulate') ?? 'none';
  const mode = (SIMULATE_MODES as string[]).includes(raw) ? (raw as SimulateMode) : 'none';
  const sources = React.useMemo(() => createControlTowerSources(mode), [mode]);
  return { sources, mode };
}

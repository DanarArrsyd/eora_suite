import { describe, expect, it } from 'vitest';
import { NAV_MODULES } from '@eora/features/navigation';
import { PPIC_IMPLEMENTED } from './implemented';
import { PPIC_SCREENS } from './registry';

describe('PPIC Phase 3 registry', () => {
  it('server list, client registry and navigation phase tags agree', () => {
    expect(Object.keys(PPIC_SCREENS).sort()).toEqual([...PPIC_IMPLEMENTED].sort());
    const phase3 = NAV_MODULES.find((m) => m.id === 'ppic')!
      .groups.flatMap((g) => g.items)
      .filter((i) => i.phase === 3)
      .map((i) => i.id);
    expect(phase3.sort()).toEqual([...PPIC_IMPLEMENTED].sort());
  });
});

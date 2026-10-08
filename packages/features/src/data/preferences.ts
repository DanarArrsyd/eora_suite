'use client';

import * as React from 'react';

/**
 * Per-user UI preferences (density, page size, column visibility). Production: stored server-side per user
 * (doc 22 §5); this localStorage adapter is a cached convenience only and never holds business data.
 */
export interface PreferenceSource {
  read(key: string): string | null;
  write(key: string, value: string): void;
  subscribe(listener: () => void): () => void;
}

const listeners = new Set<() => void>();
export const localPreferenceSource: PreferenceSource = {
  read(key) {
    try {
      return window.localStorage.getItem(key);
    } catch {
      return null;
    }
  },
  write(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      /* storage blocked: preference lasts for this page only */
    }
    listeners.forEach((l) => l());
  },
  subscribe(listener) {
    listeners.add(listener);
    const onStorage = () => listener();
    window.addEventListener('storage', onStorage);
    return () => {
      listeners.delete(listener);
      window.removeEventListener('storage', onStorage);
    };
  },
};

/** Typed preference with a server-safe fallback. `parse` rejects unknown stored values. */
export function usePreference<T extends string>(
  key: string,
  fallback: T,
  parse: (raw: string) => T | undefined,
  source: PreferenceSource = localPreferenceSource,
) {
  const raw = React.useSyncExternalStore(
    source.subscribe,
    () => source.read(key),
    () => null,
  );
  const value = (raw !== null ? parse(raw) : undefined) ?? fallback;
  const set = React.useCallback((v: T) => source.write(key, v), [key, source]);
  return [value, set] as const;
}

export type Density = 'comfortable' | 'compact';
/** D-029: Comfortable is the default for new users. */
export function useDensityPreference() {
  return usePreference<Density>('eora.pref.table-density', 'comfortable', (r) => (r === 'compact' || r === 'comfortable' ? r : undefined));
}

export const PAGE_SIZES = [20, 50, 100] as const;
export function usePageSizePreference() {
  return usePreference<string>('eora.pref.page-size', '20', (r) => (PAGE_SIZES.map(String).includes(r) ? r : undefined));
}

/** `(max-width: 767px)` etc. False during SSR; components must render a usable desktop/mobile-neutral fallback. */
export function useMediaQuery(query: string): boolean {
  return React.useSyncExternalStore(
    (cb) => {
      const m = window.matchMedia(query);
      m.addEventListener('change', cb);
      return () => m.removeEventListener('change', cb);
    },
    () => window.matchMedia(query).matches,
    () => false,
  );
}

'use client';

import * as React from 'react';

const subscribe = (cb: () => void) => {
  window.addEventListener('hashchange', cb);
  return () => window.removeEventListener('hashchange', cb);
};

/**
 * One-time secret from the link's URL fragment (`#token=…`): fragments never reach the server, logs or Referer headers
 * (doc 25 §2.3/§2.4). Empty on the server render; `null` when the link has no well-formed token.
 */
export function useHashToken(): string | null | undefined {
  const hash = React.useSyncExternalStore(
    subscribe,
    () => window.location.hash,
    () => undefined,
  );
  if (hash === undefined) return undefined;
  const token = new URLSearchParams(hash.replace(/^#/, '')).get('token');
  return token && /^[0-9a-f]{64}$/.test(token) ? token : null;
}

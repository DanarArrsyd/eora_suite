'use client';

import * as React from 'react';
import { MutationCache, QueryCache, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ApiError } from '../auth/api';
import { ME_KEY } from '../auth/session';

/** One QueryClient per browser session; list queries keep previous data while a new page loads. */
export function EoraQueryProvider({ children }: { children: React.ReactNode }) {
  const [client] = React.useState(() => {
    // Any 401 from the API (session expired, credentials changed, membership suspended) signs the shell out: the auth
    // gate sees `me = null` and redirects to sign-in.
    const onError = (error: unknown) => {
      if (error instanceof ApiError && error.status === 401) client.setQueryData(ME_KEY, null);
    };
    const client: QueryClient = new QueryClient({
      queryCache: new QueryCache({ onError }),
      mutationCache: new MutationCache({ onError }),
      defaultOptions: {
        queries: {
          staleTime: 30_000,
          retry: (count, error) => count < 1 && !(error instanceof Error && error.name === 'SourceError'),
          refetchOnWindowFocus: false,
        },
      },
    });
    return client;
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

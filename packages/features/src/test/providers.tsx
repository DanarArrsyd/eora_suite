import * as React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ShellSessionProvider } from '../shell/session';
import { FeedbackProvider } from '../patterns/feedback';

/** Test wrapper: fresh QueryClient (no retries), prototype session and feedback provider. */
export function Providers({ children }: { children: React.ReactNode }) {
  const [client] = React.useState(() => new QueryClient({ defaultOptions: { queries: { retry: false } } }));
  return (
    <QueryClientProvider client={client}>
      <ShellSessionProvider>
        <FeedbackProvider>{children}</FeedbackProvider>
      </ShellSessionProvider>
    </QueryClientProvider>
  );
}

'use client';

import { Button, ErrorState } from '@eora/ui';

export default function WorkspaceError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <ErrorState
      title="This page could not be displayed"
      description={`Something went wrong while rendering. Reference ${error.digest ?? 'n/a'} — share it with support if the problem continues.`}
      action={
        <Button variant="primary" onClick={reset}>
          Try again
        </Button>
      }
    />
  );
}

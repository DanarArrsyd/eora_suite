import { AuthGate } from '@eora/features/auth';
import { EoraQueryProvider } from '@eora/features/data';
import { FeedbackProvider } from '@eora/features/patterns';
import { AppShell } from '@eora/features/shell';

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <EoraQueryProvider>
      <AuthGate>
        <FeedbackProvider>
          <AppShell>{children}</AppShell>
        </FeedbackProvider>
      </AuthGate>
    </EoraQueryProvider>
  );
}

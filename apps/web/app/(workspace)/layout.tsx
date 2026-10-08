import { EoraQueryProvider } from '@eora/features/data';
import { FeedbackProvider } from '@eora/features/patterns';
import { AppShell, ShellSessionProvider } from '@eora/features/shell';

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <EoraQueryProvider>
      <ShellSessionProvider>
        <FeedbackProvider>
          <AppShell>{children}</AppShell>
        </FeedbackProvider>
      </ShellSessionProvider>
    </EoraQueryProvider>
  );
}

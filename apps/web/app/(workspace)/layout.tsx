import { AppShell, ShellSessionProvider } from '@eora/features/shell';

export default function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  return (
    <ShellSessionProvider>
      <AppShell>{children}</AppShell>
    </ShellSessionProvider>
  );
}

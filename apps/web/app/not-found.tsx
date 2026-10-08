import Link from 'next/link';
import { buttonVariants, EmptyState } from '@eora/ui';

export default function NotFound() {
  return (
    <main className="grid min-h-dvh place-items-center bg-workspace p-6">
      <EmptyState
        title="Page not found"
        description="This address is not part of EORA navigation, or you do not have access to it."
        action={<Link href="/ppic" className={buttonVariants({ variant: 'primary' })}>Go to PPIC / Planning</Link>}
      />
    </main>
  );
}

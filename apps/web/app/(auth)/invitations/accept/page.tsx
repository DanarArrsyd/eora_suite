import type { Metadata } from 'next';
import { AcceptInvitation } from '@/modules/auth/accept-invitation';

export const metadata: Metadata = { title: 'Set your password' };

export default function Page() {
  return <AcceptInvitation />;
}

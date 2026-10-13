import type { Metadata } from 'next';
import { ResetPassword } from '@/modules/auth/reset-password';

export const metadata: Metadata = { title: 'Choose a new password' };

export default function Page() {
  return <ResetPassword />;
}

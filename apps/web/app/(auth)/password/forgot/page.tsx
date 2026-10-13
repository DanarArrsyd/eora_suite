import type { Metadata } from 'next';
import { ForgotPassword } from '@/modules/auth/forgot-password';

export const metadata: Metadata = { title: 'Reset your password' };

export default function Page() {
  return <ForgotPassword />;
}

import { Suspense } from 'react';
import type { Metadata } from 'next';
import { LoginForm } from '@/modules/auth/login-form';

export const metadata: Metadata = { title: 'Sign in' };

export default function Page() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}

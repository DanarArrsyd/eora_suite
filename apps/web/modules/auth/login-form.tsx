'use client';

import * as React from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { ApiError, failureMessage, safeNext, useMe, useSessionActions } from '@eora/features/auth';
import { EoraForm, FormTextField, type SubmitResult } from '@eora/features/patterns';
import { LoadingState } from '@eora/ui';
import { AuthCard } from './auth-card';

const schema = z.object({
  email: z.string().trim().min(1, 'Enter your e-mail address.').max(254),
  password: z.string().min(1, 'Enter your password.').max(1024),
});

export function LoginForm() {
  const router = useRouter();
  const next = safeNext(useSearchParams().get('next'));
  const me = useMe();
  const { login, selectTenant } = useSessionActions();
  const signedIn = Boolean(me.data);

  // Already signed in (e.g. back button): continue where the user was going.
  React.useEffect(() => {
    if (signedIn) router.replace(next);
  }, [signedIn, next, router]);

  const onSubmit = async (values: z.output<typeof schema>): Promise<SubmitResult> => {
    try {
      let session = await login.mutateAsync(values);
      if (!session.activeTenantId && session.tenants.length === 1) session = await selectTenant.mutateAsync(session.tenants[0]!.id);
      router.replace(session.activeTenantId ? next : `/select-tenant?next=${encodeURIComponent(next)}`);
      return { ok: true, message: 'Signed in' };
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        return { ok: false, message: 'The e-mail or password is not correct, or the account is temporarily locked. Try again or reset your password.' };
      }
      if (e instanceof ApiError && e.status === 429) return { ok: false, message: 'Too many sign-in attempts. Wait one minute, then try again.' };
      if (e instanceof ApiError && e.status === 422) return { ok: false, message: 'Check the highlighted fields.', fieldErrors: e.fieldErrors };
      return { ok: false, message: failureMessage(e, 'Sign-in failed.') };
    }
  };

  if (me.isPending || signedIn) return <LoadingState title={signedIn ? 'Opening your workspace…' : 'Checking your session…'} />;

  return (
    <AuthCard
      title="Sign in"
      description="Use the e-mail address your administrator invited."
      footer={<>New to EORA? Ask your administrator for an invitation. It contains the link to set your password.</>}
    >
      <EoraForm schema={schema} defaultValues={{ email: '', password: '' }} onSubmit={onSubmit} label="Sign in" submitLabel="Sign in" actionsLayout="stack">
        <FormTextField name="email" label="E-mail" type="email" autoComplete="username" inputMode="email" />
        <FormTextField name="password" label="Password" type="password" autoComplete="current-password" />
        <Link href="/password/forgot" className="-mt-1 self-start text-[13px] text-link underline-offset-2 hover:underline">
          Forgot your password?
        </Link>
      </EoraForm>
    </AuthCard>
  );
}

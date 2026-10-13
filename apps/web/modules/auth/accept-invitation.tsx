'use client';

import * as React from 'react';
import Link from 'next/link';
import type { z } from 'zod';
import { api, ApiError, call, failureMessage } from '@eora/features/auth';
import { EoraForm, FormTextField, type SubmitResult } from '@eora/features/patterns';
import { Button, ErrorState, LoadingState } from '@eora/ui';
import { AuthCard } from './auth-card';
import { useHashToken } from './hash-token';
import { newPasswordSchema, PASSWORD_HINT } from './schemas';

/** Invitation acceptance (doc 25 §2.4): the delivered secret sets the first password and activates the membership. */
export function AcceptInvitation() {
  const token = useHashToken();
  const [done, setDone] = React.useState(false);

  if (token === undefined) return <LoadingState title="Reading your invitation link…" />;
  if (token === null) {
    return (
      <ErrorState
        title="This invitation link is incomplete"
        description="Open the link from your invitation e-mail again. If it still fails, ask your administrator to resend the invitation."
      />
    );
  }
  if (done) {
    return (
      <AuthCard title="Your account is ready" description="Your password is set and your membership is active.">
        <Button asChild variant="primary" className="w-full">
          <Link href="/login">Sign in</Link>
        </Button>
      </AuthCard>
    );
  }

  const onSubmit = async (values: z.output<typeof newPasswordSchema>): Promise<SubmitResult> => {
    try {
      await call(() => api.POST('/auth/invitations/accept', { body: { token, ...values } }));
      setDone(true);
      return { ok: true, message: 'Password set' };
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const fields = e.fieldErrors;
        if (fields.token) return { ok: false, message: 'This invitation link is invalid, already used or expired. Ask your administrator to resend it.' };
        return { ok: false, message: 'Check the highlighted fields.', fieldErrors: fields };
      }
      if (e instanceof ApiError && e.status === 409) return { ok: false, message: failureMessage(e) };
      if (e instanceof ApiError && e.status === 429) return { ok: false, message: 'Too many attempts. Wait one minute, then try again.' };
      return { ok: false, message: failureMessage(e, 'The invitation could not be accepted.') };
    }
  };

  return (
    <AuthCard title="Set your password" description="Choose a password to activate your account. You sign in with it from now on.">
      <EoraForm
        schema={newPasswordSchema}
        defaultValues={{ password: '', password_confirmation: '' }}
        onSubmit={onSubmit}
        label="Set your password"
        submitLabel="Activate account"
        actionsLayout="stack"
      >
        <FormTextField name="password" label="New password" type="password" autoComplete="new-password" hint={PASSWORD_HINT} required />
        <FormTextField name="password_confirmation" label="Repeat password" type="password" autoComplete="new-password" required />
      </EoraForm>
    </AuthCard>
  );
}

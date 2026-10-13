'use client';

import * as React from 'react';
import Link from 'next/link';
import { useQueryClient } from '@tanstack/react-query';
import type { z } from 'zod';
import { api, ApiError, call, failureMessage, ME_KEY } from '@eora/features/auth';
import { EoraForm, FormTextField, type SubmitResult } from '@eora/features/patterns';
import { Button, ErrorState, LoadingState } from '@eora/ui';
import { AuthCard } from './auth-card';
import { useHashToken } from './hash-token';
import { newPasswordSchema, PASSWORD_HINT } from './schemas';

/** Reset with the delivered secret (doc 25 §2.3): success ends every session of the account. */
export function ResetPassword() {
  const token = useHashToken();
  const client = useQueryClient();
  const [done, setDone] = React.useState(false);

  if (token === undefined) return <LoadingState title="Reading your reset link…" />;
  if (token === null) {
    return (
      <ErrorState
        title="This reset link is incomplete"
        description="Open the link from the e-mail again, or request a new one from the sign-in page."
        action={
          <Button asChild variant="secondary">
            <Link href="/password/forgot">Request a new link</Link>
          </Button>
        }
      />
    );
  }
  if (done) {
    return (
      <AuthCard title="Password changed" description="Every session that used the old password is signed out. Sign in with the new password.">
        <Button asChild variant="primary" className="w-full">
          <Link href="/login">Sign in</Link>
        </Button>
      </AuthCard>
    );
  }

  const onSubmit = async (values: z.output<typeof newPasswordSchema>): Promise<SubmitResult> => {
    try {
      await call(() => api.POST('/auth/password/reset', { body: { token, ...values } }));
      client.setQueryData(ME_KEY, null);
      setDone(true);
      return { ok: true, message: 'Password changed' };
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const fields = e.fieldErrors;
        if (fields.token) return { ok: false, message: 'This reset link is invalid, already used or expired. Request a new link.' };
        return { ok: false, message: 'Check the highlighted fields.', fieldErrors: fields };
      }
      if (e instanceof ApiError && e.status === 429) return { ok: false, message: 'Too many attempts. Wait one minute, then try again.' };
      return { ok: false, message: failureMessage(e, 'The password could not be changed.') };
    }
  };

  return (
    <AuthCard title="Choose a new password" description="The new password replaces the old one on every device.">
      <EoraForm
        schema={newPasswordSchema}
        defaultValues={{ password: '', password_confirmation: '' }}
        onSubmit={onSubmit}
        label="Choose a new password"
        submitLabel="Change password"
        actionsLayout="stack"
      >
        <FormTextField name="password" label="New password" type="password" autoComplete="new-password" hint={PASSWORD_HINT} required />
        <FormTextField name="password_confirmation" label="Repeat password" type="password" autoComplete="new-password" required />
      </EoraForm>
    </AuthCard>
  );
}

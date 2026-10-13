'use client';

import * as React from 'react';
import Link from 'next/link';
import { z } from 'zod';
import { api, ApiError, call, failureMessage } from '@eora/features/auth';
import { EoraForm, FormTextField, type SubmitResult } from '@eora/features/patterns';
import { AuthCard } from './auth-card';

const schema = z.object({ email: z.string().trim().min(1, 'Enter your e-mail address.').max(254) });

/** Forgot password (doc 25 §2.3): the answer is the same whether or not the address has an account. */
export function ForgotPassword() {
  const [sentTo, setSentTo] = React.useState<string | null>(null);

  const onSubmit = async (values: z.output<typeof schema>): Promise<SubmitResult> => {
    try {
      await call(() => api.POST('/auth/password/forgot', { body: values }));
      setSentTo(values.email);
      return { ok: true };
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) return { ok: false, message: 'Check the highlighted field.', fieldErrors: e.fieldErrors };
      return { ok: false, message: failureMessage(e, 'The request could not be sent.') };
    }
  };

  if (sentTo) {
    return (
      <AuthCard
        title="Check your e-mail"
        description={`If ${sentTo} belongs to an account, a reset link is on its way. The link works once and expires after 30 minutes.`}
        footer={
          <Link href="/login" className="text-link underline-offset-2 hover:underline">
            Back to sign in
          </Link>
        }
      >
        <p className="m-0 text-sm text-ink-2">
          No e-mail after a few minutes? Check the spam folder, then request a new link. Each new request replaces the previous link.
        </p>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Reset your password"
      description="Enter the e-mail address you sign in with. We send a link to choose a new password."
      footer={
        <Link href="/login" className="text-link underline-offset-2 hover:underline">
          Back to sign in
        </Link>
      }
    >
      <EoraForm
        schema={schema}
        defaultValues={{ email: '' }}
        onSubmit={onSubmit}
        label="Reset your password"
        submitLabel="Send reset link"
        actionsLayout="stack"
      >
        <FormTextField name="email" label="E-mail" type="email" autoComplete="username" inputMode="email" />
      </EoraForm>
    </AuthCard>
  );
}

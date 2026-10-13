import { ApiError, failureMessage } from '@eora/features/auth';
import type { SubmitResult } from '@eora/features/patterns';

/* Problem codes of the access endpoints (doc 24 §5) in words an administrator can act on. Nothing names a permission. */
const MESSAGES: Record<string, string> = {
  FORBIDDEN: 'Your roles do not allow this action.',
  SCOPE_FORBIDDEN: 'This is outside the scope you administer. Ask an administrator with a wider scope.',
  RATE_LIMITED: 'Too many requests. Wait a minute and try again.',
  RETRYABLE_CONFLICT: 'Someone changed this at the same time. Try again.',
};

/** One sentence for a failed access action; the server detail wins when it explains the case (SoD, state changes). */
export function actionMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const code = error.code ?? '';
    if (code !== 'SOD_VIOLATION' && code !== 'INVALID_STATE_TRANSITION' && MESSAGES[code]) {
      return error.problem?.reference ? `${MESSAGES[code]} Reference ${error.problem.reference}.` : MESSAGES[code];
    }
  }
  return failureMessage(error, fallback);
}

/** EoraForm result for a failed submit: field errors of a 422 go back onto their fields. */
export function formFailure(error: unknown, fallback: string): SubmitResult {
  if (error instanceof ApiError && error.status === 422) {
    return { ok: false, message: 'Check the highlighted fields.', fieldErrors: error.fieldErrors };
  }
  return { ok: false, message: actionMessage(error, fallback) };
}

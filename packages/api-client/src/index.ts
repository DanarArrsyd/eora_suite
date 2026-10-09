import createClient from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Typed EORA API client (doc 24 §2, ADR-0013). Types are generated from `openapi.json`, which Laravel Scramble exports
 * (`composer openapi:export` in apps/api) — never edit `schema.d.ts` by hand; CI fails when either file drifts.
 * Same-origin session cookies (ADR-0006): no tokens in JS; CSRF header added by the caller for mutations (B1).
 */
export type { paths } from './schema';

export function createApiClient(baseUrl = '/api/v1') {
  return createClient<paths>({ baseUrl, credentials: 'include', headers: { Accept: 'application/json' } });
}

/** RFC 9457 problem+json body returned by every /api error (doc 24 §5). */
export type ProblemDetails = {
  type: string;
  title: string;
  status: number;
  code: string;
  detail?: string;
  correlationId?: string;
  reference?: string;
  errors?: Record<string, string[]>;
};

export function isProblemDetails(value: unknown): value is ProblemDetails {
  return (
    typeof value === 'object' && value !== null && typeof (value as ProblemDetails).code === 'string' && typeof (value as ProblemDetails).status === 'number'
  );
}

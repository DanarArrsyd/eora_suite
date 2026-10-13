import { createApiClient, isProblemDetails, type ProblemDetails } from '@eora/api-client';

/**
 * Same-origin API client for the browser (ADR-0006): session cookie + Sanctum CSRF. Every state-changing request carries
 * `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie; the cookie is fetched once from `/sanctum/csrf-cookie` when missing.
 * No token is ever stored in JavaScript state or storage.
 */
export const api = createApiClient();

const SAFE = new Set(['GET', 'HEAD', 'OPTIONS']);

function readXsrf(): string | undefined {
  if (typeof document === 'undefined') return undefined;
  const raw = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='));
  return raw ? decodeURIComponent(raw.slice('XSRF-TOKEN='.length)) : undefined;
}

export async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readXsrf()) return;
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } });
}

api.use({
  async onRequest({ request }) {
    if (SAFE.has(request.method)) return request;
    await ensureCsrfCookie();
    const token = readXsrf();
    if (token) request.headers.set('X-XSRF-TOKEN', token);
    return request;
  },
});

/** An /api failure with its problem+json body (doc 24 §5) when the server sent one. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly problem: ProblemDetails | null,
  ) {
    super(problem?.title ?? `Request failed (${status})`);
    this.name = 'ApiError';
  }

  get code(): string | undefined {
    return this.problem?.code;
  }

  /** Field errors of a 422 VALIDATION_FAILED, first message per field. */
  get fieldErrors(): Record<string, string> {
    return Object.fromEntries(Object.entries(this.problem?.errors ?? {}).map(([k, v]) => [k, v[0] ?? 'Invalid value.']));
  }
}

type Result<T> = { data?: T; error?: unknown; response: Response };

/** Unwraps an openapi-fetch result: the data, or an ApiError. A 419 retries once with a fresh CSRF cookie. */
export async function call<T>(request: () => Promise<Result<T>>): Promise<T> {
  let result = await request();
  if (result.response.status === 419) {
    await ensureCsrfCookie(true);
    result = await request();
  }
  if (result.response.ok) return result.data as T;
  throw new ApiError(result.response.status, isProblemDetails(result.error) ? result.error : null);
}

/** Message safe to show for an unexpected failure, with the support reference when present. */
export function failureMessage(error: unknown, fallback = 'The request could not be completed.'): string {
  if (error instanceof ApiError) {
    const base = error.problem?.detail ?? error.problem?.title ?? fallback;
    return error.problem?.reference ? `${base} Reference ${error.problem.reference}.` : base;
  }
  return `${fallback} Check your connection and try again.`;
}

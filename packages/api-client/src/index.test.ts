import { describe, expect, expectTypeOf, it, vi } from 'vitest';
import { createApiClient, isProblemDetails } from './index';

describe('api-client', () => {
  it('calls the typed health endpoint on the same-origin base URL with credentials', async () => {
    const fetchMock = vi.fn(
      async (_req: Request) =>
        new Response(JSON.stringify({ status: 'ok', service: 'EORA Suite API', time: '2026-10-09T00:00:00Z' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
    );
    const client = createApiClient('http://localhost/api/v1');
    const { data, error } = await client.GET('/health', { fetch: fetchMock });
    const req = fetchMock.mock.calls[0]![0];
    expect(req.url).toBe('http://localhost/api/v1/health');
    expect(req.credentials).toBe('include');
    expect(error).toBeUndefined();
    expect(data?.status).toBe('ok');
  });

  it('exposes a degraded readiness (503) as a typed error body', async () => {
    const degraded = {
      status: 'degraded',
      checks: { database: { status: 'fail', latencyMs: 3.2, error: 'QueryException' }, redis: { status: 'ok', latencyMs: 1.1, version: '8.6.7' } },
      time: '2026-10-09T00:00:00Z',
    };
    const fetchMock = vi.fn(async (_req: Request) => new Response(JSON.stringify(degraded), { status: 503, headers: { 'Content-Type': 'application/json' } }));
    const { data, error, response } = await createApiClient('http://localhost/api/v1').GET('/health/ready', { fetch: fetchMock });

    expect(response.status).toBe(503);
    expect(data).toBeUndefined();
    expect(error).toEqual(degraded);
    // Compile-time contract (checked by `tsc` in the typecheck task): 503 is a documented response, not `never`/unknown.
    expectTypeOf<NonNullable<typeof error>['status']>().toEqualTypeOf<'degraded'>();
    expectTypeOf<NonNullable<typeof error>['checks']['database']['status']>().toEqualTypeOf<'ok' | 'fail'>();
    expectTypeOf<NonNullable<typeof data>['status']>().toEqualTypeOf<'ok'>();
  });

  it('recognises problem+json bodies', () => {
    expect(isProblemDetails({ type: 'https://errors.eora.app/NOT_FOUND', title: 'Not found', status: 404, code: 'NOT_FOUND' })).toBe(true);
    expect(isProblemDetails({ status: 'ok' })).toBe(false);
  });
});

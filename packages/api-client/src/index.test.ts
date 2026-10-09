import { describe, expect, it, vi } from 'vitest';
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

  it('recognises problem+json bodies', () => {
    expect(isProblemDetails({ type: 'https://errors.eora.app/NOT_FOUND', title: 'Not found', status: 404, code: 'NOT_FOUND' })).toBe(true);
    expect(isProblemDetails({ status: 'ok' })).toBe(false);
  });
});

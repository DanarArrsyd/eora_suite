import { describe, expect, it } from 'vitest';
import { ApiError, call, failureMessage } from './api';
import { safeNext } from './session';

const response = (status: number) => new Response(null, { status });

describe('safeNext (no open redirect after sign-in)', () => {
  it.each([
    ['/ppic/control-tower?view=hold', '/ppic/control-tower?view=hold'],
    ['//evil.example/x', '/'],
    ['/\\evil.example', '/'],
    ['https://evil.example', '/'],
    ['', '/'],
    [null, '/'],
  ])('%s → %s', (next, expected) => {
    expect(safeNext(next)).toBe(expected);
  });
});

describe('call / ApiError', () => {
  it('returns data on success and turns a problem+json body into ApiError with field errors', async () => {
    expect(await call(async () => ({ data: { ok: 1 }, response: response(200) }))).toEqual({ ok: 1 });

    const problem = {
      type: 't',
      title: 'Validation failed',
      status: 422,
      code: 'VALIDATION_FAILED',
      errors: { email: ['Required.', 'Second.'] },
      reference: 'ab12-cd34',
    };
    const error = await call(async () => ({ error: problem, response: response(422) })).catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).code).toBe('VALIDATION_FAILED');
    expect((error as ApiError).fieldErrors).toEqual({ email: 'Required.' });
    expect(failureMessage(error)).toBe('Validation failed Reference ab12-cd34.');
  });

  it('does not trust a body that is not problem+json', async () => {
    const error = (await call(async () => ({ error: '<html>', response: response(502) })).catch((e: unknown) => e)) as ApiError;
    expect(error.problem).toBeNull();
    expect(failureMessage(new TypeError('offline'))).toBe('The request could not be completed. Check your connection and try again.');
  });
});

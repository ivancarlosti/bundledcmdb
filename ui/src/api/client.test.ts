import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  apiRequest,
  apiRequestEnvelope,
  apiUrl,
  buildQuery,
  getCsrfToken,
  PanelApiError,
  setCsrfToken,
} from './client';

/**
 * The three rules in `client.ts` are the contract every screen depends on:
 * CSRF on writes only, envelope unwrapping, and a typed error. A regression here
 * shows up as a 419 or an "undefined" toast in production, not as a type error,
 * so it is worth pinning down with unit tests.
 */

type FetchCall = { url: string; init: RequestInit };

const calls: FetchCall[] = [];

function respond(payload: unknown, status = 200, statusText = 'OK') {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText,
    json: async () => payload,
  } as unknown as Response;
}

function stubFetch(handler: () => Response | Promise<Response>) {
  const mock = vi.fn(async (url: string, init: RequestInit) => {
    calls.push({ url, init });
    return handler();
  });
  vi.stubGlobal('fetch', mock);
}

beforeEach(() => {
  calls.length = 0;
  setCsrfToken(null);
});

afterEach(() => {
  vi.unstubAllGlobals();
  setCsrfToken(null);
});

describe('apiRequest', () => {
  it('unwraps the envelope and sends a plain GET without a CSRF header', async () => {
    stubFetch(() => respond({ ok: true, data: { rows: [] }, meta: { total: 0 } }));

    const data = await apiRequest<{ rows: unknown[] }>('/devices');

    expect(data).toEqual({ rows: [] });
    expect(calls).toHaveLength(1);
    expect(calls[0]?.url).toBe('/panel-api/v1/devices');
    expect(calls[0]?.init.headers).toMatchObject({ Accept: 'application/json' });
    expect(calls[0]?.init.headers).not.toHaveProperty('X-CSRF-Token');
    expect(calls[0]?.init.credentials).toBe('same-origin');
    expect(calls[0]?.init.body).toBeUndefined();
  });

  it('adds the CSRF token and a JSON content type to writes', async () => {
    setCsrfToken('csrf-123');
    stubFetch(() => respond({ ok: true, data: { id: 1 } }));

    await apiRequest('/devices/1', { method: 'PATCH', body: { sn: 'SN-1' } });

    expect(getCsrfToken()).toBe('csrf-123');
    expect(calls[0]?.init.method).toBe('PATCH');
    expect(calls[0]?.init.headers).toMatchObject({
      'Content-Type': 'application/json',
      'X-CSRF-Token': 'csrf-123',
    });
    expect(calls[0]?.init.body).toBe('{"sn":"SN-1"}');
  });

  it('lets a multipart body win over json and keeps the browser boundary', async () => {
    setCsrfToken('csrf-123');
    stubFetch(() => respond({ ok: true, data: { id: 7 } }));
    const form = new FormData();
    form.set('file', new Blob(['x']), 'invoice.pdf');

    await apiRequest('/devices/1/files', { method: 'POST', form, body: { ignored: true } });

    expect(calls[0]?.init.body).toBe(form);
    // No Content-Type: the browser has to append the boundary itself.
    expect(calls[0]?.init.headers).not.toHaveProperty('Content-Type');
    expect(calls[0]?.init.headers).toMatchObject({ 'X-CSRF-Token': 'csrf-123' });
  });

  it('returns the meta block alongside the payload', async () => {
    stubFetch(() => respond({ ok: true, data: [1], meta: { restored: true } }));

    const result = await apiRequestEnvelope<number[]>('/devices/1/restore', { method: 'POST' });

    expect(result.data).toEqual([1]);
    expect(result.meta).toEqual({ restored: true });
  });

  it('turns an error envelope into a typed PanelApiError', async () => {
    stubFetch(() => respond({ ok: false, error: { code: 'stale_record', message: 'Reload first.' } }, 409, 'Conflict'));

    const error = await apiRequest('/devices/1', { method: 'PATCH', body: {} }).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(PanelApiError);
    const apiError = error as PanelApiError;
    expect(apiError.code).toBe('stale_record');
    expect(apiError.status).toBe(409);
    expect(apiError.message).toBe('Reload first.');
    expect(apiError.isStale).toBe(true);
    expect(apiError.isUnauthorized).toBe(false);
    expect(apiError.isValidation).toBe(false);
  });

  it('keeps per-field validation errors', async () => {
    stubFetch(() =>
      respond(
        {
          ok: false,
          error: {
            code: 'validation_failed',
            message: 'Invalid payload.',
            errors: { sn: 'required', status: 'invalid_option' },
          },
        },
        422,
        'Unprocessable Content',
      ),
    );

    const error = (await apiRequest('/devices', { method: 'POST', body: {} }).catch(
      (caught: unknown) => caught,
    )) as PanelApiError;

    expect(error.isValidation).toBe(true);
    expect(error.errors).toEqual({ sn: 'required', status: 'invalid_option' });
  });

  it('flags a 401 so the shell can show the login screen', async () => {
    stubFetch(() => respond({ ok: false, error: { code: 'unauthorized', message: 'Sign in.' } }, 401, 'Unauthorized'));

    const error = (await apiRequest('/session').catch((caught: unknown) => caught)) as PanelApiError;

    expect(error.isUnauthorized).toBe(true);
    expect(error.status).toBe(401);
  });

  it('falls back to a generic error when the body is not JSON', async () => {
    stubFetch(
      () =>
        ({
          ok: false,
          status: 502,
          statusText: 'Bad Gateway',
          json: async () => {
            throw new SyntaxError('Unexpected token <');
          },
        }) as unknown as Response,
    );

    const error = (await apiRequest('/devices').catch((caught: unknown) => caught)) as PanelApiError;

    expect(error).toBeInstanceOf(PanelApiError);
    expect(error.code).toBe('unknown_error');
    expect(error.message).toBe('Bad Gateway');
    expect(error.status).toBe(502);
  });

  it('returns the raw response for downloads and still throws on failure', async () => {
    stubFetch(() => respond('SN;Hostname\n', 200));
    const response = await apiRequest<{ status: number }>('/devices/export', { raw: true });
    expect(response.status).toBe(200);

    stubFetch(() => respond({ ok: false, error: { code: 'forbidden', message: 'No.' } }, 403));
    const error = (await apiRequest('/devices/export', { raw: true }).catch(
      (caught: unknown) => caught,
    )) as PanelApiError;
    expect(error.code).toBe('forbidden');
    expect(error.status).toBe(403);
  });
});

describe('buildQuery', () => {
  it('skips empty values so URLs stay readable', () => {
    expect(buildQuery({ q: 'laptop', page: 2, status: '', source: null, trashed: false, byod: undefined })).toBe(
      '?q=laptop&page=2&trashed=false',
    );
  });

  it('returns an empty string when nothing is set', () => {
    expect(buildQuery({ q: '', page: null })).toBe('');
  });

  it('escapes values', () => {
    expect(buildQuery({ q: 'a&b=c' })).toBe('?q=a%26b%3Dc');
  });
});

describe('apiUrl', () => {
  it('prefixes the versioned mount point', () => {
    expect(apiUrl('/devices/export?sort=SN')).toBe('/panel-api/v1/devices/export?sort=SN');
  });
});

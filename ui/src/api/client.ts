/**
 * Thin typed client for `/panel-api/v1`.
 *
 * Every call goes through this module so three rules are enforced in one place:
 *  - the CSRF token header is added to non-GET requests (session cookie only,
 *    no tokens in JS storage);
 *  - the `{ok, data, meta}` envelope is unwrapped, and an error envelope becomes
 *    a typed `PanelApiError` carrying `code`/`message`/`errors`;
 *  - `401` is reported with `isUnauthorized` so the shell can show the login
 *    screen instead of a generic failure.
 */

export type ApiErrorEnvelope = {
  code: string;
  message: string;
  errors?: Record<string, string>;
};

export type ApiEnvelope<T> = {
  ok: boolean;
  data?: T;
  meta?: Record<string, unknown>;
  error?: ApiErrorEnvelope;
};

/** Payload plus the `meta` block (sort echo, effective filters, timings). */
export type ApiResult<T> = {
  data: T;
  meta: Record<string, unknown>;
};

export class PanelApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly errors: Record<string, string>;

  constructor(error: ApiErrorEnvelope, status: number) {
    super(error.message);
    this.name = 'PanelApiError';
    this.code = error.code;
    this.status = status;
    this.errors = error.errors ?? {};
  }

  get isUnauthorized(): boolean {
    return this.status === 401 || this.code === 'unauthorized';
  }

  get isValidation(): boolean {
    return this.code === 'validation_failed';
  }

  get isStale(): boolean {
    return this.code === 'stale_record';
  }
}

const API_BASE = '/panel-api/v1';

let csrfToken: string | null = null;

/** Store the token returned by `GET /session` (rotated on every login). */
export function setCsrfToken(token: string | null): void {
  csrfToken = token;
}

export function getCsrfToken(): string | null {
  return csrfToken;
}

/** Build a query string, skipping empty values so URLs stay readable. */
export function buildQuery(params: Record<string, string | number | boolean | null | undefined>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue;
    }
    search.set(key, String(value));
  }
  const query = search.toString();
  return query === '' ? '' : `?${query}`;
}

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  signal?: AbortSignal;
  /** Skip the JSON envelope and return the raw Response (e.g. CSV downloads). */
  raw?: boolean;
};

/** Absolute-in-app URL for direct navigation (CSV export, downloads). */
export function apiUrl(path: string): string {
  return `${API_BASE}${path}`;
}

/** Perform one panel API call and return the unwrapped `data` payload. */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const result = await apiRequestEnvelope<T>(path, options);
  return result.data;
}

/**
 * Same as `apiRequest`, but keeps the `meta` block. The device grid needs it to
 * echo the sort and the effective company scope the server actually applied.
 */
export async function apiRequestEnvelope<T>(path: string, options: RequestOptions = {}): Promise<ApiResult<T>> {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json' };

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (method !== 'GET' && csrfToken !== null) {
    headers['X-CSRF-Token'] = csrfToken;
  }

  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    signal: options.signal,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  if (options.raw) {
    if (!response.ok) {
      throw new PanelApiError(await readError(response), response.status);
    }
    return { data: response as unknown as T, meta: {} };
  }

  let payload: ApiEnvelope<T> | null = null;
  try {
    payload = (await response.json()) as ApiEnvelope<T>;
  } catch {
    payload = null;
  }

  if (!response.ok || payload === null || payload.ok !== true) {
    throw new PanelApiError(payload?.error ?? { code: 'unknown_error', message: response.statusText }, response.status);
  }

  return { data: payload.data as T, meta: payload.meta ?? {} };
}

async function readError(response: Response): Promise<ApiErrorEnvelope> {
  try {
    const payload = (await response.json()) as ApiEnvelope<unknown>;
    if (payload?.error) {
      return payload.error;
    }
  } catch {
    /* fall through to the generic message */
  }
  return { code: 'http_error', message: response.statusText || `HTTP ${response.status}` };
}

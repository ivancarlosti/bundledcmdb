/**
 * "Collect data" endpoints (`lib/panel/collect.php`).
 *
 * The download is returned as text, not as a URL: the token it embeds has to
 * become a file on the user's machine, and the panel never hands out a token by
 * session afterwards. `token` carries only public metadata (never the raw value).
 */

import { apiRequest } from '@/api/client';

export type CollectToken = {
  id: number | null;
  file_name: string;
  created_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  uses: number;
};

export type CollectStatus = {
  enabled: boolean;
  ttl_minutes: number;
  rate_limit: number;
  script_version: string;
  /** Absolute URL the downloaded script posts to. */
  endpoint: string;
  token: CollectToken | null;
  can_collect: boolean;
};

export type CollectFormat = 'bat' | 'ps1';

export type CollectFile = {
  name: string;
  format: CollectFormat;
  content: string;
  expires_at: string | null;
};

export type CollectFileResult = { file: CollectFile; token: CollectToken };

export function fetchCollect(): Promise<CollectStatus> {
  return apiRequest<CollectStatus>('/collect');
}

export function createCollectFile(format: CollectFormat): Promise<CollectFileResult> {
  return apiRequest<CollectFileResult>('/collect/file', { method: 'POST', body: { format } });
}

export function revokeCollectFile(): Promise<{ revoked: number }> {
  return apiRequest<{ revoked: number }>('/collect/file', { method: 'DELETE' });
}

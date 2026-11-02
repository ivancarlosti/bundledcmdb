/** Types mirroring the `/panel-api/v1` payloads (see lib/panel/*.php). */

export type Role = 'user' | 'manager' | 'admin' | 'superadmin';

export type Capabilities = {
  role: Role;
  rank: number;
  can_write: boolean;
  can_admin: boolean;
  can_superadmin: boolean;
};

export type LocaleOption = {
  code: string;
  native: string;
  label: string;
  dir: 'ltr' | 'rtl';
};

export type GridState = {
  order?: string[];
  visible?: string[];
  sort?: string;
  dir?: 'asc' | 'desc' | '';
} | null;

export type Session = {
  user: { email: string; company: string; role: Role };
  csrfToken: string;
  locale: string;
  dir: 'ltr' | 'rtl';
  theme: 'system' | 'light' | 'dark';
  perPage: number;
  gridState: GridState;
  locales: LocaleOption[];
  themes: string[];
  pageSizes: number[];
  maxPageSize: number;
  capabilities: Capabilities;
  features: { spa: boolean; api: boolean; attachments: boolean; devLogin: boolean };
  serverTime: string;
};

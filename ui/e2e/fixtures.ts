import type { Page, Route } from '@playwright/test';

/**
 * Panel API mocks for the e2e suite.
 *
 * The tests target the behaviour of the SPA (server-side sorting, URL state,
 * navigation), so the API is stubbed with payloads shaped exactly like
 * `lib/panel/*.php`. No database or Keycloak is required, which keeps the suite
 * runnable on a laptop and in CI.
 */

export const MESSAGES: Record<string, string> = {
  panel_title: 'CMDB',
  login_prompt: 'Please sign in using your company account.',
  sso_btn: 'Sign in with SSO',
  ui_devices: 'Devices',
  ui_loading: 'Loading…',
  ui_retry: 'Try again',
  ui_api_unavailable: 'Panel API unavailable',
  ui_theme: 'Theme',
  ui_rows_per_page: 'Rows per page',
  ui_showing_range: 'Showing {{from}}–{{to}} of {{total}}',
  ui_clear_filters: 'Clear filters',
  ui_trashed: 'Deleted only',
  ui_dev_login: 'Development sign-in',
  records: 'records',
  signed_in_as: 'Signed in as:',
  search_text: 'Search Text',
  search_any: 'Any field',
  source_filter: 'Source',
  all_sources: 'All sources',
  all: 'All',
  yes: 'Yes',
  no: 'No',
  export_btn: 'Export to Excel',
  no_assets: 'No assets found.',
  previous: 'Previous',
  next: 'Next',
  page: 'Page',
  of: 'of',
  logout: 'Logout',
  language: 'Language',
  back_to_list: 'Back to list',
  files_title: 'Attachments',
  no_files: 'No files',
  asset_not_found: 'Asset not found.',
  role_admin: 'Admin',
  theme_system: 'System',
  theme_light: 'Light',
  theme_dark: 'Dark',
  status_in_use: 'In Use',
  status_in_stock: 'In Stock',
  col_sn: 'Serial number',
  col_hostname: 'Hostname',
  col_status: 'Status',
  col_files: 'Attachments',
  col_updated_at: 'Updated at',
  col_company: 'Company',
  col_byod: 'BYOD',
  ui_new_device: 'New device',
  ui_edit_device: 'Edit device',
  ui_columns: 'Columns',
  ui_columns_hint: 'Choose the columns to show, and their order.',
  ui_move_up: 'Move {{name}} up',
  ui_move_down: 'Move {{name}} down',
  ui_reset_columns: 'Reset columns',
  ui_saved: 'Saved.',
  ui_stale_record: 'This device was changed by someone else. Reload before saving.',
  ui_reload: 'Reload',
  create_asset: 'Create asset',
  save_changes: 'Save Changes',
  cancel: 'Cancel',
  custom_fields: 'Custom fields',
  edit: 'Edit',
  delete: 'Delete',
  ui_bulk_actions: 'Bulk actions',
  ui_selected_count: '{{total}} selected',
  ui_select_all: 'Select all rows on this page',
  ui_select_row: 'Select {{name}}',
  ui_bulk_status: 'Set status',
  ui_bulk_byod: 'Set BYOD',
  ui_bulk_apply: 'Apply',
  ui_bulk_clear: 'Clear selection',
  ui_bulk_done: '{{done}} device(s) updated.',
  ui_bulk_partial: '{{done}} updated, {{skipped}} skipped.',
  ui_restore: 'Restore',
};

export const SESSION = {
  user: { email: 'qa@example.com', company: '', role: 'admin' },
  csrfToken: 'test-csrf-token',
  locale: 'en-US',
  dir: 'ltr',
  theme: 'system',
  perPage: 25,
  gridState: null,
  locales: [
    { code: 'en-US', native: 'English (US)', label: 'EN', dir: 'ltr' },
    { code: 'ar-SA', native: 'العربية', label: 'AR', dir: 'rtl' },
  ],
  themes: ['system', 'light', 'dark'],
  pageSizes: [10, 25, 50],
  maxPageSize: 200,
  capabilities: { role: 'admin', rank: 3, can_write: true, can_admin: true, can_superadmin: false },
  features: { spa: true, api: true, attachments: false, devLogin: false },
  serverTime: '2026-11-02T12:00:00Z',
};

export const META = {
  columns: [
    { key: 'SN', api_key: 'sn', label_key: 'col_sn', label: 'Serial Number', type: 'text', in_grid: true, sortable: true, editable: true, virtual: false },
    { key: 'Hostname', api_key: 'hostname', label_key: 'col_hostname', label: 'Hostname', type: 'text', in_grid: true, sortable: true, editable: true, virtual: false },
    { key: 'Status', api_key: 'status', label_key: 'col_status', label: 'Status', type: 'status', in_grid: true, sortable: true, editable: true, virtual: false },
    { key: 'updated_at', api_key: 'updated_at', label_key: 'col_updated_at', label: 'Updated At', type: 'datetime', in_grid: true, sortable: true, editable: false, virtual: false },
    { key: 'files', api_key: 'files_count', label_key: 'col_files', label: 'Attachments', type: 'int', in_grid: true, sortable: true, editable: false, virtual: true },
  ],
  statuses: [
    { value: 'In Use', label_key: 'status_in_use' },
    { value: 'In Stock', label_key: 'status_in_stock' },
  ],
  sources: [],
  fields: [],
  companies: [],
  locales: SESSION.locales,
  themes: SESSION.themes,
  pageSizes: SESSION.pageSizes,
  capabilities: SESSION.capabilities,
  defaultSort: { sort: 'updated_at', dir: 'desc' },
  virtualSorts: ['files'],
  writeColumns: { create: ['sn', 'hostname', 'status', 'source'], update: ['sn', 'hostname', 'status'] },
  limits: { requireSn: true, maxUploadBytes: 26214400 },
  schemaReady: true,
};

export const ROWS = [
  {
    id: 7,
    uuid: 'uuid-7',
    sn: 'SN-0007',
    os: 'Windows 11 Pro',
    os_version: '23H2',
    hostname: 'acme-laptop-07',
    mobile: false,
    manufacturer: 'Dell',
    last_seen: '2026-10-01T12:00:00Z',
    user_email: 'user@example.com',
    byod: false,
    status: 'In Use',
    warranty: '2027-01-15',
    asset: 'ACME-NB-007',
    purchase_date: '2025-01-15',
    cypher_id: null,
    cypher_key: null,
    notes: null,
    cpus: '1',
    hds: 'SSD 512GB',
    hds_types: null,
    hds_space_gb: null,
    network_adapters: null,
    mac_addresses: null,
    eset_components: null,
    primary_local_ip: null,
    primary_remote_ip: null,
    company: 'Acme',
    source: 'intune',
    source_ref: null,
    created_at: '2026-09-01T12:00:00Z',
    updated_at: '2026-10-01T12:00:00Z',
    created_by: 'seed',
    updated_by: 'seed',
    deleted_at: null,
    files_count: 2,
    custom: {},
  },
  {
    id: 8,
    uuid: 'uuid-8',
    sn: 'SN-0008',
    os: 'Ubuntu 24.04',
    os_version: '24.04',
    hostname: 'acme-desktop-08',
    mobile: false,
    manufacturer: 'Lenovo',
    last_seen: '2026-10-02T09:30:00Z',
    user_email: 'manager@example.com',
    byod: true,
    status: 'In Use',
    warranty: '2026-12-01',
    asset: 'ACME-DT-008',
    purchase_date: '2024-11-02',
    cypher_id: null,
    cypher_key: null,
    notes: null,
    cpus: '2',
    hds: 'NVMe 1TB',
    hds_types: null,
    hds_space_gb: null,
    network_adapters: null,
    mac_addresses: null,
    eset_components: null,
    primary_local_ip: null,
    primary_remote_ip: null,
    company: 'Acme',
    source: 'intune',
    source_ref: null,
    created_at: '2026-09-02T12:00:00Z',
    updated_at: '2026-10-02T09:30:00Z',
    created_by: 'seed',
    updated_by: 'seed',
    deleted_at: null,
    files_count: 0,
    custom: {},
  },
];

export type MockOptions = {
  /** Called with every list URL, so a test can assert the server-side query. */
  onDevices?: (url: string) => void;
  /** Called with the JSON body of every `POST /devices`, so a test can assert it. */
  onCreate?: (body: Record<string, unknown>) => void;
  /** Called with the JSON body of every `POST /devices/bulk`. */
  onBulk?: (body: Record<string, unknown>) => void;
  /** Called with the JSON body of every `PATCH /session/preferences`. */
  onPreferences?: (patch: Record<string, unknown>) => void;
  pages?: number;
  total?: number;
};

function json(route: Route, data: unknown, meta: Record<string, unknown> = {}) {
  return route.fulfill({ json: { ok: true, data, meta } });
}

export async function mockPanel(page: Page, options: MockOptions = {}): Promise<void> {
  const total = options.total ?? ROWS.length;
  const pages = options.pages ?? 1;
  // The stubbed session is mutable so preference writes (theme, locale, column
  // layout) behave like the real server: a reload sees what was saved.
  let sessionState: Record<string, unknown> = { ...SESSION };

  await page.route('**/panel-api/v1/session', (route) => json(route, sessionState));
  await page.route('**/panel-api/v1/session/preferences', (route) => {
    let patch: Record<string, unknown> = {};
    try {
      patch = (route.request().postDataJSON() ?? {}) as Record<string, unknown>;
    } catch {
      patch = {};
    }
    options.onPreferences?.(patch);
    sessionState = {
      ...sessionState,
      ...(patch.locale === undefined ? {} : { locale: patch.locale }),
      ...(patch.theme === undefined ? {} : { theme: patch.theme }),
      ...(patch.per_page === undefined ? {} : { perPage: patch.per_page }),
      ...(patch.grid_state === undefined ? {} : { gridState: patch.grid_state }),
    };
    return json(route, sessionState);
  });
  await page.route('**/panel-api/v1/i18n**', (route) => {
    const requested = new URL(route.request().url()).searchParams.get('locale') ?? 'en-US';
    return json(route, {
      locale: requested,
      dir: requested === 'ar-SA' ? 'rtl' : 'ltr',
      messages: MESSAGES,
    });
  });
  await page.route('**/panel-api/v1/meta', (route) => json(route, META));

  await page.route('**/panel-api/v1/devices**', (route) => {
    const request = route.request();
    const url = request.url();

    // Writes: answer like `POST /devices` does (201 + the created row).
    // `/devices/bulk` is matched by the dedicated route registered below.
    if (request.method() !== 'GET') {
      let body: Record<string, unknown> = {};
      try {
        body = (request.postDataJSON() ?? {}) as Record<string, unknown>;
      } catch {
        body = {};
      }
      options.onCreate?.(body);
      return route.fulfill({
        status: 201,
        json: { ok: true, data: { ...ROWS[0], ...body, id: 99, files: [] }, meta: { created: true } },
      });
    }

    if (url.includes('/devices/export')) {
      return route.fulfill({ status: 200, contentType: 'text/csv', body: 'SN;Hostname\n' });
    }
    options.onDevices?.(url);
    const pageNumber = Number(new URL(url).searchParams.get('page') ?? '1');
    return json(
      route,
      { rows: ROWS, total, page: pageNumber, per_page: 25, pages },
      { sort: 'SN', dir: 'asc' },
    );
  });

  // Registered last so it wins over the generic list route for /devices/{id}.
  await page.route('**/panel-api/v1/devices/**', (route) =>
    json(route, {
      ...ROWS[0],
      files: [
        {
          id: 1,
          name: 'invoice.pdf',
          path: 'acme/invoice.pdf',
          mime: 'application/pdf',
          size: 2048,
          uploaded_at: '2026-10-01T12:00:00Z',
        },
      ],
    }),
  );

  // ...and after that one so `POST /devices/bulk` is not read as `POST /devices/{id}`.
  await page.route('**/panel-api/v1/devices/bulk', (route) => {
    let body: Record<string, unknown> = {};
    try {
      body = (route.request().postDataJSON() ?? {}) as Record<string, unknown>;
    } catch {
      body = {};
    }
    const ids = Array.isArray(body.ids) ? (body.ids as number[]) : [];
    options.onBulk?.(body);
    return json(route, { op: body.op, updated: ids, skipped: [] }, { changed: ids.length, skipped: 0 });
  });
}

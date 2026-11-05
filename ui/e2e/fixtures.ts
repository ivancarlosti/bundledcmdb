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
  ui_history: 'History',
  ui_drag_column: 'Drag {{name}} to reorder',
  upload_title: 'Upload New File',
  upload_failed: 'Could not upload the file.',
  files_title: 'Attachments',
  ui_views: 'Views',
  ui_form_tabs: 'Device sections',
  ui_form_tab_identity: 'Identity',
  ui_form_tab_hardware: 'Hardware',
  ui_form_tab_assignment: 'Assignment',
  ui_form_tab_admin: 'Admin',
  ui_form_tab_more: 'More',
  ui_views_empty: 'No saved views yet. Filter the grid, then name it below.',
  ui_view_name: 'View name',
  ui_view_save: 'Save view',
  ui_view_delete: 'Delete {{name}}',
  ui_history_empty: 'No changes recorded yet.',
  ui_ev_create: 'Created',
  ui_ev_update: 'Updated',
  ui_ev_delete: 'Deleted',
  ui_ev_restore: 'Restored',
  ui_ev_purge: 'Deleted permanently',
  ui_ev_file_add: 'Attachment added',
  ui_ev_file_remove: 'Attachment removed',
  ui_ev_key_rotate: 'Key rotated',
  ui_ev_log_purge: 'Log purged',
  ui_ev_role_change: 'Role changed',
  ui_user_email_hint: 'Accounts of the selected company.',
  ui_user_email_other: 'Other…',
  logs_title: 'Audit log',
  logs_intro: 'Every change made through the panel or the API.',
  logs_total: '{{total}} event(s)',
  logs_empty: 'No events match these filters.',
  logs_retention: 'Keeping {{days}} days of history.',
  logs_retention_off: 'History is kept indefinitely.',
  logs_last_purge: 'Last purge: {{when}}.',
  logs_never_purged: 'The log has never been trimmed.',
  logs_col_time: 'When',
  logs_col_actor: 'Who',
  logs_col_action: 'Action',
  logs_col_entity: 'What',
  logs_col_company: 'Company',
  logs_col_details: 'Details',
  logs_filter_search: 'Search',
  logs_filter_action: 'Action',
  logs_filter_entity: 'Type',
  logs_filter_company: 'Company',
  logs_filter_from: 'From',
  logs_filter_to: 'To',
  logs_filter_clear: 'Clear filters',
  logs_purge_title: 'Purge history',
  logs_purge_hint: 'Deletes every event recorded before the selected day.',
  logs_purge_btn: 'Delete older events',
  logs_purge_preview: '{{count}} event(s) older than {{date}} will be deleted. Continue?',
  logs_purge_done: '{{count}} event(s) deleted.',
  logs_entity_api_key: 'API key',
  logs_entity_device: 'Device',
  logs_entity_field: 'Custom field',
  logs_entity_logs: 'Audit log',
  logs_entity_migration: 'Schema',
  logs_entity_setting: 'Setting',
  logs_entity_source: 'Source',
  logs_entity_system: 'System',
  logs_entity_user: 'User',
  set_tab_logs: 'Logs',
  set_group_logs: 'Audit log',
  set_logs_intro: 'How long the audit trail is kept.',
  setting_logs_retention_days: 'Audit log retention (days)',
  records: 'records',
  signed_in_as: 'Signed in as:',
  search_text: 'Search Text',
  search_any: 'Any field',
  source_filter: 'Source',
  all_sources: 'All sources',
  all: 'All',
  yes: 'Yes',
  no: 'No',
  export_btn: 'Export CSV',
  export_xlsx_btn: 'Export Excel',
  no_assets: 'No assets found.',
  previous: 'Previous',
  next: 'Next',
  page: 'Page',
  of: 'of',
  logout: 'Logout',
  language: 'Language',
  back_to_list: 'Back to list',
  no_files: 'No files',
  asset_not_found: 'Asset not found.',
  role_admin: 'Admin',
  theme_system: 'System',
  theme_light: 'Light',
  theme_dark: 'Dark',
  status_in_use: 'In Use',
  status_stock: 'In Stock',
  col_sn: 'Serial number',
  col_hostname: 'Hostname',
  col_status: 'Status',
  col_files: 'Attachments',
  col_updated_at: 'Updated at',
  col_company: 'Company',
  col_byod: 'BYOD',
  // Settings screens (phase 7): the catalogue stub only needs the strings the
  // specs assert on, not the whole `set_*` family.
  set_title: 'Settings',
  set_tab_general: 'General',
  set_tab_storage: 'Storage',
  set_tab_api: 'API',
  set_tab_sources: 'Sources',
  set_tab_fields: 'Custom fields',
  set_tab_maintenance: 'Maintenance',
  set_tab_users: 'Users',
  set_group_general: 'General',
  set_group_assets: 'Assets',
  set_group_api: 'API',
  set_group_storage: 'Attachment storage',
  set_group_s3: 'S3 connection',
  set_general_intro: 'Language, timezone and display defaults.',
  set_storage_intro: 'Attachment storage and the S3-compatible connection.',
  set_saved: 'Settings saved.',
  set_error: 'Some values could not be saved.',
  set_err_required: 'Required.',
  set_prefix_placeholders: 'Insert a placeholder:',
  set_reset_default: 'Reset to default',
  set_key_company: 'Company',
  set_user_all_companies: 'All companies',
  set_user_empty: 'No users yet.',
  set_user_no_company: 'No company',
  set_user_no_results: 'No users match the filters.',
  set_user_search: 'Search users',
  set_user_search_placeholder: 'Search by e-mail, company or role',
  set_user_summary: 'Showing {{shown}} of {{total}} users',
  setting_assets_require_sn: 'Enable serial number requirement',
  setting_s3_bucket: 'Bucket',
  setting_s3_path_style: 'Enable path-style URLs (MinIO / self-hosted)',
  setting_s3_prefix: 'Object key prefix',
  setting_s3_secret_key: 'Secret access key',
  setting_storage_driver: 'Attachment storage driver',
  role_user: 'User',
  role_manager: 'Manager',
  role_superadmin: 'Superadmin',
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
  delete_confirm: 'Delete this asset?',
  ui_delete_reason: 'Reason',
  ui_delete_reason_choose: 'Choose a reason…',
  ui_delete_reason_duplicated: 'Duplicated',
  ui_delete_reason_mistake: 'Created by mistake',
  ui_delete_reason_none: 'No reason',
  ui_delete_reason_required: 'Choose a reason before deleting.',
  ui_duplicate_of: 'Duplicate of',
  ui_duplicate_of_empty: 'No device matches “{{q}}”.',
  ui_duplicate_of_hint: 'Only live devices of this company are listed.',
  ui_duplicate_of_missing: 'Device #{{id}} (no longer available)',
  ui_duplicate_of_placeholder: 'Search serial number or hostname',
  ui_trashed_reason: 'Deleted because: {{reason}}',
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
  features: { api: true, attachments: false, devLogin: false },
  serverTime: '2026-11-02T12:00:00Z',
};

/** Audit trail stub: newest first, one field change each. */
export const EVENTS = [
  {
    id: 2,
    asset_id: 7,
    action: 'update',
    actor: 'admin@example.com',
    changes: { Status: ['In Use', 'In Stock'] },
    note: null,
    created_at: '2026-11-02T12:05:00Z',
  },
  {
    id: 1,
    asset_id: 7,
    action: 'create',
    actor: 'manager@example.com',
    changes: { SN: [null, 'SN-0007'] },
    note: null,
    created_at: '2026-11-02T12:00:00Z',
  },
];

/** Directory stub for the "user e-mail" dropdown (`GET /users`). */
export const COMPANY_USERS = [
  { email: 'admin@example.com', company: 'Acme', role: 'admin' },
  { email: 'manager@example.com', company: 'Acme', role: 'manager' },
];

/** Audit-log stub (`GET /logs`): a device write plus a settings change. */
export const LOG_ROWS = [
  {
    id: 12,
    asset_id: 7,
    company: 'Acme',
    entity: 'device',
    entity_id: '7',
    entity_label: 'SN-0007',
    action: 'update',
    actor: 'admin@example.com',
    changes: { Status: ['In Use', 'In Stock'] },
    note: null,
    created_at: '2026-11-02T12:05:00Z',
  },
  {
    id: 11,
    asset_id: null,
    company: null,
    entity: 'setting',
    entity_id: 'general.default_theme',
    entity_label: 'Default theme',
    action: 'update',
    actor: 'qa@example.com',
    changes: { 'general.default_theme': ['system', 'dark'] },
    note: null,
    created_at: '2026-11-02T12:00:00Z',
  },
];

export const LOGS_META = {
  filters: {
    q: '',
    action: '',
    entity: '',
    actor: '',
    from: null,
    to: null,
    company: null,
  },
  scope: 'all',
  filter_options: {
    actions: [
      'create',
      'update',
      'delete',
      'restore',
      'purge',
      'file_add',
      'file_remove',
      'role_change',
      'key_rotate',
      'log_purge',
    ],
    entities: ['device', 'setting', 'source', 'field', 'api_key', 'user', 'migration', 'logs'],
    companies: ['Acme', 'Globex'],
  },
  retention_days: 180,
  last_purge: null,
};

export const META = {
  columns: [
    {
      key: 'SN',
      api_key: 'sn',
      label_key: 'col_sn',
      label: 'Serial Number',
      type: 'text',
      in_grid: true,
      sortable: true,
      editable: true,
      virtual: false,
    },
    {
      key: 'Hostname',
      api_key: 'hostname',
      label_key: 'col_hostname',
      label: 'Hostname',
      type: 'text',
      in_grid: true,
      sortable: true,
      editable: true,
      virtual: false,
    },
    {
      key: 'Status',
      api_key: 'status',
      label_key: 'col_status',
      label: 'Status',
      type: 'status',
      in_grid: true,
      sortable: true,
      editable: true,
      virtual: false,
    },
    {
      key: 'updated_at',
      api_key: 'updated_at',
      label_key: 'col_updated_at',
      label: 'Updated At',
      type: 'datetime',
      in_grid: true,
      sortable: true,
      editable: false,
      virtual: false,
    },
    {
      key: 'files',
      api_key: 'files_count',
      label_key: 'col_files',
      label: 'Attachments',
      type: 'int',
      in_grid: true,
      sortable: true,
      editable: false,
      virtual: true,
    },
    {
      // Not a grid column: it exists so the form renders the e-mail dropdown.
      key: 'UserEmail',
      api_key: 'user_email',
      label_key: 'col_user_email',
      label: 'E-mail',
      type: 'email',
      in_grid: false,
      sortable: false,
      editable: true,
      virtual: false,
    },
  ],
  statuses: [
    { value: 'In Use', label_key: 'status_in_use' },
    { value: 'In Stock', label_key: 'status_stock' },
  ],
  deleteReasons: [
    { value: 'duplicated', label_key: 'ui_delete_reason_duplicated' },
    { value: 'created_by_mistake', label_key: 'ui_delete_reason_mistake' },
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
  // Unlocked, so the e2e suite keeps exercising the per-user layout; the lock
  // itself is covered by the vitest suite and `bin/test-panel-api.php`.
  gridLayout: {
    locked: false,
    enabled: ['SN', 'Hostname', 'Status', 'updated_at', 'files'],
    order: ['SN', 'Hostname', 'Status', 'updated_at', 'files', 'UserEmail'],
  },
  writeColumns: {
    create: ['sn', 'hostname', 'status', 'source', 'user_email'],
    update: ['sn', 'hostname', 'status', 'user_email'],
  },
  limits: { requireSn: true, maxUploadBytes: 104857600 },
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
    logged_username: 'jdoe',
    device_model: 'Dell Latitude 5440',
    byod: false,
    status: 'In Use',
    warranty: '2027-01-15',
    asset: 'ACME-NB-007',
    purchase_date: '2025-01-15',
    cypher_id: null,
    cypher_key: null,
    notes: null,
    cpus: '1',
    gpus: 'Intel Iris Xe',
    ram_gb: '16',
    hds: 'SSD 512GB',
    hds_types: 'SSD',
    hds_space_gb: '476.94',
    physical_disks: [
      {
        number: 0,
        friendly_name: 'WD Green M.2 2280 480GB',
        media_type: 'SSD',
        bus_type: 'NVMe',
        size_gb: 476.94,
        free_gb: 210.5,
        used_gb: 266.44,
        volumes: [{ drive: 'C:', label: 'Windows', total_gb: 476.94, free_gb: 210.5, used_gb: 266.44 }],
      },
    ],
    disks_total_gb: 476.94,
    disks_free_gb: 210.5,
    disks_used_gb: 266.44,
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
    logged_username: null,
    device_model: 'Lenovo ThinkStation P360',
    byod: true,
    status: 'In Use',
    warranty: '2026-12-01',
    asset: 'ACME-DT-008',
    purchase_date: '2024-11-02',
    cypher_id: null,
    cypher_key: null,
    notes: null,
    cpus: '2',
    gpus: null,
    ram_gb: null,
    hds: 'NVMe 1TB',
    hds_types: null,
    hds_space_gb: null,
    physical_disks: [],
    disks_total_gb: null,
    disks_free_gb: null,
    disks_used_gb: null,
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
  /** Called with the full URL of every `DELETE /devices/{id}` (query string included). */
  onDelete?: (url: string) => void;
  /** Called with the JSON body of every `PATCH /session/preferences`. */
  onPreferences?: (patch: Record<string, unknown>) => void;
  /** Answer `POST /devices/{id}/files/presign` with this (default: server mode). */
  presign?: Record<string, unknown>;
  /** Called with the JSON body that registers a direct upload. */
  onFileUpload?: (body: Record<string, unknown>) => void;
  /**
   * Abort the browser's PUT to the fake bucket, the way a DNS failure or a
   * missing CORS rule looks from `XMLHttpRequest` (it fires `error`, not `load`).
   */
  bucketUnreachable?: boolean;
  /**
   * Refuse the registration of a direct upload (`422`), so the SPA has to fall
   * back to the multipart upload. Multipart bodies still succeed.
   */
  registerDirectFailed?: boolean;
  /**
   * Refuse the multipart upload too (`422`, the way an oversized body is
   * reported), so a test can assert what the toast says when nothing worked.
   */
  multipartFailed?: boolean;
  /** Merged into the stubbed `GET /devices/{id}` row, e.g. a long `notes`. */
  detail?: Record<string, unknown>;
  /** Called with the entry the SPA saved as a grid view (`POST /views`). */
  onSaveView?: (view: { id: number; name: string; state: Record<string, unknown> }) => void;
  /** Merged into the stubbed session (role, capabilities, locale, …). */
  session?: Record<string, unknown>;
  pages?: number;
  total?: number;
};

function json(route: Route, data: unknown, meta: Record<string, unknown> = {}) {
  return route.fulfill({ json: { ok: true, data, meta } });
}

/**
 * Unauthenticated visitor.
 *
 * The shell has to render from the session probe alone, so `/session` is
 * answered with a 401 and nothing else is allowed to reach the network: on CI
 * there is no PHP backend behind the preview proxy, and an unstubbed call would
 * come back as a 502 that Chromium reports as a console error.
 */
export async function mockAnonymous(page: Page, status = 401): Promise<void> {
  await page.route('**/panel-api/v1/session', (route) =>
    route.fulfill({
      status,
      json: { ok: false, error: { code: 'unauthorized', message: 'Authentication required.' } },
    }),
  );
}

export async function mockPanel(page: Page, options: MockOptions = {}): Promise<void> {
  const total = options.total ?? ROWS.length;
  const pages = options.pages ?? 1;
  // The stubbed session is mutable so preference writes (theme, locale, column
  // layout) behave like the real server: a reload sees what was saved.
  let sessionState: Record<string, unknown> = { ...SESSION, ...(options.session ?? {}) };

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
    // Every locale gets the same strings (the assertions are written in English),
    // except the brand: it carries the locale so a test can prove that switching
    // back to `en-US` actually re-applied the catalogue instead of leaving the
    // previous language on screen.
    const brand = requested === 'en-US' ? MESSAGES.panel_title : `${MESSAGES.panel_title} ${requested}`;
    return json(route, {
      locale: requested,
      dir: requested === 'ar-SA' ? 'rtl' : 'ltr',
      messages: { ...MESSAGES, panel_title: brand },
    });
  });
  await page.route('**/panel-api/v1/meta', (route) => json(route, META));

  // Directory for the device form's "user e-mail" dropdown.
  await page.route('**/panel-api/v1/users**', (route) =>
    json(route, { rows: COMPANY_USERS, total: COMPANY_USERS.length }, { company: 'Acme' }),
  );

  // Audit log. `/logs/purge` is registered last so it wins over the list route.
  await page.route('**/panel-api/v1/logs**', (route) =>
    json(route, { rows: LOG_ROWS, total: LOG_ROWS.length, page: 1, per_page: 50, pages: 1 }, LOGS_META),
  );
  await page.route('**/panel-api/v1/logs/purge', (route) =>
    json(route, {
      deleted: 2,
      before: '2026-01-01',
      cutoff: '2026-01-01 00:00:00',
      dry_run: false,
      last_purge: null,
      retention_days: 180,
    }),
  );

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
    return json(route, { rows: ROWS, total, page: pageNumber, per_page: 25, pages }, { sort: 'SN', dir: 'asc' });
  });

  // Registered last so it wins over the generic list route for /devices/{id}.
  // A delete lands here too (it is `DELETE /devices/{id}`): the reason travels as
  // a query parameter, so the test reads it from the URL instead of a body.
  await page.route('**/panel-api/v1/devices/**', (route) => {
    if (route.request().method() === 'DELETE') {
      const url = route.request().url();
      options.onDelete?.(url);
      const parsed = new URL(url);
      const id = Number(parsed.pathname.split('/devices/')[1] ?? 0);
      return json(route, {
        id,
        deleted: true,
        hard: parsed.searchParams.get('hard') === '1',
        deleted_reason: parsed.searchParams.get('reason'),
        duplicate_of_id: parsed.searchParams.has('duplicate_of')
          ? Number(parsed.searchParams.get('duplicate_of'))
          : null,
      });
    }

    return json(route, {
      ...ROWS[0],
      ...(options.detail ?? {}),
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
    });
  });

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

  // Saved views: an in-memory list per test, so save/apply/delete are observable.
  const savedViews: { id: number; name: string; state: Record<string, unknown> }[] = [];
  await page.route('**/panel-api/v1/views**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (request.method() === 'GET') {
      return json(route, { rows: savedViews, total: savedViews.length });
    }
    if (request.method() === 'POST') {
      const body = (request.postDataJSON() ?? {}) as { name?: string; state?: Record<string, unknown> };
      const entry = { id: savedViews.length + 1, name: body.name ?? '', state: body.state ?? {} };
      savedViews.push(entry);
      options.onSaveView?.(entry);
      return route.fulfill({ status: 201, json: { ok: true, data: entry } });
    }
    if (request.method() === 'DELETE') {
      const id = Number(url.pathname.split('/').pop());
      const index = savedViews.findIndex((view) => view.id === id);
      if (index >= 0) {
        savedViews.splice(index, 1);
      }
      return json(route, { id, deleted: true });
    }
    return json(route, { ok: true, data: {} });
  });

  // Attachments: presign + register + the (stubbed) bucket PUT. Registered after
  // `/devices/**` so `POST /devices/{id}/files` is not answered as a device write.
  await page.route('**/panel-api/v1/devices/*/files/presign', (route) =>
    json(route, options.presign ?? { mode: 'server' }),
  );
  await page.route('**/panel-api/v1/devices/*/files', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') {
      return json(route, { rows: [], total: 0 });
    }
    let body: Record<string, unknown> = {};
    try {
      body = (request.postDataJSON() ?? {}) as Record<string, unknown>;
    } catch {
      body = {};
    }
    options.onFileUpload?.(body);
    // A JSON body is the registration of a direct upload; a multipart body is the
    // server-side upload, which the bucket PUT failing must still be able to reach.
    if (options.registerDirectFailed === true && body.key !== undefined) {
      return route.fulfill({
        status: 422,
        json: {
          ok: false,
          error: {
            code: 'validation_failed',
            message: 'The uploaded object was not found.',
            errors: { key: 'missing' },
          },
        },
      });
    }
    if (options.multipartFailed === true && body.key === undefined) {
      return route.fulfill({
        status: 422,
        json: {
          ok: false,
          error: { code: 'validation_failed', message: 'The file is too large.', errors: { file: 'too_large' } },
        },
      });
    }
    return route.fulfill({
      status: 201,
      json: {
        ok: true,
        data: {
          id: 5,
          name: (body.name as string) ?? 'note.txt',
          path: (body.key as string) ?? 'assets/7/x_note.txt',
          mime: 'text/plain',
          size: (body.size as number) ?? 16,
          uploaded_at: '2026-11-02T12:00:00Z',
        },
        meta: { created: true },
      },
    });
  });
  // The bucket the browser PUTs to; only the presigned path reaches this.
  // `abort` (not an error status) is what a CORS/DNS failure looks like.
  await page.route('**/fake-upload**', (route) =>
    options.bucketUnreachable === true ? route.abort('failed') : route.fulfill({ status: 200, body: '' }),
  );

  // Registered last: Playwright gives the newest route priority, and
  // `/devices/{id}/events` would otherwise be answered by the `/devices/**`
  // detail stub.
  await page.route('**/panel-api/v1/devices/*/events*', (route) => json(route, { rows: EVENTS, total: EVENTS.length }));
}

// --- Settings -------------------------------------------------------------

type SettingStub = {
  key: string;
  group: string;
  label: string;
  label_key: string;
  type: string;
  options: { value: string; label: string }[];
  min: number | null;
  max: number | null;
  source: 'db' | 'env' | 'default';
  secret: boolean;
  configured: boolean | null;
  value: string;
  /** Built-in default; `null` for a secret. Feeds "Reset to default". */
  default: string | null;
  /** `{{token}}` catalogue; only `s3.prefix` has one. */
  placeholders: Record<string, string>;
};

function settingStub(key: string, type: string, value: string, extra: Partial<SettingStub> = {}): SettingStub {
  return {
    key,
    group: key.split('.')[0] as string,
    label: key,
    label_key: `setting_${key.replace(/\./g, '_')}`,
    type,
    options: [],
    min: null,
    max: null,
    source: 'db',
    secret: false,
    configured: null,
    value,
    default: '',
    placeholders: {},
    ...extra,
  };
}

export const SETTINGS_SOURCES = [
  { slug: 'manual', label: 'Manual', color: '#64748b', is_system: true, is_default: true, usage: 12 },
  { slug: 'intune', label: 'Intune', color: '#4f46e5', is_system: false, is_default: false, usage: 0 },
];

export const SETTINGS_PAYLOAD = {
  groups: [
    {
      id: 'general',
      label_key: 'set_group_general',
      settings: [
        settingStub('general.default_locale', 'locale', 'en-US'),
        settingStub('general.per_page', 'int', '25', { min: 5, max: 500 }),
      ],
    },
    {
      id: 'assets',
      label_key: 'set_group_assets',
      settings: [
        settingStub('assets.default_source', 'source', 'manual'),
        settingStub('assets.require_sn', 'bool', '1'),
      ],
    },
    {
      id: 'api',
      label_key: 'set_group_api',
      settings: [settingStub('api.enabled', 'bool', '1')],
    },
    {
      id: 'storage',
      label_key: 'set_group_storage',
      settings: [settingStub('storage.driver', 'text', 's3')],
    },
    {
      id: 's3',
      label_key: 'set_group_s3',
      settings: [
        settingStub('s3.bucket', 'text', 'cmdb'),
        // The one template setting: the tokens travel with the entry, so the
        // panel can offer chips instead of only documenting them.
        settingStub('s3.prefix', 'text', 'uploads', {
          default: '{{company}}-{{asset}}-',
          placeholders: { company: 'Company', asset: 'Asset tag', id: 'Asset id' },
        }),
        settingStub('s3.path_style', 'bool', '1'),
        // A stored secret: the API sends an empty value and `configured: true`,
        // which is what "leave blank to keep" is built on.
        settingStub('s3.secret_key', 'secret', '', { secret: true, configured: true }),
      ],
    },
  ],
  sources: SETTINGS_SOURCES,
  locales: [
    { code: 'en-US', native: 'English (US)', label: 'EN', dir: 'ltr' },
    { code: 'ar-SA', native: 'العربية', label: 'AR', dir: 'rtl' },
  ],
  timezones: ['UTC', 'America/Sao_Paulo'],
  themes: ['system', 'light', 'dark'],
};

export type SettingsMockOptions = {
  /** Called with the JSON body of every `PATCH /settings`. */
  onSave?: (body: Record<string, unknown>) => void;
  /** Answered as `422 validation_failed` with these per-key codes. */
  invalid?: Record<string, string>;
};

/**
 * Settings endpoints, shaped like `lib/panel/settings.php`.
 *
 * `PATCH /settings` echoes the payload the way the handler does, so the form
 * re-seeds itself from the server's answer instead of from local state. A 422
 * carries `errors` keyed by setting key, which is what the inline messages read.
 */
export async function mockSettings(page: Page, options: SettingsMockOptions = {}): Promise<void> {
  await page.route('**/panel-api/v1/settings', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ json: { ok: true, data: SETTINGS_PAYLOAD } });
      return;
    }

    let body: Record<string, unknown> = {};
    try {
      body = (route.request().postDataJSON() ?? {}) as Record<string, unknown>;
    } catch {
      body = {};
    }
    options.onSave?.(body);

    if (options.invalid !== undefined) {
      await route.fulfill({
        status: 422,
        json: {
          ok: false,
          error: { code: 'validation_failed', message: 'Some settings were rejected.', errors: options.invalid },
        },
      });
      return;
    }

    const values = (body.values ?? {}) as Record<string, unknown>;
    const reset = (body.reset ?? []) as string[];
    await route.fulfill({
      json: { ok: true, data: SETTINGS_PAYLOAD, meta: { saved: [...Object.keys(values), ...reset] } },
    });
  });

  await page.route('**/panel-api/v1/settings/sources', (route) =>
    route.fulfill({ json: { ok: true, data: { sources: SETTINGS_SOURCES } } }),
  );
  await page.route('**/panel-api/v1/settings/fields', (route) =>
    route.fulfill({
      json: {
        ok: true,
        data: {
          fields: [],
          types: [{ value: 'text', label: 'Text', input: 'text' }],
          roles: ['user', 'manager', 'admin', 'superadmin'],
        },
      },
    }),
  );
  await page.route('**/panel-api/v1/settings/api-keys**', (route) =>
    route.fulfill({ json: { ok: true, data: { keys: [], sources: SETTINGS_SOURCES } } }),
  );
  await page.route('**/panel-api/v1/settings/users', (route) =>
    route.fulfill({
      json: {
        ok: true,
        data: {
          users: [
            { email: 'qa@example.com', company: '', role: 'superadmin', is_self: true },
            { email: 'ops@example.com', company: 'Acme', role: 'manager', is_self: false },
          ],
          roles: ['user', 'manager', 'admin', 'superadmin'],
        },
      },
    }),
  );
  await page.route('**/panel-api/v1/settings/maintenance', (route) =>
    route.fulfill({
      json: {
        ok: true,
        data: {
          migrations: { ready: true, ledger: true, applied: ['0001_settings'], pending: [] },
          diagnostics: {
            app_version: '10.6.1',
            app_env: 'development',
            php_version: '8.4.0',
            database: 'cmdb',
            db_version: '8.0.36',
            timezone: 'UTC',
            now: '2026-11-03 00:00:00',
            encryption: true,
            counts: { api_keys: 0, sources: 2, fields: 0 },
          },
        },
      },
    }),
  );
}

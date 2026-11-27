-- BundledCMDB schema additions (for installations where the application user
-- cannot run ALTER TABLE / CREATE TABLE).
--
-- Preferred path: `php bin/migrate.php` (idempotent, records versions).
-- Use this file only when a DBA must apply the DDL manually, ONCE.
--
-- Requires the legacy tables `assets`, `users`, `device_files` to exist.
-- If assets.Id is BIGINT, change asset_custom_values.asset_id accordingly.
--
-- Because it records itself as applied, anything missing here is missing for
-- good (bin/migrate.php will not come back for it). `php bin/check-schema.php`
-- compares this file with lib/migrate.php and fails on drift.

CREATE TABLE IF NOT EXISTS app_settings (
    skey VARCHAR(64) NOT NULL,
    svalue LONGTEXT NULL,
    is_secret TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_sources (
    id INT NOT NULL AUTO_INCREMENT,
    slug VARCHAR(32) NOT NULL,
    label VARCHAR(64) NOT NULL,
    color VARCHAR(16) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO asset_sources (slug, label, color, is_system, is_default) VALUES
    ('api', 'API', '#2b6cb0', 1, 0),
    ('manual', 'Manual', '#6b46c1', 1, 1);

CREATE TABLE IF NOT EXISTS api_keys (
    id INT NOT NULL AUTO_INCREMENT,
    label VARCHAR(64) NOT NULL,
    prefix VARCHAR(16) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    company VARCHAR(191) NULL,
    source_slug VARCHAR(32) NULL,
    allowed_sources VARCHAR(255) NULL,
    ip_allowlist TEXT NULL,
    can_read TINYINT(1) NOT NULL DEFAULT 1,
    can_write TINYINT(1) NOT NULL DEFAULT 1,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    can_users TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(191) NULL,
    rotated_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_key_hash (key_hash),
    KEY idx_prefix (prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_capabilities (
    role VARCHAR(32) NOT NULL,
    capability VARCHAR(64) NOT NULL,
    is_allowed TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (role, capability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_field_permissions (
    role VARCHAR(32) NOT NULL,
    field_key VARCHAR(64) NOT NULL,
    can_edit TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (role, field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0023: the company (tenant) registry. A name is a registry row, not a parent
-- record: `users`/`assets`/`api_keys` keep their free-form `company` string and
-- there are no foreign keys. The backfill picks up the names already in use
-- (`users` first, so its spelling wins a case-only collision).
CREATE TABLE IF NOT EXISTS companies (
    name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(191) NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO companies (name)
SELECT DISTINCT company FROM users WHERE company IS NOT NULL AND company <> '';
INSERT IGNORE INTO companies (name)
SELECT DISTINCT company FROM assets WHERE company IS NOT NULL AND company <> '';

CREATE TABLE IF NOT EXISTS asset_field_defs (
    id INT NOT NULL AUTO_INCREMENT,
    field_key VARCHAR(64) NOT NULL,
    label VARCHAR(128) NOT NULL,
    label_i18n TEXT NULL,
    data_type VARCHAR(16) NOT NULL DEFAULT 'text',
    options_json TEXT NULL,
    default_value VARCHAR(255) NULL,
    validation_json TEXT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    is_searchable TINYINT(1) NOT NULL DEFAULT 1,
    show_in_grid TINYINT(1) NOT NULL DEFAULT 0,
    editable_roles VARCHAR(64) NOT NULL DEFAULT 'admin,superadmin',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NULL,
    updated_at DATETIME NULL,
    created_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_field_key (field_key),
    KEY idx_field_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_custom_values (
    asset_id INT NOT NULL,
    field_id INT NOT NULL,
    value LONGTEXT NULL,
    updated_at DATETIME NULL,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (asset_id, field_id),
    KEY idx_field_value (field_id, value(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(191) NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-user panel preferences (locale, theme, page size, grid layout).
CREATE TABLE IF NOT EXISTS user_preferences (
    email VARCHAR(191) NOT NULL,
    locale VARCHAR(16) NULL,
    theme VARCHAR(16) NOT NULL DEFAULT 'system',
    per_page SMALLINT NOT NULL DEFAULT 25,
    grid_state LONGTEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail. asset_id is deliberately not a foreign key: the history has to
-- outlive a hard delete. `changes` holds {field: [old, new]} as JSON.
-- `entity`/`entity_id`/`entity_label` describe what was touched (a device, a
-- setting, an API key, …) and `company` scopes the Logs screen without a join.
CREATE TABLE IF NOT EXISTS asset_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT NULL,
    company VARCHAR(191) NULL,
    entity VARCHAR(32) NOT NULL DEFAULT 'device',
    entity_id VARCHAR(64) NULL,
    entity_label VARCHAR(191) NULL,
    action VARCHAR(32) NOT NULL,
    actor VARCHAR(191) NOT NULL,
    changes LONGTEXT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_asset_events_asset (asset_id, id),
    KEY idx_asset_events_created (created_at),
    KEY idx_asset_events_actor (actor),
    KEY idx_asset_events_company (company, id),
    KEY idx_asset_events_entity (entity, id),
    KEY idx_asset_events_action (action, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named grid presets; (email, name) is unique so "save as …" with an existing
-- name overwrites that view instead of piling up duplicates.
CREATE TABLE IF NOT EXISTS saved_views (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    name VARCHAR(64) NOT NULL,
    state LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_saved_view (email, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provenance columns (run once; each statement fails if the column exists,
-- which is why bin/migrate.php is the recommended path).
ALTER TABLE assets ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'manual';
ALTER TABLE assets ADD COLUMN source_ref VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN created_at DATETIME NULL;
ALTER TABLE assets ADD COLUMN updated_at DATETIME NULL;
ALTER TABLE assets ADD COLUMN created_by VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN updated_by VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN deleted_at DATETIME NULL;

UPDATE assets SET source = 'manual' WHERE source IS NULL OR source = '';

ALTER TABLE assets ADD INDEX idx_assets_company_source (company, source);
ALTER TABLE assets ADD INDEX idx_assets_uuid (UUID);
ALTER TABLE assets ADD INDEX idx_assets_sn (SN);
ALTER TABLE assets ADD INDEX idx_assets_updated (updated_at);
ALTER TABLE assets ADD INDEX idx_assets_deleted (deleted_at);
-- Sorts on these two cannot use the composite index above; measured by
-- bin/bench-devices.php at 100k rows (179 ms → 0.3 ms).
ALTER TABLE assets ADD INDEX idx_assets_source (source);
ALTER TABLE assets ADD INDEX idx_assets_status (Status);

-- Record every version in lib/migrate.php::migrations() so bin/migrate.php does
-- not try to re-apply them. `php bin/check-schema.php` fails when this file and
-- the migrations drift apart (a table added to one but not the other).

-- 0013 retired the editable local-storage path: the directory is fixed at
-- `var/storage`, which the Docker stacks bind-mount.
DELETE FROM app_settings WHERE skey = 'storage.local_path';

-- 0014 records why a device was deleted (`duplicated` / `created_by_mistake`)
-- and, for a duplicate, the asset that was kept. INT matches the common
-- `assets.Id` type; bin/migrate.php uses the exact type of the column.
ALTER TABLE assets ADD COLUMN deleted_reason VARCHAR(32) NULL;
ALTER TABLE assets ADD COLUMN duplicate_of_id INT NULL;
ALTER TABLE assets ADD INDEX idx_assets_deleted_reason (deleted_reason);

-- 0015 adds the persisted role matrix and retires the built-in ESET source.
-- A fresh schema never seeds ESET; existing installs only lose it when no asset
-- or API key still references the slug.
ALTER TABLE assets ALTER COLUMN source SET DEFAULT 'manual';
UPDATE app_settings SET svalue = 'manual' WHERE skey = 'assets.default_source' AND svalue = 'eset';
UPDATE asset_sources SET is_default = 0 WHERE slug = 'eset' AND is_default = 1;
UPDATE asset_sources SET is_default = 1 WHERE slug = 'manual' AND NOT EXISTS (
    SELECT 1 FROM (SELECT id FROM asset_sources WHERE is_default = 1) AS defaults
);
DELETE FROM asset_sources
 WHERE slug = 'eset'
   AND NOT EXISTS (SELECT 1 FROM assets WHERE source = 'eset')
   AND NOT EXISTS (SELECT 1 FROM api_keys WHERE source_slug = 'eset' OR FIND_IN_SET('eset', allowed_sources));
UPDATE asset_sources SET is_system = 0 WHERE slug = 'eset';

-- 0018 stores the inventory agent's own copy of the identity/hardware facts in
-- a separate `Agent*` block, so an agent report never overwrites a
-- hand-maintained `Hostname`/`OS`/`Manufacturer`. The block is declared once in
-- `asset_agent_columns()` (lib/assets.php).
ALTER TABLE assets ADD COLUMN AgentHostname VARCHAR(255) NULL;
ALTER TABLE assets ADD COLUMN AgentOS VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentOSBuild VARCHAR(64) NULL;
ALTER TABLE assets ADD COLUMN AgentBIOS VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentSecureBoot VARCHAR(16) NULL;
ALTER TABLE assets ADD COLUMN AgentManufacturer VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentModel VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentCPU VARCHAR(255) NULL;
ALTER TABLE assets ADD COLUMN AgentGPU VARCHAR(255) NULL;
ALTER TABLE assets ADD COLUMN AgentRAMGB VARCHAR(255) NULL;
ALTER TABLE assets ADD COLUMN AgentIP VARCHAR(64) NULL;
ALTER TABLE assets ADD COLUMN AgentLoggedUser VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentAllLoggedUsers TEXT NULL;
ALTER TABLE assets ADD COLUMN AgentExecutionUser VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN AgentAntivirus VARCHAR(255) NULL;

-- 0019 retires the six columns the inventory agent now owns. `GPUs`, `RAMGB` and
-- `DeviceModel` duplicated the `Agent*` block, and the three `Disks*GB` roll-ups
-- were only a sorting shortcut over `PhysicalDisks` (the grid, the export and the
-- API read the JSON). Values move into the agent block first — an existing report
-- wins — and then the columns are dropped. Destructive on purpose: take a dump
-- before upgrading a DBA-managed database.
UPDATE assets SET AgentModel = DeviceModel
 WHERE (AgentModel IS NULL OR AgentModel = '') AND DeviceModel IS NOT NULL AND DeviceModel <> '';
UPDATE assets SET AgentGPU = GPUs
 WHERE (AgentGPU IS NULL OR AgentGPU = '') AND GPUs IS NOT NULL AND GPUs <> '';
UPDATE assets SET AgentRAMGB = RAMGB
 WHERE (AgentRAMGB IS NULL OR AgentRAMGB = '') AND RAMGB IS NOT NULL AND RAMGB <> '';

ALTER TABLE assets DROP COLUMN DeviceModel;
ALTER TABLE assets DROP COLUMN GPUs;
ALTER TABLE assets DROP COLUMN RAMGB;
ALTER TABLE assets DROP COLUMN DisksTotalGB;
ALTER TABLE assets DROP COLUMN DisksFreeGB;
ALTER TABLE assets DROP COLUMN DisksUsedGB;

-- 0020 retires `LoggedUsername`. The inventory agent reports the logged-in user
-- in `AgentLoggedUser`, so the hand-typed copy was a second writable path for the
-- same fact. There is no backfill on purpose: the agent refreshes its column on
-- the next report, and `user_email` stays the manager-editable "who holds it"
-- field. Destructive: take a dump before upgrading a DBA-managed database.
ALTER TABLE assets DROP COLUMN LoggedUsername;

-- 0021 stores `Mobile`/`BYOD` as the `VARCHAR(16)` this file has always
-- declared. Databases created from an older release kept them as `tinyint(1)`,
-- and `asset_bool_normalize()` writes 'true'/'false', which a strict-mode server
-- rejects with SQLSTATE 22007 (1366) — the panel showed that as "the database is
-- unreachable" and every device save failed. Widening first keeps `1`/`0` as
-- `'1'`/`'0'`, and the CASE then collapses every spelling to 'true'/'false'.
-- Both steps are idempotent, and `NULL` stays `NULL` in each of them.
ALTER TABLE assets MODIFY COLUMN Mobile VARCHAR(16) NULL;
ALTER TABLE assets MODIFY COLUMN BYOD VARCHAR(16) NULL;
UPDATE assets SET Mobile = CASE
    WHEN LOWER(TRIM(Mobile)) IN ('1', 'true', 'yes', 'on') THEN 'true'
    WHEN LOWER(TRIM(Mobile)) IN ('0', 'false', 'no', 'off') THEN 'false'
    ELSE Mobile END;
UPDATE assets SET BYOD = CASE
    WHEN LOWER(TRIM(BYOD)) IN ('1', 'true', 'yes', 'on') THEN 'true'
    WHEN LOWER(TRIM(BYOD)) IN ('0', 'false', 'no', 'off') THEN 'false'
    ELSE BYOD END;

-- 0022 reserved `/settings` for superadmin: the capability catalogue flags every
-- `settings.*` key and `permissions.manage`, so older delegable grants are gone.
DELETE FROM role_capabilities
 WHERE role <> 'superadmin'
   AND (capability LIKE 'settings.%' OR capability = 'permissions.manage');

-- 0023 retires `settings.users` in favour of the delegable `users.manage`. The
-- table and its backfill live next to `role_field_permissions` above.
DELETE FROM role_capabilities WHERE capability = 'settings.users';

-- 0024 adds the experimental "Collect data" downloads. Only the SHA-256 of the
-- token is stored; the raw value lives in the downloaded script only. The
-- `collector` source is what those writes are stamped with.
CREATE TABLE IF NOT EXISTS collector_tokens (
    id INT NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    company VARCHAR(191) NULL,
    role VARCHAR(32) NOT NULL,
    file_name VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    uses INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collect_token (token_hash),
    KEY idx_collect_email (email, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO asset_sources (slug, label, color, is_system, is_default) VALUES
    ('collector', 'Collector', '#b7791f', 1, 0);

-- 0025 adds the Assets and Systems catalogues (tabs after Devices). `assets`
-- already means *device*, so both live here, told apart by `kind`. An item is
-- assigned to any number of people through `inventory_item_users`; the
-- `common_usage` flag marks an item the whole company shares, and the write path
-- then keeps the join table empty for that row.
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL,
    name VARCHAR(191) NOT NULL,
    brand VARCHAR(191) NULL,
    category VARCHAR(64) NULL,
    model VARCHAR(191) NULL,
    version VARCHAR(64) NULL,
    serial VARCHAR(191) NULL,
    location VARCHAR(191) NULL,
    status VARCHAR(64) NOT NULL DEFAULT 'In Use',
    purchase_date DATE NULL,
    warranty_end DATE NULL,
    license_expiry DATE NULL,
    common_usage TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    company VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    created_by VARCHAR(191) NULL,
    updated_at DATETIME NOT NULL,
    updated_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    KEY idx_inventory_kind_company (kind, company),
    KEY idx_inventory_kind_status (kind, status),
    KEY idx_inventory_kind_name (kind, name),
    KEY idx_inventory_kind_updated (kind, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_item_users (
    item_id INT NOT NULL,
    email VARCHAR(191) NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by VARCHAR(191) NULL,
    PRIMARY KEY (item_id, email),
    KEY idx_inventory_item_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0026 adds the category vocabulary. `inventory_items.category` still stores the
-- name (no foreign key, like `assets.source`); the table only controls which
-- names the forms offer, per `kind`, and `usage` counting is done by the API.
CREATE TABLE IF NOT EXISTS inventory_categories (
    id INT NOT NULL AUTO_INCREMENT,
    kind VARCHAR(16) NOT NULL,
    name VARCHAR(64) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(191) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_category (kind, name),
    KEY idx_inventory_categories_kind (kind, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The same defaults migration 0027 seeds: a fresh import marks the migration as
-- applied, so the rows have to exist here or the required category field would
-- have nothing to offer. `Other` is the fallback on both kinds and stays last.
INSERT IGNORE INTO inventory_categories (kind, name, sort_order) VALUES
    ('asset', 'Display', 10),
    ('asset', 'Peripheral', 20),
    ('asset', 'Printer', 30),
    ('asset', 'Network', 40),
    ('asset', 'Server', 50),
    ('asset', 'Storage', 60),
    ('asset', 'Furniture', 70),
    ('asset', 'Other', 80),
    ('system', 'Operating system', 10),
    ('system', 'Application', 20),
    ('system', 'Software', 30),
    ('system', 'Service', 40),
    ('system', 'Subscription', 50),
    ('system', 'Platform', 60),
    ('system', 'Identity provider', 70),
    ('system', 'Database', 80),
    ('system', 'Security', 90),
    ('system', 'Development tool', 100),
    ('system', 'Other', 110);

INSERT IGNORE INTO schema_migrations (version) VALUES
    ('2026_11_02_0001_settings'),
    ('2026_11_02_0002_sources'),
    ('2026_11_02_0003_api_keys'),
    ('2026_11_02_0004_assets_provenance'),
    ('2026_11_02_0005_asset_fields'),
    ('2026_11_02_0006_user_preferences'),
    ('2026_11_02_0007_assets_indexes'),
    ('2026_11_04_0008_retire_spa_flag'),
    ('2026_11_04_0009_rate_limits'),
    ('2026_11_04_0010_asset_events'),
    ('2026_11_04_0011_saved_views'),
    ('2026_11_05_0012_audit_entities'),
    ('2026_11_06_0013_retire_storage_local_path'),
    ('2026_11_07_0014_asset_deletion_reason'),
    ('2026_11_03_0015_permissions'),
    ('2026_11_08_0016_api_key_users_scope'),
    ('2026_11_04_0017_asset_hardware_disks'),
    ('2026_11_05_0018_asset_agent_inventory'),
    ('2026_11_05_0019_retire_agent_only_columns'),
    ('2026_11_05_0020_retire_logged_username'),
    ('2026_11_06_0021_boolean_column_types'),
    ('2026_11_26_0022_settings_superadmin_only'),
    ('2026_11_26_0023_companies'),
    ('2026_11_26_0024_collector_tokens'),
    ('2026_11_26_0025_inventory_items'),
    ('2026_11_26_0026_inventory_categories'),
    ('2026_11_27_0027_inventory_category_defaults');


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
    ('eset', 'ESET', '#2f855a', 1, 0),
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
ALTER TABLE assets ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'eset';
ALTER TABLE assets ADD COLUMN source_ref VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN created_at DATETIME NULL;
ALTER TABLE assets ADD COLUMN updated_at DATETIME NULL;
ALTER TABLE assets ADD COLUMN created_by VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN updated_by VARCHAR(191) NULL;
ALTER TABLE assets ADD COLUMN deleted_at DATETIME NULL;

UPDATE assets SET source = 'eset' WHERE source IS NULL OR source = '';

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
    ('2026_11_05_0012_audit_entities');


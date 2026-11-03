-- BundledCMDB schema additions (for installations where the application user
-- cannot run ALTER TABLE / CREATE TABLE).
--
-- Preferred path: `php bin/migrate.php` (idempotent, records versions).
-- Use this file only when a DBA must apply the DDL manually, ONCE.
--
-- Requires the legacy tables `assets`, `users`, `device_files` to exist.
-- If assets.Id is BIGINT, change asset_custom_values.asset_id accordingly.

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

-- Record the versions so bin/migrate.php does not try to re-apply them.
INSERT IGNORE INTO schema_migrations (version) VALUES
    ('2026_11_02_0001_settings'),
    ('2026_11_02_0002_sources'),
    ('2026_11_02_0003_api_keys'),
    ('2026_11_02_0004_assets_provenance'),
    ('2026_11_02_0005_asset_fields'),
    ('2026_11_02_0006_user_preferences'),
    ('2026_11_02_0007_assets_indexes');

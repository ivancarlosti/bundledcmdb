-- Legacy (pre-migration) schema, used by docker/docker-compose.dev.yml so a
-- fresh dev database looks exactly like an existing production installation
-- before bin/migrate.php runs.
--
-- Not used in production: real installations already have these tables.

CREATE TABLE IF NOT EXISTS assets (
    Id INT NOT NULL AUTO_INCREMENT,
    UUID VARCHAR(191) NULL,
    SN VARCHAR(191) NULL,
    OS VARCHAR(191) NULL,
    OSVersion VARCHAR(191) NULL,
    Hostname VARCHAR(255) NULL,
    Mobile VARCHAR(16) NULL,
    Manufacturer VARCHAR(191) NULL,
    LastSeen DATETIME NULL,
    UserEmail VARCHAR(191) NULL,
    BYOD VARCHAR(16) NULL,
    Status VARCHAR(64) NULL,
    Warranty DATE NULL,
    Asset VARCHAR(191) NULL,
    PurchaseDate DATE NULL,
    CypherID VARCHAR(191) NULL,
    CypherKey VARCHAR(191) NULL,
    Notes TEXT NULL,
    CPUs VARCHAR(255) NULL,
    HDs VARCHAR(255) NULL,
    HDsTypes VARCHAR(255) NULL,
    HDsSpacesGB VARCHAR(255) NULL,
    NetworkAdapters VARCHAR(255) NULL,
    MACAddresses VARCHAR(255) NULL,
    ESETComponents VARCHAR(255) NULL,
    PrimaryLocalIP VARCHAR(64) NULL,
    PrimaryRemoteIP VARCHAR(64) NULL,
    company VARCHAR(191) NOT NULL DEFAULT '',
    PRIMARY KEY (Id),
    KEY idx_assets_company (company)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    company VARCHAR(191) NOT NULL DEFAULT '',
    role VARCHAR(32) NOT NULL DEFAULT 'user',
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_files (
    id INT NOT NULL AUTO_INCREMENT,
    device_id INT NOT NULL,
    device_table VARCHAR(64) NOT NULL DEFAULT 'assets',
    file_path VARCHAR(512) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NULL,
    size INT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_device (device_id, device_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (email, company, role) VALUES
    ('admin@example.com', 'Acme', 'superadmin'),
    ('manager@example.com', 'Acme', 'manager'),
    ('user@example.com', 'Acme', 'user'),
    ('other@example.com', 'Globex', 'admin');

INSERT INTO assets (UUID, SN, OS, OSVersion, Hostname, Mobile, Manufacturer, LastSeen, UserEmail, BYOD, Status, Warranty, Asset, PurchaseDate, CypherID, CypherKey, Notes, CPUs, HDs, company) VALUES
    ('11111111-1111-1111-1111-111111111111', 'SN-0001', 'Windows 11 Pro', '23H2', 'acme-laptop-01', 'false', 'Dell', '2026-10-01 12:00:00', 'user@example.com', 'false', 'In Use', '2027-01-15', 'ACME-NB-001', '2025-01-15', 'CY-1', 'KEY-1', 'Dev sample from ESET', '1', 'SSD 512GB', 'Acme'),
    ('22222222-2222-2222-2222-222222222222', 'SN-0002', 'Ubuntu 24.04', '24.04', 'acme-desktop-02', 'false', 'Lenovo', '2026-10-02 09:30:00', 'manager@example.com', 'true', 'In Stock', '2026-12-01', 'ACME-DT-002', '2024-11-02', NULL, NULL, NULL, '2', 'NVMe 1TB', 'Acme'),
    ('33333333-3333-3333-3333-333333333333', 'SN-0003', 'Windows 10', '22H2', 'globex-laptop-01', 'true', 'HP', '2026-09-20 08:15:00', 'other@example.com', 'false', 'In Repair', '2025-06-30', 'GLX-NB-001', '2023-06-30', NULL, NULL, 'Second tenant', '1', 'SSD 256GB', 'Globex');

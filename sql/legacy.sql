-- Legacy (pre-migration) schema, loaded by docker/docker-compose-bundle.yml on
-- first boot so a fresh database looks exactly like an existing installation
-- before bin/migrate.php runs. It is also the reference DDL for an installation
-- that already has these tables.
--
-- Schema only: the demo rows live in sql/dev-seed.sql and are never loaded
-- automatically. Table definitions here stay in sync with what lib/migrate.php
-- assumes exists (it only ALTERs these tables, it does not create them).

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


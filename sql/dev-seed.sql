-- Demo rows for local development (4 users across 2 tenants, 3 assets).
--
-- Never loaded automatically: docker/docker-compose-bundle.yml only applies
-- sql/legacy.sql. Apply this once, after the stack is up and migrated:
--   docker compose -f docker/docker-compose-bundle.yml exec -T db \
--     mariadb -ucmdb -pcmdb bundledcmdb < sql/dev-seed.sql
--
-- Requires sql/legacy.sql to have run (the tables) and is not idempotent.

INSERT INTO users (email, company, role) VALUES
    ('admin@example.com', 'Acme', 'superadmin'),
    ('manager@example.com', 'Acme', 'manager'),
    ('user@example.com', 'Acme', 'user'),
    ('other@example.com', 'Globex', 'admin');

INSERT INTO assets (UUID, SN, OS, OSVersion, Hostname, Mobile, Manufacturer, LastSeen, UserEmail, BYOD, Status, Warranty, Asset, PurchaseDate, CypherID, CypherKey, Notes, CPUs, HDs, company) VALUES
    ('11111111-1111-1111-1111-111111111111', 'SN-0001', 'Windows 11 Pro', '23H2', 'acme-laptop-01', 'false', 'Dell', '2026-10-01 12:00:00', 'user@example.com', 'false', 'In Use', '2027-01-15', 'ACME-NB-001', '2025-01-15', 'CY-1', 'KEY-1', 'Dev sample from ESET', '1', 'SSD 512GB', 'Acme'),
    ('22222222-2222-2222-2222-222222222222', 'SN-0002', 'Ubuntu 24.04', '24.04', 'acme-desktop-02', 'false', 'Lenovo', '2026-10-02 09:30:00', 'manager@example.com', 'true', 'In Stock', '2026-12-01', 'ACME-DT-002', '2024-11-02', NULL, NULL, NULL, '2', 'NVMe 1TB', 'Acme'),
    ('33333333-3333-3333-3333-333333333333', 'SN-0003', 'Windows 10', '22H2', 'globex-laptop-01', 'true', 'HP', '2026-09-20 08:15:00', 'other@example.com', 'false', 'In Repair', '2025-06-30', 'GLX-NB-001', '2023-06-30', NULL, NULL, 'Second tenant', '1', 'SSD 256GB', 'Globex');

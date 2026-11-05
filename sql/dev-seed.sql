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

INSERT INTO assets (UUID, SN, OS, OSVersion, Hostname, Mobile, Manufacturer, LastSeen, UserEmail, BYOD, Status, Warranty, Asset, PurchaseDate, CypherID, CypherKey, Notes, CPUs, HDs, GPUs, RAMGB, LoggedUsername, DeviceModel, PhysicalDisks, DisksTotalGB, DisksFreeGB, DisksUsedGB, company) VALUES
    ('11111111-1111-1111-1111-111111111111', 'SN-0001', 'Windows 11 Pro', '23H2', 'acme-laptop-01', 'false', 'Dell', '2026-10-01 12:00:00', 'user@example.com', 'false', 'In Use', '2027-01-15', 'ACME-NB-001', '2025-01-15', 'CY-1', 'KEY-1', 'Dev sample from ESET', '1', 'SSD 512GB', 'Intel Iris Xe', '16', 'jdoe', 'Dell Latitude 5440', '[{"number":0,"friendly_name":"WD Green M.2 2280 480GB","media_type":"SSD","bus_type":"NVMe","size_gb":476.94,"free_gb":210.5,"used_gb":266.44,"volumes":[{"drive":"C:","label":"Windows","total_gb":476.94,"free_gb":210.5,"used_gb":266.44}]}]', 476.94, 210.50, 266.44, 'Acme'),
    ('22222222-2222-2222-2222-222222222222', 'SN-0002', 'Ubuntu 24.04', '24.04', 'acme-desktop-02', 'false', 'Lenovo', '2026-10-02 09:30:00', 'manager@example.com', 'true', 'In Stock', '2026-12-01', 'ACME-DT-002', '2024-11-02', NULL, NULL, NULL, '2', 'NVMe 1TB', 'NVIDIA GeForce RTX 4060', '32', 'asmith', 'Lenovo ThinkStation P360', '[{"number":0,"friendly_name":"Samsung SSD 980 PRO 1TB","media_type":"SSD","bus_type":"NVMe","size_gb":953.87,"free_gb":402.11,"used_gb":551.76,"volumes":[{"drive":"/","label":"","total_gb":953.87,"free_gb":402.11,"used_gb":551.76}]},{"number":1,"friendly_name":"Seagate Expansion HDD","media_type":"HDD","bus_type":"USB","size_gb":1863.02,"free_gb":1740.25,"used_gb":122.77,"volumes":[{"drive":"/media/backup","label":"Expansion","total_gb":1863.02,"free_gb":1740.25,"used_gb":122.77}]}]', 2816.89, 2142.36, 674.53, 'Acme'),
    ('33333333-3333-3333-3333-333333333333', 'SN-0003', 'Windows 10', '22H2', 'globex-laptop-01', 'true', 'HP', '2026-09-20 08:15:00', 'other@example.com', 'false', 'In Repair', '2025-06-30', 'GLX-NB-001', '2023-06-30', NULL, NULL, 'Second tenant', '1', 'SSD 256GB', NULL, '8', 'b.jones', 'HP EliteBook 840 G8', NULL, NULL, NULL, NULL, 'Globex');

-- Demo rows for local development (4 users across 2 tenants, 3 assets).
--
-- Never loaded automatically: docker/docker-compose-bundle.yml only applies
-- sql/legacy.sql. Apply this once, after the stack is up and migrated:
--   docker compose -f docker/docker-compose-bundle.yml exec -T db \
--     mariadb -ucmdb -pcmdb bundledcmdb < sql/dev-seed.sql
--
-- Requires sql/legacy.sql to have run (the tables) and is not idempotent.

-- Hardware facts (model, GPU, RAM) are seeded into the `Agent*` block: the
-- legacy `DeviceModel`/`GPUs`/`RAMGB` columns are retired by migration 0019, and
-- the disk snapshots stay in `PhysicalDisks`.
--
-- `HDs`/`HDsTypes`/`HDsSpacesGB` are manual text columns — an agent report never
-- fills them — so the demo rows spell them out here instead of relying on a
-- derivation that no longer exists.

INSERT INTO users (email, company, role) VALUES
    ('admin@example.com', 'Acme', 'superadmin'),
    ('manager@example.com', 'Acme', 'manager'),
    ('user@example.com', 'Acme', 'user'),
    ('other@example.com', 'Globex', 'admin');

INSERT INTO assets (UUID, SN, OS, OSVersion, Hostname, Mobile, Manufacturer, LastSeen, UserEmail, BYOD, Status, Warranty, Asset, PurchaseDate, CypherID, CypherKey, Notes, CPUs, HDs, HDsTypes, HDsSpacesGB, PhysicalDisks, AgentModel, AgentGPU, AgentRAMGB, company) VALUES
    ('11111111-1111-1111-1111-111111111111', 'SN-0001', 'Windows 11 Pro', '23H2', 'acme-laptop-01', 'false', 'Dell', '2026-10-01 12:00:00', 'user@example.com', 'false', 'In Use', '2027-01-15', 'ACME-NB-001', '2025-01-15', 'CY-1', 'KEY-1', 'Dev sample from ESET', '1', 'SSD 512GB', 'SSD NVMe', '476.94', '[{"number":0,"friendly_name":"WD Green M.2 2280 480GB","media_type":"SSD","bus_type":"NVMe","size_gb":476.94,"free_gb":210.5,"used_gb":266.44,"volumes":[{"drive":"C:","label":"Windows","total_gb":476.94,"free_gb":210.5,"used_gb":266.44}]}]', 'Dell Latitude 5440', 'Intel Iris Xe', '16', 'Acme'),
    ('22222222-2222-2222-2222-222222222222', 'SN-0002', 'Ubuntu 24.04', '24.04', 'acme-desktop-02', 'false', 'Lenovo', '2026-10-02 09:30:00', 'manager@example.com', 'true', 'In Stock', '2026-12-01', 'ACME-DT-002', '2024-11-02', NULL, NULL, NULL, '2', 'NVMe 1TB', 'SSD NVMe, HDD USB', '953.87, 1863.02', '[{"number":0,"friendly_name":"Samsung SSD 980 PRO 1TB","media_type":"SSD","bus_type":"NVMe","size_gb":953.87,"free_gb":402.11,"used_gb":551.76,"volumes":[{"drive":"/","label":"","total_gb":953.87,"free_gb":402.11,"used_gb":551.76}]},{"number":1,"friendly_name":"Seagate Expansion HDD","media_type":"HDD","bus_type":"USB","size_gb":1863.02,"free_gb":1740.25,"used_gb":122.77,"volumes":[{"drive":"/media/backup","label":"Expansion","total_gb":1863.02,"free_gb":1740.25,"used_gb":122.77}]}]', 'Lenovo ThinkStation P360', 'NVIDIA GeForce RTX 4060', '32', 'Acme'),
    ('33333333-3333-3333-3333-333333333333', 'SN-0003', 'Windows 10', '22H2', 'globex-laptop-01', 'true', 'HP', '2026-09-20 08:15:00', 'other@example.com', 'false', 'In Repair', '2025-06-30', 'GLX-NB-001', '2023-06-30', NULL, NULL, 'Second tenant', '1', 'SSD 256GB', 'SSD SATA', '256', NULL, 'HP EliteBook 840 G8', NULL, '8', 'Globex');
-- Audit trail. The Logs screen (and the History card on the device view) reads
-- `asset_events`, which no seed row fills on its own, so without these the demo
-- stack shows an empty table and `ui/scripts/screenshots.mjs` captures a blank
-- screen. `action`/`entity` values must stay inside ASSET_EVENT_ACTIONS and
-- ASSET_EVENT_ENTITIES (lib/asset_events.php); `changes` is the same
-- `{"column": [old, new]}` JSON the panel writes, using the DB column names.
-- The device rows resolve the asset id by serial number (`MIN(Id)` in a session
-- variable), so the seed does not depend on AUTO_INCREMENT values and a re-run
-- resolves one id instead of failing on a duplicated demo row.
SET @acme_laptop_1 = (SELECT MIN(Id) FROM assets WHERE SN = 'SN-0001');
SET @acme_desktop_2 = (SELECT MIN(Id) FROM assets WHERE SN = 'SN-0002');

INSERT INTO asset_events (asset_id, action, actor, changes, note, created_at, company, entity, entity_id, entity_label) VALUES
    (@acme_laptop_1, 'create', 'admin@example.com', NULL, 'Imported from the inventory agent', '2026-10-20 09:12:00', 'Acme', 'device', CAST(@acme_laptop_1 AS CHAR), 'SN-0001'),
    (@acme_desktop_2, 'update', 'manager@example.com', '{"Status": ["In Stock", "In Use"], "UserEmail": ["manager@example.com", "user@example.com"]}', NULL, '2026-10-24 14:03:00', 'Acme', 'device', CAST(@acme_desktop_2 AS CHAR), 'SN-0002'),
    (@acme_laptop_1, 'file_add', 'user@example.com', NULL, 'Attached invoice-2026.pdf', '2026-10-28 08:41:00', 'Acme', 'device', CAST(@acme_laptop_1 AS CHAR), 'SN-0001'),
    (NULL, 'update', 'admin@example.com', '{"value": ["90", "180"]}', NULL, '2026-10-30 17:20:00', NULL, 'setting', 'logs.retention_days', 'Log retention (days)'),
    (NULL, 'role_change', 'admin@example.com', '{"role": ["user", "manager"]}', NULL, '2026-11-02 10:05:00', 'Acme', 'user', 'manager@example.com', 'manager@example.com'),
    (NULL, 'key_rotate', 'admin@example.com', NULL, 'Secret rotated, old key valid for 24 h', '2026-11-03 11:30:00', NULL, 'api_key', 'reporting', 'Reporting key'),
    (NULL, 'log_purge', 'admin@example.com', NULL, 'Purged rows older than 2026-05-01', '2026-11-04 06:00:00', NULL, 'logs', 'retention', 'Retention policy');


# Boolean columns stored as `tinyint(1)` broke every device save

## Context

Admins and managers could not save a device: the SPA showed
*"The database is unreachable right now."* while the database was perfectly
healthy. The application log held the real cause:

```
SQLSTATE[22007]: 1366 Incorrect integer value: 'false' for column `assets`.`Mobile` at row 1
```

`SHOW COLUMNS FROM assets LIKE 'Mobile'` on the affected database (`cmdb`):

```
Field   Type        Null  Key  Default  Extra
Mobile  tinyint(1)  YES
BYOD    tinyint(1)  YES
```

`sql/legacy.sql` (and therefore `sql/schema.sql`) declares both as
`VARCHAR(16)`, so this installation was created from an older release that used
numeric booleans.

Why it failed:

1. the form posts `{ mobile: false }`;
2. `panel_device_input()` maps it to `assets.Mobile` (`lib/panel/device_writes.php`);
3. `asset_normalize_input()` → `asset_bool_normalize()` (`lib/assets.php`) turns it
   into the **string** `'false'`;
4. `asset_filter_columns()` → `asset_update()`/`asset_create()` bound that string
   unchanged into a `tinyint(1)` column → strict-mode MySQL/MariaDB rejects it;
5. `lib/panel/routes.php` turned **every** `PDOException` into
   `database_unavailable` (503, "The database is unreachable right now."), which
   pointed at the network instead of the data.

The role is irrelevant: `Mobile` is in `asset_create_columns()` for `manager`,
`admin` and `superadmin`, so `/meta` publishes `mobile` in `writeColumns` for all
three and every non-`user` save sent the value. `BYOD` had the same problem
(including the grid checkbox and the bulk `byod` action).

## Goal

1. A device save works on both layouts — `VARCHAR(16)` (`'true'`/`'false'`) and
   legacy `tinyint(1)` (`1`/`0`) — for every role, without DDL privileges.
2. The schema converges on the declared `VARCHAR(16)` (migration 0021, mirrored in
   `sql/schema.sql` for the DBA path).
3. A rejected value is no longer reported as an outage: `database_error` (500,
   SQLSTATE in the message, statement in the log) versus `database_unavailable`
   (503) only for a real connection failure.

## Steps

1. `lib/assets.php` — `asset_bool_storage_kind()` (pure), `asset_bool_sql_value()`
   (pure), `asset_bool_storage()` (per-request cache over
   `db_column_definition()`), `asset_bool_storage_value()`, `asset_bool_storage_flush()`.
2. `lib/assets.php` — `asset_filter_columns()` binds `1`/`0` for a `bool` column
   whose physical type is numeric. It is the single funnel of `asset_create()`,
   `asset_update()`, the bulk `byod` action, the Bearer API and imports. `NULL`
   keeps its `NULL` (a `PUT` clears every omitted column), so only the spelling of
   the two real values changes.
3. `lib/migrate.php` — `2026_11_06_0021_boolean_column_types`: for
   `Mobile`/`BYOD`/`AgentSecureBoot`, `ALTER … MODIFY VARCHAR(16) NULL` then a
   `CASE` that collapses `1/0/true/false/yes/no/on/off` to `'true'`/`'false'`;
   a missing DDL privilege is logged and skipped, never fatal.
4. `sql/schema.sql` — the same two statements plus the new version in
   `INSERT IGNORE INTO schema_migrations` (the DBA path; `bin/check-schema.php`
   fails otherwise).
5. `lib/db.php` — `db_failure_map()`: SQLSTATE class `08` or a connection driver
   code (`1040/1044/1045/1129/1130/2002/2003/2005/2006/2013`) → 503
   `database_unavailable`; everything else → 500 `database_error`. Both
   `lib/panel/routes.php` and `public/api.php` use it, so the Bearer API no longer
   collapses a rejected value into `internal_error`.
6. `bin/test-panel-api.php` — DB-free checks for the storage helpers and the
   failure mapping, plus an end-to-end block that turns the real columns into
   `tinyint(1)`, creates/patches/bulk-updates a device, and restores the schema.
7. `docs/api.md` — document `database_error`/`database_unavailable` and the
   boolean storage rules.

## Risks

* **Write lock on a big table.** `MODIFY tinyint → varchar(16)` rebuilds `assets`
  on some MariaDB versions, and the `UPDATE` rewrites every non-`NULL` row. On a
  large installation run `php bin/migrate.php` off-hours (or apply
  `sql/schema.sql` in a maintenance window).
* **No DDL privileges** (the common Docker setup uses a dedicated user): the
  migration records a warning and the runtime coercion keeps writes working, so
  the installation stays on `tinyint` until a DBA widens the columns. Values read
  back correctly either way (`asset_bool_parse()`, the `BYOD` filter).
* **Other writers of these columns** are covered because they all pass through
  `asset_filter_columns()`; a raw SQL writer outside the model would not be.
* **`AgentSecureBoot`** is created by migration 0018 as `VARCHAR(16)`, so the
  third column is a no-op except on hand-made schemas.
* The test block needs `ALTER` on the test database; without it the block prints
  a `skip` line instead of failing (the DB-free checks still cover the logic).

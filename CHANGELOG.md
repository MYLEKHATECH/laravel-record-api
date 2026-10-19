# Changelog

All notable changes to `record-api` will be documented in this file.

## [Unreleased]

## [1.0.3] - 2026-10-19

### Security
- `POST /{table}/bulk` items with `"operation": "upsert"` (and the queued `ProcessBulkOperationJob` with operation `upsert`) ignored the table's `canUpsert`, so a table whose upsert endpoints were disabled could still be upserted. Such a batch is now refused as a whole with the same `404 Resource not available` response as `POST /{table}/upsert`, and nothing is written.
- Upserts skipped the before-hooks and validators that create and update run, so validation, tenant rules and limits placed in `beforeCreate` / `beforeUpdate` triggers could be bypassed by upserting instead. Each upserted row is now looked up by `match_on` first and runs the hooks of the write it performs:
  - `POST /{table}/upsert`: the global and table `beforeCreate` trigger, `createValidator` and default create validation for a new row; the global and table `beforeUpdate` trigger (with the existing `id`), `updateValidator` and default update validation for an existing row — the same as `POST /{table}` and `PUT /{table}/{id}`.
  - `POST /{table}/bulk/upsert`: the same per-row default validation and global/table before-hooks as `bulk/create` and `bulk/update`. Rows are written one by one inside one transaction, so a trigger sees the rows written before it and a rejected row rolls back the batch.
  - `POST /{table}/bulk` with `"operation": "upsert"`: the table `beforeCreate` / `beforeUpdate` trigger, like the create and update items of that endpoint.

  A rejecting trigger or validator refuses the upsert with the same response the create/update would give. After-hooks and audit logging are unchanged.

## [1.0.2] - 2026-10-05

### Changed
- Published on Packagist as an open-source package under the MIT license (was proprietary, installed from a private VCS repository). Consumers can drop the `vcs` repository entry and the GitHub token; `composer require mylekha/record-api:^1.0` resolves from Packagist.
- Accurate package description, keywords and support links in `composer.json`; dev-only files are excluded from dist archives.

## [1.0.1] - 2026-09-27

### Security
- Nested writes are scoped to the parent record and the caller's tenant. A nested has-many update or delete (`PUT /items/{id}` with `"parts": [{"id": …}]`) used to match the child by id alone, so another parent's or another tenant's row could be changed or deleted by guessing its id. Many-to-many and has-many-through links no longer attach an existing row that belongs to another tenant.

### Fixed
- Fetch/update/delete/restore on a table whose config key differs from its physical table (e.g. `leave_types` → `hrms_leave_types`) failed with "Unknown column": the tenant filter was qualified with the config key. It now uses the physical table.
- Table functions (`/{table}/rpc/...`) that fail `$request->validate()` now return **422** with the field errors, like the CRUD endpoints, instead of HTTP 500 "Function execution failed". An `HttpResponseException` thrown by a function now returns its own response.

## [1.0.0] - 2026-09-26

First stable release; consumers should require `^1.0`.

### Fixed
- `getRecord()` now strips hidden fields like `listRecords()` already did.

### Added
- **Upsert Support**: Added `POST /{table}/upsert` and `POST /{table}/bulk/upsert` endpoints for atomic create-or-update operations.
- **Match On Parameter**: Required `match_on` query parameter for upsert operations to define matching columns dynamically.
- **Configuration**: Added `$canUpsert` to `RecordTableType` to control upsert endpoint availability (default: `true`).
- **OpenAPI**: Updated OpenAPI generator to include single and bulk upsert endpoints with schema definitions.

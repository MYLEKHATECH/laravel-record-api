# Changelog

All notable changes to `record-api` will be documented in this file.

## [Unreleased]

### Added
- Every table operation in the generated OpenAPI spec has a stable `operationId`: `list{Table}`, `create{Table}`, `get{Table}`, `update{Table}`, `delete{Table}`, `restore{Table}`, `forceDelete{Table}`, `upsert{Table}` and `bulkUpsert{Table}` (e.g. `listInvoiceItems`). Code generators and AI tool callers use them as operation names. Record names that map to the same id (`invoice_items` and `invoice-items`) get a numeric suffix so ids stay unique. Existing RPC operation ids are unchanged.

### Changed
- `/docs/llms.txt` (and `llms.mdx`) describe the configured API: the tenant header only when multi-tenancy is on, auth only for protected endpoints, plus endpoint patterns, query syntax, pagination limits, the response envelope and `operationId`s. It no longer calls every API "private/internal".
- The generated OpenAPI description no longer claims that every endpoint needs a token, no longer documents cursor pagination or `lazy=true` (neither exists on the generated endpoints), states the real `per_page` / `limit` caps, and no longer sets `info.license` to "Proprietary - Internal Use Only".

### Fixed
- Many-to-many and has-many-through writes ignored bare ids, so `"tags": [3]` linked nothing although the docs and OpenAPI described it. A bare id now links the existing row like `{"id": 3}`, with the same tenant check.
- `record-api:validate` reported a missing `cursor_pagination.php` config on every install; that file was removed with cursor pagination.
- `composer analyse` ran out of memory; it now runs PHPStan with a 1 GB limit.

### Documentation
- `AGENTS.md` (renamed from `AGENT.md`, with a `CLAUDE.md` pointer) gives AI coding agents the commands, layout, contracts and pitfalls of this repository.
- `docs/SKILL.md` and `docs/SKILL-FRONTEND.md` are corrected against the code and carry skill front matter; the duplicate `docs/trae-skill-*.md` copies are removed. Corrections include: hooks are static class methods (`RecordTableTriggerType`), not closures, and reject a write by returning a response; `public:` takes `RecordTablePublic` or `isAuthRead` / `isAuthWrite`, not an array; bulk upsert takes a JSON array and requires `match_on`; pivot columns sit next to `id`; search is `?s=`; and the missing options `canUpsert`, `uniqueIds`, `overrideTimestamps` and `overrideUserstamps`.
- README and `docs/api-documentation.md`: `?with=` works only in custom controllers using `QueryHelpers`; generated endpoints embed relations with `select`. Removed the nonexistent `record-api:cache-clear` command and stale cursor-pagination references; the validate command is `record-api:validate`.

## [1.1.1] - 2026-10-21

### Changed
- The API docs page (`/api-docs`) stores its access token under the session and browser-storage key `record_api_docs_access_token`. After upgrading, anyone signed in to the docs signs in once more.
- `record-api:setup` and `record-api:validate` output, route comments and the error-response log message (`Record API error response`) use the Record API name. Update any log filter that matches the old message text.
- The generated OpenAPI description links to the package documentation at https://record-api-docs.vercel.app.

### Documentation
- New documentation site at https://record-api-docs.vercel.app, built from `README.md` and `docs/`.
- README: removed links to pages that do not exist and a migration guide for a 2.x release that does not exist; cache examples use the `record_api` prefix and `RECORD_API_CACHE_API`, matching `config/record.php`; the VCS repository URL points to the `MYLEKHATECH` organisation.
- CONTRIBUTING: contributions are licensed under the MIT License.

## [1.1.0] - 2026-10-20

### Added
- `uniqueIds` table option on `RecordTableType` (default `[]`): a list of columns the server generates, such as an immutable `uuid` secondary key. Rows created through the engine's raw query-builder inserts used to get `NULL` there, because only Eloquent filled such columns and `beforeCreate` triggers cannot add fields. For every listed column:
  - every insert the engine performs into the table sets a fresh lowercase UUIDv7, overriding any client-supplied value: `POST /{table}`, `POST /{table}/bulk/create`, create items of `POST /{table}/bulk` (and the queued `ProcessBulkOperationJob`), upserts that insert a new row (`POST /{table}/upsert`, `POST /{table}/bulk/upsert`, upsert items of `POST /{table}/bulk`), and nested relationship creates into the table (has-many children, many-to-many related and pivot rows, has-many-through target and through rows);
  - updates never change it: it is stripped from update payloads (single, bulk and nested) and left out of an upsert's update columns, so an upsert matching an existing row keeps that row's value;
  - default validation adds no rules for it (a `NOT NULL` column is not reported as required);
  - an upsert whose `match_on` names it is refused with `422`, since the client value is never written.

  Tables without `uniqueIds` behave exactly as before.

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

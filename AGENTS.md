# AGENTS.md — `mylekha/record-api`

Instructions for AI coding agents working on this repository. Humans: see `README.md` and `CONTRIBUTING.md`.

## What this is

A Laravel package (PHP 8.2+, Laravel 12) that turns configuration into a REST API. Each table is declared
as a `RecordTableType` in the host app's `config/record.php`; the package registers the routes and serves
CRUD, bulk, upsert, restore, RPC functions, audit logging and a generated OpenAPI spec. No per-table
controllers or routes.

Request flow: `routes/api.php` → `CoreRecordController` (traits in `src/Http/Controllers/Concerns/`)
→ `RecordService` → hooks, validators, relationship writes → `RecordApiResponseService` (response envelope).

## Commands

```bash
composer install
composer test            # PHPUnit (Orchestra Testbench, SQLite in memory)
composer analyse         # PHPStan
composer format-check    # Rector dry run (some existing files are already flagged; do not reformat unrelated code)
npm ci --prefix website && npm run build --prefix website   # docs site, when docs change
```

Run `composer test` before finishing any change. Run a single file with `vendor/bin/phpunit tests/Feature/OpenApiTest.php`.

## Layout

| Path | Contents |
|------|----------|
| `src/Types/` | Config objects: `RecordTableType` (single source of truth for a table), `RecordFunctionType`, relationship types, `RecordTableTriggerType` |
| `src/Services/` | `RecordService` (reads, writes, hooks), `RecordApiResponseService` (envelope), `OpenApiService` (spec + `llms.txt`), `RecordConfigService` (typed config access), audit and cache services |
| `src/Utilities/` | Query filters (`QueryBuilderFiltersUtils`), relationship reads/writes (`RelationshipResolverUtils`), schema registry, validation, unique ids |
| `src/Http/` | `CoreRecordController` + `Concerns/`, `RecordRouteMiddleware`, `RequestId` |
| `src/Console/` | `record-api:*` artisan commands |
| `config/` | `record.php`, `record-api.php`, `audit.php` (published to the host app) |
| `routes/` | `api.php` (record API), `web.php` (`/api-docs` UI and login) |
| `tests/Feature`, `tests/Unit` | PHPUnit; base class `tests/TestCase.php` |
| `docs/` | Markdown docs; `SKILL.md` / `SKILL-FRONTEND.md` are agent skills for apps using the package |
| `website/` | VitePress docs site built from `README.md` and `docs/` (https://record-api-docs.vercel.app) |

## Contracts you must not break

- **Response envelope** (`RecordApiResponseService::successWrapped` / `errorWrapped`):
  success `{success: true, error_code: 0, data, meta: {request_id, …}}`;
  error `{success: false, error_code, message, errors, meta: {request_id}}`.
  The key is `error_code` (snake_case); codes live in `src/Constants/HttpErrorCodeConstant.php`.
- **Config semantics**: existing `RecordTableType` options keep their meaning and defaults. New behaviour
  is opt-in through a new option with a backward-compatible default. Every new constructor option must
  also be read in `__set_state()`, or it is lost under `php artisan config:cache`.
- **OpenAPI** must describe the runtime exactly. CRUD operations have stable `operationId`s
  (`list{Table}`, `create{Table}`, …); do not rename them, since clients and AI tools are generated from them.
- **Security**: nested writes are scoped to the parent record and the caller's tenant
  (`RelationshipResolverUtils::ownedChildQuery`, `rowInTenant`). Keep that scoping on any new write path.

## How the code behaves (easy to get wrong)

- Hooks (`beforeCreate`, `afterUpdate`, …) are **static class methods** referenced by
  `RecordTableType(beforeCreate: new RecordTableTriggerType(class: …, functionName: …))`, not closures.
  They receive `(Request $request, string $table, array $context)`. A before-hook stops the write by
  returning a `JsonResponse`; anything thrown from a hook becomes a generic 500.
- Validators (`createValidator`, …) may be closures returning a `Validator`.
- List endpoints page with `page` / `per_page` or `limit`; there is no cursor pagination. Search is `?s=`.
- Upsert and bulk upsert require the `match_on` query parameter; bulk endpoints take a JSON array.
- Writes go through the query builder (`DB::table()`), not Eloquent, so model events and casts do not run.

## Making a change

1. Add or update a test in `tests/Feature` (HTTP or service behaviour) or `tests/Unit` (pure logic).
   Tests use the `/** @test */` style of the surrounding files.
2. Keep `README.md`, `docs/api-documentation.md` and `docs/SKILL*.md` in step with behaviour.
   A new `## ` section in `README.md` must also be mapped in `website/pages.mjs`, or the docs build fails.
3. If the change is user-visible, add it under `## [Unreleased]` in `CHANGELOG.md`.

## Releases

Version lives in `VERSION` and `CHANGELOG.md`. A release is a lightweight git tag (`1.2.0`, no `v`) plus a
GitHub release; Packagist reads the tags. Do not tag or publish unless asked.

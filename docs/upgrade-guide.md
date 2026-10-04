# Upgrade Guide: sopheak/sp-laravel-api → mylekha/record-api

This guide covers all breaking changes when migrating from `sopheak/sp-laravel-api` to `mylekha/record-api`.

---

## 1. Update composer.json

```json
// Before
"sopheak/sp-laravel-api": "^0.x"

// After
"mylekha/record-api": "^1.0"
```

Then run:

```bash
composer update mylekha/record-api
```

---

## 2. Rename your published config file

```bash
mv config/sp-laravel-api.php config/record-api.php
```

Inside that file, rename the environment variable reference:

```php
// Before
'auth_guard' => env('SP_LARAVEL_API_AUTH_GUARD', 'sanctum'),

// After
'auth_guard' => env('RECORD_API_AUTH_GUARD', 'sanctum'),
```

---

## 3. Update .env (11 variables)

| Before | After |
|---|---|
| `SP_LARAVEL_API_AUTH_GUARD` | `RECORD_API_AUTH_GUARD` |
| `SP_LARAVEL_API_AUDIT_ENABLED` | `RECORD_API_AUDIT_ENABLED` |
| `SP_LARAVEL_API_AUDIT_QUEUE` | `RECORD_API_AUDIT_QUEUE` |
| `SP_LARAVEL_API_AUDIT_QUEUE_NAME` | `RECORD_API_AUDIT_QUEUE_NAME` |
| `SP_LARAVEL_API_AUDIT_QUEUE_CONNECTION` | `RECORD_API_AUDIT_QUEUE_CONNECTION` |
| `SP_LARAVEL_API_AUDIT_RETENTION_DAYS` | `RECORD_API_AUDIT_RETENTION_DAYS` |
| `SP_LARAVEL_API_AUDIT_CLEAN_ENABLED` | `RECORD_API_AUDIT_CLEAN_ENABLED` |
| `SP_LARAVEL_API_AUDIT_CLEAN_FREQUENCY` | `RECORD_API_AUDIT_CLEAN_FREQUENCY` |
| `SP_LARAVEL_API_AUDIT_LOG_CHANNEL` | `RECORD_API_AUDIT_LOG_CHANNEL` |
| `SP_LARAVEL_API_AUDIT_EXCLUDE_ACTIONS` | `RECORD_API_AUDIT_EXCLUDE_ACTIONS` |
| `SP_LARAVEL_API_AUDIT_EXCLUDE_TABLES` | `RECORD_API_AUDIT_EXCLUDE_TABLES` |

---

## 4. Update vendor:publish tags

```bash
# Config
php artisan vendor:publish --tag=record-api-config

# Migrations
php artisan vendor:publish --tag=record-api-migrations
```

| Before | After |
|---|---|
| `--tag=sp-laravel-api-config` | `--tag=record-api-config` |
| `--tag=sp-laravel-api-migrations` | `--tag=record-api-migrations` |

---

## 5. Update service provider (if manually registered)

If you have manually registered the provider in `config/app.php` or `bootstrap/providers.php`:

```php
// Before
Sopheak\Core\CoreSpLaravelApiProvider::class,

// After
Mylekha\RecordApi\RecordApiServiceProvider::class,
```

---

## 6. Find-replace namespace in your application code

Run a project-wide find-replace:

```
Sopheak\Core\  →  Mylekha\RecordApi\
```

---

## 7. Artisan commands (all renamed)

| Before | After |
|---|---|
| `php artisan sp-laravel-api:setup` | `php artisan record-api:setup` |
| `php artisan sp-laravel-api:validate-setup` | `php artisan record-api:validate-setup` |
| `php artisan sp-laravel-api:make-record-table` | `php artisan record-api:make-record-table` |
| `php artisan sp-laravel-api:sync-record-columns` | `php artisan record-api:sync-record-columns` |
| `php artisan sp-laravel-api:generate-record-tables` | `php artisan record-api:generate-record-tables` |
| `php artisan sp-laravel-api:clean-audit-logs` | `php artisan record-api:clean-audit-logs` |

---

## 8. Unchanged — no action needed

The following are **not** breaking changes and require no updates on your end:

- `config/record.php` structure (`record.*` keys are unchanged)
- HTTP endpoints and response shapes
- Middleware aliases (`record.route.middleware`, `request.id`)
- Database migrations (no re-run needed)
- All RecordTable type definitions and `RecordFunctionInterface` API

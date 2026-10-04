<?php

namespace Mylekha\RecordApi;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mylekha\RecordApi\Console\CleanAuditLogsCommand;
use Mylekha\RecordApi\Console\SyncRecordColumnsCommand;
use Mylekha\RecordApi\Console\GenerateRecordTablesFromDatabaseCommand;
use Mylekha\RecordApi\Console\MakeRecordTableCommand;
use Mylekha\RecordApi\Console\SetupPackageCommand;
use Mylekha\RecordApi\Console\ValidateSetupCommand;
use Mylekha\RecordApi\Http\Middleware\RecordRouteMiddleware;
use Mylekha\RecordApi\Http\Middleware\RequestId;
use Mylekha\RecordApi\Services\AuditLogService;
use Mylekha\RecordApi\Services\QueryCacheService;
use Mylekha\RecordApi\Services\RecordApiResponseService;
use Mylekha\RecordApi\Services\RecordService;

class RecordApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/record-api.php', 'record-api');

        $this->app->singleton('api.response', fn(): RecordApiResponseService => new RecordApiResponseService());
        $this->app->singleton(AuditLogService::class);
        $this->app->singleton(QueryCacheService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/record-api.php' => config_path('record-api.php'),
            __DIR__ . '/../config/audit.php' => config_path('audit.php'),
            __DIR__ . '/../config/record.php' => config_path('record.php'),
        ], 'record-api-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'record-api-migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'record-api');

        // Load package routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $commands = [
                SetupPackageCommand::class,
                ValidateSetupCommand::class,
                SyncRecordColumnsCommand::class,
                CleanAuditLogsCommand::class,
                MakeRecordTableCommand::class,
                GenerateRecordTablesFromDatabaseCommand::class,
            ];

            $commands = array_values(array_filter($commands, class_exists(...)));
            $this->commands($commands);
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('request.id', RequestId::class);
        $router->aliasMiddleware('record.route.middleware', RecordRouteMiddleware::class);

        /**
         * @param Request     $request       HTTP request carrying query parameters.
         * @param bool        $isArray       When true, shape results as an array on the client side.
         * @param string      $orderBy       Default column to sort by when sortby is not provided.
         * @param string|null $tenantColumn  Optional tenant column value for multi-tenant scoping.
         *
         * @return array{
         *     data: mixed,
         *     meta: array,
         *     headers: array,
         *     filters: array,
         *     request: Request,
         *     cursor_meta: mixed
         * }
         */
        Builder::macro('applyRequestFilters', function (Request $request, bool $isArray = true, string $orderBy = 'id', ?string $tenantColumn = ''): array {
            /** @var Builder $this */
            return RecordService::applyRequestFilters($request, $this, $tenantColumn, $isArray, $orderBy);
        });
    }
}

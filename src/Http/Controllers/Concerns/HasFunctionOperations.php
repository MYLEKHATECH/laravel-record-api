<?php

namespace Mylekha\RecordApi\Http\Controllers\Concerns;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mylekha\RecordApi\Enums\RecordApiJsonResponseEnum;
use Mylekha\RecordApi\Services\RecordApiResponseService;
use Mylekha\RecordApi\Services\RecordService;

/**
 * @property RecordService $recordService
 */
trait HasFunctionOperations
{
    /**
     * Execute a table-specific custom function.
     */
    public function executeTableFunction(Request $request, string $table, string $functionName): JsonResponse
    {
        try {
            $tableSchema = $this->resolveSchemaOrFail($table);

            if (!$this->isReadEndpointEnabled($tableSchema)) {
                return $this->resourceNotAvailableResponse();
            }

            $this->authorizeAction($table, 'read');

            return $this->recordService->executeTableFunction($request, $table, $functionName);
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage(), $status);
        }
    }

    /**
     * Execute a global custom function.
     * Supports patterns like: function_name or function_name/{id}.
     */
    public function executeGlobalFunction(Request $request, string $functionName): JsonResponse
    {
        try {
            return $this->recordService->executeGlobalFunction($request, $functionName);
        } catch (Exception $exception) {
            $status = $exception->getCode();
            if (!is_int($status) || $status < 100 || $status > 599) {
                $status = RecordApiJsonResponseEnum::SERVER_ERROR->value;
            }

            return RecordApiResponseService::errorFromException($exception, $exception->getMessage(), $status);
        }
    }
}

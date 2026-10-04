<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Http\Request;
use Mylekha\RecordApi\Services\RecordService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordFunctionType;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

/**
 * A table function that calls $request->validate() used to come back as HTTP 500
 * "Function execution failed: ..." because the executor caught every exception.
 * Validation errors must return 422 with the field errors, like the CRUD endpoints.
 */
class FunctionValidationErrorTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.tables', [
            'sales' => new RecordTableType(
                table: 'sales',
                functions: [
                    'syncPayments' => new RecordFunctionType(
                        httpMethod: 'POST',
                        class: FunctionValidationErrorHandler::class,
                        functionName: 'syncPayments',
                        isPublic: true
                    ),
                ]
            ),
        ]);
        $app['config']->set('record.rpc_prefix', 'rpc');
    }

    /** @test */
    public function it_returns_422_with_field_errors_when_a_function_fails_validation(): void
    {
        SchemaRegistryUtils::refresh();

        $response = (new RecordService())->executeTableFunction(
            Request::create('/api/sales/rpc/syncPayments', 'POST', []),
            'sales',
            'syncPayments'
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('payments', $response->getData(true)['errors'] ?? []);
    }

    /** @test */
    public function it_still_returns_500_when_a_function_throws_something_else(): void
    {
        SchemaRegistryUtils::refresh();

        $response = (new RecordService())->executeTableFunction(
            Request::create('/api/sales/rpc/syncPayments', 'POST', ['payments' => ['boom']]),
            'sales',
            'syncPayments'
        );

        $this->assertSame(500, $response->getStatusCode());
    }
}

class FunctionValidationErrorHandler
{
    public function syncPayments(Request $request): array
    {
        $request->validate(['payments' => 'required|array']);

        throw new \RuntimeException('boom');
    }
}

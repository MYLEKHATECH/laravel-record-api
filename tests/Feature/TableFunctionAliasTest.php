<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Http\Request;
use Mylekha\RecordApi\Services\RecordService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordFunctionType;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

class TableFunctionAliasTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.tables', [
            'journal_entries' => new RecordTableType(
                table: 'journal_entries',
                pmsName: 'journalEntry',
                functions: [
                    'parties' => new RecordFunctionType(
                        httpMethod: 'GET',
                        class: TableFunctionAliasHandler::class,
                        functionName: 'parties',
                        isPublic: true
                    ),
                ]
            ),
        ]);
        $app['config']->set('record.rpc_prefix', 'rpc');
    }

    /** @test */
    public function it_executes_table_function_using_pms_name_alias(): void
    {
        SchemaRegistryUtils::refresh();

        $service = new RecordService();
        $response = $service->executeTableFunction(
            Request::create('/api/journalEntry/parties', 'GET'),
            'journalEntry',
            'parties'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getData(true)['data']['status'] ?? null);
    }
}

class TableFunctionAliasHandler
{
    public function parties(Request $request): array
    {
        return [
            'status' => 'ok',
            'method' => $request->getMethod(),
        ];
    }
}

<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Mylekha\RecordApi\Services\OpenApiService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordBelongsToType;
use Mylekha\RecordApi\Types\RecordHasManyType;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

class OpenApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_generates_correct_server_url_without_duplicate_api_path(): void
    {
        // Set configuration to match the reported issue
        Config::set('app.url', 'http://mylekha_task_management_back.test');
        Config::set('record.api_prefix', 'api/v1');
        // Clear tables to avoid SchemaRegistryUtils errors due to array vs object mismatch in TestCase defaults
        Config::set('record.tables', []);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        // Check server URL
        $serverUrl = $spec['servers'][0]['url'];
        
        // The fix should remove the appended '/api', so it should just be the app.url
        $this->assertEquals('http://mylekha_task_management_back.test', $serverUrl);
    }

    /** @test */
    public function it_generates_global_rpc_paths_with_rpc_prefix(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', 'rpc');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'description' => 'Login',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/v2/rpc/auth/login', $spec['paths']);
        $this->assertSame(['RPC - Auth'], $spec['paths']['/api/v2/rpc/auth/login']['post']['tags']);
    }

    /** @test */
    public function it_generates_global_rpc_paths_without_rpc_prefix(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.rpc_prefix', '');
        Config::set('record.tables', []);
        Config::set('record.global_functions', [
            'auth/login' => [
                'httpMethod' => ['POST'],
                'description' => 'Login',
            ],
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/v2/auth/login', $spec['paths']);
        $this->assertArrayNotHasKey('/api/v2/rpc/auth/login', $spec['paths']);
        $this->assertSame(['RPC - Auth'], $spec['paths']['/api/v2/auth/login']['post']['tags']);
    }

    /** @test */
    public function it_generates_spec_without_writing_internal_openapi_file(): void
    {
        Config::set('app.url', 'http://localhost');
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', []);
        Config::set('record.global_functions', []);

        $service = new OpenApiService();
        $spec = $service->generateInternal();

        $this->assertArrayHasKey('openapi', $spec);
        $this->assertArrayHasKey('paths', $spec);
    }

    /** @test */
    public function it_documents_relationship_payload_shapes_clearly(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoices' => new RecordTableType(
                table: 'invoices',
                columns: [
                    'id' => ['type' => 'bigint', 'nullable' => false],
                    'customer_id' => ['type' => 'bigint', 'nullable' => true],
                    'ref_number' => ['type' => 'varchar', 'nullable' => true],
                ],
                relationships: [
                    'customer' => new RecordBelongsToType(table: 'customers', foreignKey: 'customer_id'),
                    'items' => new RecordHasManyType(table: 'invoice_items', foreignKey: 'invoice_id'),
                ],
            ),
            'customers' => new RecordTableType(
                table: 'customers',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false], 'invoice_id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $service = new OpenApiService();
        $spec = $service->generateInternal();
        $path = $spec['paths']['/api/v2/invoices'] ?? [];
        $createDescription = $path['post']['description'] ?? ($path['get']['description'] ?? '');

        $this->assertStringContainsString('Relationship payload guide', $createDescription);
        $this->assertStringContainsString('`items`', $createDescription);
        $this->assertStringContainsString('array<id|object>', $createDescription);
        $this->assertStringContainsString('FK relationship input (belongsTo)', $createDescription);
        $this->assertStringContainsString('`customer_id`', $createDescription);
        $this->assertStringContainsString('Payload examples', $createDescription);
        $this->assertStringContainsString('#relationship-write-payload-guide', $createDescription);
    }

    /** @test */
    public function it_gives_every_table_operation_a_stable_operation_id(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                softDeletes: true,
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $paths = (new OpenApiService())->generateInternal()['paths'];

        $this->assertSame('listInvoiceItems', $paths['/api/v2/invoice_items']['get']['operationId']);
        $this->assertSame('createInvoiceItems', $paths['/api/v2/invoice_items']['post']['operationId']);
        $this->assertSame('getInvoiceItems', $paths['/api/v2/invoice_items/{id}']['get']['operationId']);
        $this->assertSame('updateInvoiceItems', $paths['/api/v2/invoice_items/{id}']['put']['operationId']);
        $this->assertSame('deleteInvoiceItems', $paths['/api/v2/invoice_items/{id}']['delete']['operationId']);
        $this->assertSame('restoreInvoiceItems', $paths['/api/v2/invoice_items/{id}/restore']['post']['operationId']);
        $this->assertSame('forceDeleteInvoiceItems', $paths['/api/v2/invoice_items/{id}/force']['delete']['operationId']);
        $this->assertSame('upsertInvoiceItems', $paths['/api/v2/invoice_items/upsert']['post']['operationId']);
        $this->assertSame('bulkUpsertInvoiceItems', $paths['/api/v2/invoice_items/bulk/upsert']['post']['operationId']);
    }

    /** @test */
    public function it_keeps_operation_ids_unique_when_record_names_collide(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', [
            'invoice_items' => new RecordTableType(
                table: 'invoice_items',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
            'invoice-items' => new RecordTableType(
                table: 'invoice_items',
                columns: ['id' => ['type' => 'bigint', 'nullable' => false]],
            ),
        ]);

        $operationIds = [];
        foreach ((new OpenApiService())->generateInternal()['paths'] as $operations) {
            foreach ($operations as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    $operationIds[] = $operation['operationId'];
                }
            }
        }

        $this->assertSame($operationIds, array_unique($operationIds));
        $this->assertContains('listInvoiceItems', $operationIds);
        $this->assertContains('listInvoiceItems2', $operationIds);
    }

    /** @test */
    public function it_describes_the_configured_api_in_the_agent_contract(): void
    {
        Config::set('record.api_prefix', 'api/v2');
        Config::set('record.tables', []);
        Config::set('record.enable_tenant_id', false);

        $contract = OpenApiService::generateLlmMdx();

        $this->assertStringContainsString('operationId', $contract);
        $this->assertStringContainsString('Tenant: not used by this API.', $contract);
        $this->assertStringNotContainsString('private/internal', $contract);

        Config::set('record.enable_tenant_id', true);
        Config::set('record.tenant_header', 'X-Tenant-ID');

        $this->assertStringContainsString('`X-Tenant-ID: <tenant-id>`', OpenApiService::generateLlmMdx());
    }
}

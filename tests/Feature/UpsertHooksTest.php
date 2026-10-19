<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Mylekha\RecordApi\Exceptions\RecordNotFoundException;
use Mylekha\RecordApi\Jobs\ProcessBulkOperationJob;
use Mylekha\RecordApi\Services\RecordConfigService;
use Mylekha\RecordApi\Services\RecordService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordTablePublic;
use Mylekha\RecordApi\Types\RecordTableTriggerType;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;
use RuntimeException;

// ---------------------------------------------------------------------------
// Static spy — state is reset in setUp()
// ---------------------------------------------------------------------------

class UpsertHookSpy
{
    /** @var array<int, array{0: string, 1: array}> hook name and its arguments without the Request */
    public static array $calls = [];

    /** Reject a create once the table holds this many rows (like an app's plan limit). */
    public static ?int $createLimit = null;

    public static bool $rejectUpdate = false;

    public static bool $throwUpdate = false;

    public static function reset(): void
    {
        self::$calls        = [];
        self::$createLimit  = null;
        self::$rejectUpdate = false;
        self::$throwUpdate  = false;
    }

    public static function beforeCreate(Request $request, string $table, mixed ...$args): ?JsonResponse
    {
        self::$calls[] = ['beforeCreate', [$table, ...$args]];

        if (null !== self::$createLimit && DB::table($table)->count() >= self::$createLimit) {
            return response()->json(['message' => 'Product limit reached'], 422);
        }

        return null;
    }

    public static function beforeUpdate(Request $request, string $table, mixed ...$args): ?JsonResponse
    {
        self::$calls[] = ['beforeUpdate', [$table, ...$args]];

        if (self::$throwUpdate) {
            throw new RuntimeException('Updates are locked');
        }

        if (self::$rejectUpdate) {
            return response()->json(['message' => 'Product is locked'], 422);
        }

        return null;
    }

    public static function afterCreate(Request $request, string $table, array $context): void
    {
        self::$calls[] = ['afterCreate', [$table, $context]];
    }

    public static function afterUpdate(Request $request, string $table, array $context): void
    {
        self::$calls[] = ['afterUpdate', [$table, $context]];
    }

    /** @return list<string> */
    public static function hooks(): array
    {
        return array_map(fn (array $call): string => $call[0], self::$calls);
    }

    public static function argsOf(string $hook, int $nth = 0): array
    {
        $matches = array_values(array_filter(self::$calls, fn (array $call): bool => $call[0] === $hook));

        return $matches[$nth][1];
    }
}

// ---------------------------------------------------------------------------

class UpsertHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UpsertHookSpy::reset();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('price');
            $table->timestamps();
        });
    }

    private function registerTable(bool $canUpsert = true, bool $withTriggers = true, array $extra = []): void
    {
        $triggers = $withTriggers ? [
            'beforeCreate' => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'beforeCreate'),
            'beforeUpdate' => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'beforeUpdate'),
            'afterCreate'  => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'afterCreate'),
            'afterUpdate'  => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'afterUpdate'),
        ] : [];

        Config::set('record.tables', [
            'products' => new RecordTableType(...array_merge([
                'table'     => 'products',
                'canUpsert' => $canUpsert,
                'public'    => new RecordTablePublic(read: true, write: true),
            ], $triggers, $extra)),
        ]);
        SchemaRegistryUtils::refresh();
    }

    private function seedProduct(string $sku = 'SKU-OLD', string $name = 'Old', int $price = 50): int
    {
        return DB::table('products')->insertGetId([
            'sku'        => $sku,
            'name'       => $name,
            'price'      => $price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The response body without meta (meta.request_id differs per request). */
    private function body(TestResponse $response): array
    {
        $json = $response->json();
        unset($json['meta']);

        return $json;
    }

    private function assertSameResponse(TestResponse $expected, TestResponse $actual): void
    {
        $this->assertSame($expected->getStatusCode(), $actual->getStatusCode());
        $this->assertSame($this->body($expected), $this->body($actual));
    }

    // -----------------------------------------------------------------------
    // POST {table}/bulk with operation: upsert honours canUpsert
    // -----------------------------------------------------------------------

    /** @test */
    public function bulk_operation_upsert_is_refused_like_the_upsert_endpoint_when_can_upsert_is_false(): void
    {
        $this->registerTable(canUpsert: false);

        $dedicated = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]);
        $dedicated->assertStatus(404);

        $bulk = $this->postJson('/api/products/bulk?match_on=sku', [
            'items' => [['operation' => 'upsert', 'sku' => 'SKU-1', 'name' => 'One', 'price' => 10]],
        ]);

        $this->assertSameResponse($dedicated, $bulk);
        $this->assertSameResponse($dedicated, $this->postJson('/api/products/bulk/upsert?match_on=sku', [['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]]));
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], UpsertHookSpy::$calls);
    }

    /** @test */
    public function bulk_operation_upsert_with_async_flag_is_refused_when_can_upsert_is_false(): void
    {
        $this->registerTable(canUpsert: false);

        $dedicated = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]);
        $bulk      = $this->postJson('/api/products/bulk?match_on=sku&async=1', [
            'items' => [['operation' => 'upsert', 'sku' => 'SKU-1', 'name' => 'One', 'price' => 10]],
        ]);

        $this->assertSameResponse($dedicated, $bulk);
        $this->assertDatabaseCount('products', 0);
    }

    /** @test */
    public function mixed_bulk_batch_with_an_upsert_item_is_refused_as_a_whole_when_can_upsert_is_false(): void
    {
        $this->registerTable(canUpsert: false);
        $id = $this->seedProduct();

        $dedicated = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]);
        $bulk      = $this->postJson('/api/products/bulk?match_on=sku', [
            'items' => [
                ['sku' => 'SKU-NEW', 'name' => 'Created first', 'price' => 5],
                ['id' => $id, 'name' => 'Updated second'],
                ['operation' => 'upsert', 'sku' => 'SKU-1', 'name' => 'One', 'price' => 10],
            ],
        ]);

        $this->assertSameResponse($dedicated, $bulk);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old']);
        $this->assertSame([], UpsertHookSpy::$calls);
    }

    /** @test */
    public function bulk_without_upsert_items_still_works_when_can_upsert_is_false(): void
    {
        $this->registerTable(canUpsert: false, withTriggers: false);

        $this->postJson('/api/products/bulk', [
            'items' => [['sku' => 'SKU-NEW', 'name' => 'New', 'price' => 5]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('products', ['sku' => 'SKU-NEW']);
    }

    /** @test */
    public function queued_bulk_upsert_job_is_refused_when_can_upsert_is_false(): void
    {
        $this->registerTable(canUpsert: false);

        $job = new ProcessBulkOperationJob('upsert', 'products', [['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]], null);

        try {
            $job->handle(app(RecordService::class));
            $this->fail('The queued upsert should have been refused');
        } catch (RecordNotFoundException $exception) {
            $this->assertSame('Resource not available', $exception->getMessage());
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], UpsertHookSpy::$calls);
    }

    // -----------------------------------------------------------------------
    // POST {table}/upsert runs the create or update before-hooks and validators
    // -----------------------------------------------------------------------

    /** @test */
    public function upsert_of_a_new_row_runs_before_create_with_the_create_signature(): void
    {
        $this->registerTable();

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10])
            ->assertStatus(200);

        $this->assertSame(['beforeCreate', 'afterUpdate'], UpsertHookSpy::hooks());
        [$table, $context] = UpsertHookSpy::argsOf('beforeCreate');
        $this->assertSame('products', $table);
        $this->assertArrayHasKey(RecordConfigService::tenantColumn(), $context);
        $this->assertArrayNotHasKey('id', $context);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-1', 'name' => 'One']);
    }

    /** @test */
    public function upsert_of_an_existing_row_runs_before_update_with_the_existing_id(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-1');

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'Renamed', 'price' => 10])
            ->assertStatus(200);

        $this->assertSame(['beforeUpdate', 'afterUpdate'], UpsertHookSpy::hooks());
        [$table, $context] = UpsertHookSpy::argsOf('beforeUpdate');
        $this->assertSame('products', $table);
        $this->assertSame((string) $id, $context['id']);
        $this->assertArrayHasKey(RecordConfigService::tenantColumn(), $context);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Renamed']);
    }

    /** @test */
    public function upsert_insert_rejected_by_before_create_is_refused_like_create(): void
    {
        $this->registerTable();
        $this->seedProduct();
        UpsertHookSpy::$createLimit = 1;

        $create = $this->postJson('/api/products', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]);
        $create->assertStatus(422);

        $upsert = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10]);

        $this->assertSameResponse($create, $upsert);
        $this->assertDatabaseCount('products', 1);
        $this->assertNotContains('afterUpdate', UpsertHookSpy::hooks());
    }

    /** @test */
    public function upsert_update_rejected_by_before_update_is_refused_like_update(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-1');
        UpsertHookSpy::$rejectUpdate = true;

        $update = $this->putJson('/api/products/' . $id, ['name' => 'Renamed']);
        $update->assertStatus(422);

        $upsert = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'Renamed', 'price' => 10]);

        $this->assertSameResponse($update, $upsert);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old', 'price' => 50]);
    }

    /** @test */
    public function upsert_update_aborted_by_a_throwing_before_update_is_refused_like_update(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-1');
        UpsertHookSpy::$throwUpdate = true;

        $update = $this->putJson('/api/products/' . $id, ['name' => 'Renamed']);
        $update->assertStatus(500);

        $upsert = $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'Renamed', 'price' => 10]);

        $this->assertSameResponse($update, $upsert);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old']);
    }

    /** @test */
    public function upsert_runs_the_create_validator_for_a_new_row_and_the_update_validator_for_an_existing_row(): void
    {
        $this->registerTable(withTriggers: false, extra: [
            'createValidator' => fn (Request $request, ?string $id = null): ValidatorContract => Validator::make($request->all(), [
                'price' => 'required|integer|min:1',
            ]),
            'updateValidator' => fn (Request $request, ?string $id = null): ValidatorContract => Validator::make($request->all(), [
                'name' => 'string|max:5',
            ]),
        ]);
        $id = $this->seedProduct('SKU-OLD');

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
        $this->assertDatabaseMissing('products', ['sku' => 'SKU-1']);

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-OLD', 'name' => 'Far too long', 'price' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old', 'price' => 50]);

        // The update validator does not require a price, so an update with price 0 passes.
        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-OLD', 'name' => 'New', 'price' => 0])
            ->assertStatus(200);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'New', 'price' => 0]);
    }

    /** @test */
    public function upsert_runs_global_before_hooks(): void
    {
        $this->registerTable(withTriggers: false);
        Config::set('record.global_triggers', [
            'beforeCreate' => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'beforeCreate'),
            'beforeUpdate' => new RecordTableTriggerType(class: UpsertHookSpy::class, functionName: 'beforeUpdate'),
        ]);
        $this->seedProduct('SKU-OLD');

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10])->assertStatus(200);
        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-OLD', 'name' => 'Two', 'price' => 10])->assertStatus(200);

        $this->assertSame(['beforeCreate', 'beforeUpdate'], UpsertHookSpy::hooks());
    }

    // -----------------------------------------------------------------------
    // POST {table}/bulk/upsert runs the bulk create or update before-hooks per row
    // -----------------------------------------------------------------------

    /** @test */
    public function bulk_upsert_runs_before_create_for_new_rows_and_before_update_for_existing_rows(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-OLD');

        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-OLD', 'name' => 'Updated', 'price' => 60],
            ['sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70],
        ])->assertStatus(200);

        $this->assertSame(['beforeUpdate', 'beforeCreate'], UpsertHookSpy::hooks());

        [$table, $updateId, $updateItem] = UpsertHookSpy::argsOf('beforeUpdate');
        $this->assertSame('products', $table);
        $this->assertSame($id, (int) $updateId);
        $this->assertSame('Updated', $updateItem['name']);

        [$table, $createItem] = UpsertHookSpy::argsOf('beforeCreate');
        $this->assertSame('products', $table);
        $this->assertSame('SKU-NEW', $createItem['sku']);

        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Updated']);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-NEW', 'name' => 'Inserted']);
    }

    /** @test */
    public function bulk_upsert_rejected_by_before_create_is_refused_like_bulk_create_and_writes_nothing(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-OLD');
        UpsertHookSpy::$createLimit = 1;

        $bulkCreate = $this->postJson('/api/products/bulk/create', [['sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70]]);
        $bulkCreate->assertStatus(422);

        $bulkUpsert = $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-OLD', 'name' => 'Updated', 'price' => 60],
            ['sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70],
        ]);

        $this->assertSameResponse($bulkCreate, $bulkUpsert);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old', 'price' => 50]);
    }

    /** @test */
    public function bulk_upsert_before_create_sees_the_rows_inserted_earlier_in_the_batch(): void
    {
        $this->registerTable();
        UpsertHookSpy::$createLimit = 2;

        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-1', 'name' => 'One', 'price' => 10],
            ['sku' => 'SKU-2', 'name' => 'Two', 'price' => 20],
            ['sku' => 'SKU-3', 'name' => 'Three', 'price' => 30],
        ])->assertStatus(422);

        $this->assertDatabaseCount('products', 0);
    }

    /** @test */
    public function bulk_upsert_runs_default_validation_like_bulk_create(): void
    {
        $this->registerTable(withTriggers: false);
        Config::set('record.default_validation', ['enabled' => true]);
        SchemaRegistryUtils::refresh();

        $bulkCreate = $this->postJson('/api/products/bulk/create', [['sku' => 'SKU-1', 'name' => 'One']]);
        $bulkCreate->assertStatus(422);

        $bulkUpsert = $this->postJson('/api/products/bulk/upsert?match_on=sku', [['sku' => 'SKU-1', 'name' => 'One']]);

        $this->assertSameResponse($bulkCreate, $bulkUpsert);
        $this->assertDatabaseCount('products', 0);
    }

    // -----------------------------------------------------------------------
    // POST {table}/bulk with operation: upsert runs the bulk before-triggers
    // -----------------------------------------------------------------------

    /** @test */
    public function bulk_operation_upsert_runs_before_create_or_before_update_per_item(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-OLD');

        $this->postJson('/api/products/bulk?match_on=sku', [
            'items' => [
                ['operation' => 'upsert', 'sku' => 'SKU-OLD', 'name' => 'Updated', 'price' => 60],
                ['operation' => 'upsert', 'sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70],
            ],
        ])->assertStatus(200);

        $this->assertSame(['beforeUpdate', 'afterUpdate', 'beforeCreate', 'afterUpdate'], UpsertHookSpy::hooks());

        [$table, $updateId, $updateItem] = UpsertHookSpy::argsOf('beforeUpdate');
        $this->assertSame('products', $table);
        $this->assertSame($id, (int) $updateId);
        $this->assertSame('Updated', $updateItem['name']);

        [$table, $createItem] = UpsertHookSpy::argsOf('beforeCreate');
        $this->assertSame('products', $table);
        $this->assertSame('SKU-NEW', $createItem['sku']);
    }

    /** @test */
    public function bulk_operation_upsert_rejected_by_a_before_trigger_rolls_back_the_batch(): void
    {
        $this->registerTable();
        $id = $this->seedProduct('SKU-OLD');
        UpsertHookSpy::$createLimit = 1;

        $create = $this->postJson('/api/products/bulk', [
            'items' => [['sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70]],
        ]);
        $create->assertStatus(422);

        $upsert = $this->postJson('/api/products/bulk?match_on=sku', [
            'items' => [
                ['operation' => 'upsert', 'sku' => 'SKU-OLD', 'name' => 'Updated', 'price' => 60],
                ['operation' => 'upsert', 'sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 70],
            ],
        ]);

        $this->assertSameResponse($create, $upsert);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $id, 'name' => 'Old', 'price' => 50]);
    }

    // -----------------------------------------------------------------------
    // Regression: tables without triggers behave as before
    // -----------------------------------------------------------------------

    /** @test */
    public function upsert_payloads_are_unchanged_for_tables_without_triggers(): void
    {
        $this->registerTable(withTriggers: false);
        $id = $this->seedProduct('SKU-OLD');

        $this->postJson('/api/products/upsert?match_on=sku', ['sku' => 'SKU-OLD', 'name' => 'Renamed', 'price' => 10])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.payload.sku', 'SKU-OLD')
            ->assertJsonPath('data.payload.name', 'Renamed');

        $this->postJson('/api/products/bulk/upsert?match_on=sku', [
            ['sku' => 'SKU-OLD', 'name' => 'Again', 'price' => 11],
            ['sku' => 'SKU-NEW', 'name' => 'Inserted', 'price' => 12],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.count', 2);

        $this->postJson('/api/products/bulk?match_on=sku', [
            'items' => [['operation' => 'upsert', 'sku' => 'SKU-NEW', 'name' => 'Third', 'price' => 13]],
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.0.sku', 'SKU-NEW')
            ->assertJsonPath('data.0.name', 'Third')
            ->assertJsonPath('meta.affected', 1);

        $this->assertDatabaseCount('products', 2);
    }
}

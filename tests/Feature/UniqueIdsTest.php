<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mylekha\RecordApi\Services\RecordService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordHasManyThroughType;
use Mylekha\RecordApi\Types\RecordHasManyType;
use Mylekha\RecordApi\Types\RecordMetaBelongsToManyType;
use Mylekha\RecordApi\Types\RecordTablePublic;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\RelationshipResolverUtils;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

/**
 * Columns listed in a table's `uniqueIds` are server-generated UUIDv7 values: every insert the engine
 * performs sets a fresh one (ignoring any client value) and no update ever changes it.
 */
class UniqueIdsTest extends TestCase
{
    private const CLIENT_UUID = '00000000-0000-4000-8000-000000000000';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $public = new RecordTablePublic(read: true, write: true);
        $app['config']->set('record.tables', [
            'shops' => new RecordTableType(
                table: 'shops',
                public: $public,
                columns: ['id' => [], 'uuid' => [], 'sku' => [], 'name' => [], 'created_at' => [], 'updated_at' => []],
                relationships: [
                    'branches' => new RecordHasManyType(table: 'branches', foreignKey: 'shop_id'),
                    'labels' => new RecordMetaBelongsToManyType(related: 'labels', table: 'shop_labels', foreignPivotKey: 'shop_id', relatedPivotKey: 'label_id'),
                    'docs' => new RecordHasManyThroughType(table: 'docs', through: 'shop_docs', firstKey: 'shop_id', secondLocalKey: 'doc_id'),
                ],
                uniqueIds: ['uuid'],
            ),
            'branches' => new RecordTableType(
                table: 'branches',
                public: $public,
                columns: ['id' => [], 'uuid' => [], 'shop_id' => [], 'name' => []],
                uniqueIds: ['uuid'],
            ),
            'labels' => new RecordTableType(
                table: 'labels',
                public: $public,
                columns: ['id' => [], 'uuid' => [], 'name' => []],
                uniqueIds: ['uuid'],
            ),
            'docs' => new RecordTableType(
                table: 'docs',
                public: $public,
                columns: ['id' => [], 'uuid' => [], 'name' => []],
                uniqueIds: ['uuid'],
            ),
            'notes' => new RecordTableType(
                table: 'notes',
                public: $public,
                columns: ['id' => [], 'uuid' => [], 'name' => [], 'created_at' => [], 'updated_at' => []],
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('shops', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->string('sku')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->unsignedBigInteger('shop_id');
            $t->string('name');
        });
        Schema::create('labels', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('name');
        });
        Schema::create('shop_labels', function (Blueprint $t): void {
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('label_id');
        });
        Schema::create('docs', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('name');
        });
        Schema::create('shop_docs', function (Blueprint $t): void {
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('doc_id');
        });
        Schema::create('notes', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('name');
            $t->timestamps();
        });

        SchemaRegistryUtils::refresh();
    }

    private function assertUuidV7(mixed $value): void
    {
        $this->assertIsString($value);
        $this->assertTrue(Str::isUuid($value), 'Not a UUID: ' . $value);
        $this->assertSame('7', $value[14], 'Not a version-7 UUID: ' . $value);
        $this->assertSame(strtolower($value), $value);
    }

    private function shopUuid(string $sku): ?string
    {
        return DB::table('shops')->where('sku', $sku)->value('uuid');
    }

    private function seedShop(string $sku, string $uuid): int
    {
        return DB::table('shops')->insertGetId([
            'uuid' => $uuid,
            'sku' => $sku,
            'name' => 'seeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @test */
    public function the_option_defaults_to_empty_and_survives_config_caching(): void
    {
        $this->assertSame([], (new RecordTableType())->uniqueIds);
        $this->assertSame(['uuid'], RecordTableType::__set_state(['uniqueIds' => ['uuid']])->uniqueIds);
        $this->assertSame([], RecordTableType::__set_state([])->uniqueIds);
    }

    /** @test */
    public function create_sets_a_v7_uuid(): void
    {
        $this->postJson('/api/shops', ['sku' => 'S1', 'name' => 'Shop'])->assertSuccessful();

        $this->assertUuidV7($this->shopUuid('S1'));
    }

    /** @test */
    public function create_ignores_a_client_supplied_uuid(): void
    {
        $this->postJson('/api/shops', ['sku' => 'S1', 'name' => 'Shop', 'uuid' => self::CLIENT_UUID])->assertSuccessful();

        $uuid = $this->shopUuid('S1');
        $this->assertNotSame(self::CLIENT_UUID, $uuid);
        $this->assertUuidV7($uuid);
    }

    /** @test */
    public function update_never_changes_the_uuid(): void
    {
        $original = (string) Str::uuid7();
        $id = $this->seedShop('S1', $original);

        $this->putJson('/api/shops/' . $id, ['name' => 'Renamed', 'uuid' => self::CLIENT_UUID])->assertSuccessful();

        $row = DB::table('shops')->where('id', $id)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame($original, $row->uuid);
    }

    /** @test */
    public function update_with_only_a_uuid_changes_nothing(): void
    {
        $original = (string) Str::uuid7();
        $id = $this->seedShop('S1', $original);

        $this->putJson('/api/shops/' . $id, ['uuid' => self::CLIENT_UUID]);

        $this->assertSame($original, DB::table('shops')->where('id', $id)->value('uuid'));
    }

    /** @test */
    public function bulk_update_never_changes_the_uuid(): void
    {
        $original = (string) Str::uuid7();
        $id = $this->seedShop('S1', $original);

        $this->postJson('/api/shops/bulk/update', [['id' => $id, 'name' => 'Renamed', 'uuid' => self::CLIENT_UUID]])->assertSuccessful();

        $row = DB::table('shops')->where('id', $id)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame($original, $row->uuid);
    }

    /** @test */
    public function upsert_inserting_a_new_row_sets_a_uuid(): void
    {
        $this->postJson('/api/shops/upsert?match_on=sku', ['sku' => 'S1', 'name' => 'Shop', 'uuid' => self::CLIENT_UUID])->assertSuccessful();

        $uuid = $this->shopUuid('S1');
        $this->assertNotSame(self::CLIENT_UUID, $uuid);
        $this->assertUuidV7($uuid);
    }

    /** @test */
    public function upsert_matching_an_existing_row_keeps_its_uuid(): void
    {
        $original = (string) Str::uuid7();
        $this->seedShop('S1', $original);

        $this->postJson('/api/shops/upsert?match_on=sku', ['sku' => 'S1', 'name' => 'Updated', 'uuid' => self::CLIENT_UUID])->assertSuccessful();

        $this->assertSame(1, DB::table('shops')->count());
        $this->assertSame('Updated', DB::table('shops')->where('sku', 'S1')->value('name'));
        $this->assertSame($original, $this->shopUuid('S1'));
    }

    /** @test */
    public function upsert_result_payload_reports_the_stored_uuid(): void
    {
        $original = (string) Str::uuid7();
        $this->seedShop('S1', $original);

        $result = app(RecordService::class)->upsertRecord(request(), 'shops', ['sku' => 'S1', 'name' => 'Updated'], null, ['sku']);
        $this->assertSame($original, $result['data']['payload']['uuid']);

        $result = app(RecordService::class)->upsertRecord(request(), 'shops', ['sku' => 'S2', 'name' => 'New'], null, ['sku']);
        $this->assertSame($this->shopUuid('S2'), $result['data']['payload']['uuid']);
    }

    /** @test */
    public function bulk_upsert_gives_new_rows_a_uuid_and_keeps_existing_ones(): void
    {
        $original = (string) Str::uuid7();
        $this->seedShop('S1', $original);

        $this->postJson('/api/shops/bulk/upsert?match_on=sku', [
            ['sku' => 'S1', 'name' => 'Updated', 'uuid' => self::CLIENT_UUID],
            ['sku' => 'S2', 'name' => 'New'],
        ])->assertSuccessful();

        $this->assertSame($original, $this->shopUuid('S1'));
        $this->assertUuidV7($this->shopUuid('S2'));
    }

    /** @test */
    public function service_bulk_upsert_gives_new_rows_a_uuid_and_keeps_existing_ones(): void
    {
        $original = (string) Str::uuid7();
        $this->seedShop('S1', $original);

        app(RecordService::class)->bulkUpsertRecord(request(), 'shops', [
            ['sku' => 'S1', 'name' => 'Updated'],
            ['sku' => 'S2', 'name' => 'New'],
            ['sku' => 'S3', 'name' => 'New'],
        ], null, ['sku']);

        $this->assertSame($original, $this->shopUuid('S1'));
        $this->assertUuidV7($this->shopUuid('S2'));
        $this->assertUuidV7($this->shopUuid('S3'));
        $this->assertNotSame($this->shopUuid('S2'), $this->shopUuid('S3'));
    }

    /** @test */
    public function upsert_cannot_match_on_a_unique_id_column(): void
    {
        $original = (string) Str::uuid7();
        $this->seedShop('S1', $original);

        $this->postJson('/api/shops/upsert?match_on=uuid', ['uuid' => $original, 'sku' => 'S2', 'name' => 'x'])->assertStatus(422);

        $this->assertSame(1, DB::table('shops')->count());
    }

    /** @test */
    public function bulk_create_gives_each_row_a_distinct_uuid(): void
    {
        $this->postJson('/api/shops/bulk/create', [
            ['sku' => 'S1', 'name' => 'A', 'uuid' => self::CLIENT_UUID],
            ['sku' => 'S2', 'name' => 'B', 'uuid' => self::CLIENT_UUID],
            ['sku' => 'S3', 'name' => 'C'],
        ])->assertSuccessful();

        $uuids = DB::table('shops')->orderBy('id')->pluck('uuid')->all();
        $this->assertCount(3, $uuids);
        foreach ($uuids as $uuid) {
            $this->assertUuidV7($uuid);
        }

        $this->assertCount(3, array_unique($uuids));
    }

    /** @test */
    public function generic_bulk_endpoint_creates_with_uuids_and_updates_without_changing_them(): void
    {
        $original = (string) Str::uuid7();
        $id = $this->seedShop('S1', $original);

        $this->postJson('/api/shops/bulk', ['items' => [
            ['sku' => 'S2', 'name' => 'New', 'uuid' => self::CLIENT_UUID],
            ['id' => $id, 'name' => 'Renamed', 'uuid' => self::CLIENT_UUID],
        ]])->assertSuccessful();

        $this->assertUuidV7($this->shopUuid('S2'));
        $this->assertNotSame(self::CLIENT_UUID, $this->shopUuid('S2'));
        $this->assertSame($original, $this->shopUuid('S1'));
        $this->assertSame('Renamed', DB::table('shops')->where('id', $id)->value('name'));
    }

    /** @test */
    public function nested_has_many_create_sets_a_uuid_on_the_child(): void
    {
        $this->postJson('/api/shops', [
            'sku' => 'S1',
            'name' => 'Shop',
            'branches' => [['name' => 'Main', 'uuid' => self::CLIENT_UUID]],
        ])->assertSuccessful();

        $branch = DB::table('branches')->first();
        $this->assertNotNull($branch);
        $this->assertUuidV7($branch->uuid);
        $this->assertNotSame($this->shopUuid('S1'), $branch->uuid);
    }

    /** @test */
    public function nested_has_many_update_never_changes_the_child_uuid(): void
    {
        $shopId = $this->seedShop('S1', (string) Str::uuid7());
        $original = (string) Str::uuid7();
        $branchId = DB::table('branches')->insertGetId(['uuid' => $original, 'shop_id' => $shopId, 'name' => 'Main']);

        RelationshipResolverUtils::processRelatedData('shops', [
            'branches' => [['id' => $branchId, 'name' => 'Renamed', 'uuid' => self::CLIENT_UUID]],
        ], $shopId, null, 'update');

        $branch = DB::table('branches')->where('id', $branchId)->first();
        $this->assertSame('Renamed', $branch->name);
        $this->assertSame($original, $branch->uuid);
    }

    /** @test */
    public function nested_belongs_to_many_create_sets_a_uuid_on_the_related_row(): void
    {
        $shopId = $this->seedShop('S1', (string) Str::uuid7());

        RelationshipResolverUtils::processRelatedData('shops', [
            'labels' => [['name' => 'Hot', 'uuid' => self::CLIENT_UUID]],
        ], $shopId, null, 'update');

        $label = DB::table('labels')->first();
        $this->assertNotNull($label);
        $this->assertUuidV7($label->uuid);
        $this->assertSame(1, DB::table('shop_labels')->where('shop_id', $shopId)->where('label_id', $label->id)->count());
    }

    /** @test */
    public function nested_has_many_through_create_sets_a_uuid_on_the_target_row(): void
    {
        $shopId = $this->seedShop('S1', (string) Str::uuid7());

        RelationshipResolverUtils::processRelatedData('shops', [
            'docs' => [['name' => 'Manual', 'uuid' => self::CLIENT_UUID]],
        ], $shopId, null, 'update');

        $doc = DB::table('docs')->first();
        $this->assertNotNull($doc);
        $this->assertUuidV7($doc->uuid);
        $this->assertSame(1, DB::table('shop_docs')->where('shop_id', $shopId)->where('doc_id', $doc->id)->count());
    }

    /** @test */
    public function a_table_without_unique_ids_is_unchanged(): void
    {
        $result = app(RecordService::class)->createRecord('notes', ['name' => 'Plain'], null);
        $this->assertArrayNotHasKey('uuid', $result['payload']);
        $this->assertNull(DB::table('notes')->where('id', $result['id'])->value('uuid'));

        // A client value is still written as before on a table that does not generate the column.
        $this->postJson('/api/notes', ['name' => 'Client', 'uuid' => self::CLIENT_UUID])->assertSuccessful();
        $this->assertSame(self::CLIENT_UUID, DB::table('notes')->where('name', 'Client')->value('uuid'));

        $update = app(RecordService::class)->updateRecord('notes', $result['id'], ['name' => 'Renamed'], null);
        $this->assertArrayNotHasKey('uuid', $update['payload']);
    }
}

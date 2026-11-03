<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordBelongsToType;
use Mylekha\RecordApi\Types\RecordTablePublic;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

/**
 * Behaviour that must be the same on every database driver. Runs on SQLite by default and on PostgreSQL with
 * `composer test:pgsql` (see CONTRIBUTING.md).
 */
class PortableDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const WIDE_COLUMNS = 60;

    protected function setUp(): void
    {
        parent::setUp();

        // 60 data columns + id: more than json_build_object() accepts on PostgreSQL (50 key/value pairs).
        Schema::create('wide_stores', function (Blueprint $table): void {
            $table->id();
            for ($i = 1; $i <= self::WIDE_COLUMNS; ++$i) {
                $table->string('col_' . $i)->nullable();
            }
        });

        Schema::create('store_links', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('wide_store_id');
        });

        // No unique key besides the primary key (an upsert on it always inserts on MySQL).
        Schema::create('journal', function (Blueprint $table): void {
            $table->id();
            $table->string('reference');
            $table->integer('total');
            $table->timestamps();
        });

        // A composite unique key that `match_on=user_id` does not name exactly.
        Schema::create('profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('body');
        });

        $public = new RecordTablePublic(read: true, write: true);

        Config::set('record.tables', [
            'wide_stores' => new RecordTableType(table: 'wide_stores', public: $public),
            'store_links' => new RecordTableType(
                table: 'store_links',
                public: $public,
                relationships: [
                    'wide_store' => new RecordBelongsToType(table: 'wide_stores', foreignKey: 'wide_store_id', ownerKey: 'id'),
                ],
            ),
            'journal' => new RecordTableType(table: 'journal', canUpsert: true, public: $public),
            'profiles' => new RecordTableType(table: 'profiles', canUpsert: true, public: $public),
            'notes' => new RecordTableType(table: 'notes', public: $public, columnIndexes: [['title', 'body']]),
        ]);

        SchemaRegistryUtils::refresh();
    }

    private function wideStore(): int
    {
        $row = [];
        for ($i = 1; $i <= self::WIDE_COLUMNS; ++$i) {
            $row['col_' . $i] = 'v' . $i;
        }

        return (int) DB::table('wide_stores')->insertGetId($row);
    }

    /** @test */
    public function it_includes_a_related_row_with_more_than_fifty_columns(): void
    {
        $storeId = $this->wideStore();
        $linkId = (int) DB::table('store_links')->insertGetId(['name' => 'link', 'wide_store_id' => $storeId]);

        $single = $this->getJson(sprintf('/api/store_links/%d?select=*,wide_store:wide_stores(*)', $linkId))->assertOk();
        $this->assertSame('v1', $single->json('data.wide_store.col_1'));
        $this->assertSame('v60', $single->json('data.wide_store.col_60'));
        $this->assertSame($storeId, (int) $single->json('data.wide_store.id'));

        $list = $this->getJson('/api/store_links?select=*,wide_store:wide_stores(*)')->assertOk();
        $this->assertSame('v60', $list->json('data.0.wide_store.col_60'));
    }

    /** @test */
    public function it_searches_a_table_with_a_full_text_index_definition_on_any_driver(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL searches with MATCH … AGAINST, which needs a real FULLTEXT index.');
        }

        DB::table('notes')->insert([['title' => 'Coffee beans', 'body' => 'dark roast'], ['title' => 'Tea', 'body' => 'green']]);

        $response = $this->getJson('/api/notes?s=roast')->assertOk();

        $this->assertSame(['Coffee beans'], array_column($response->json('data'), 'title'));
    }

    /** @test */
    public function an_upsert_matched_on_a_column_without_a_unique_key_inserts_every_time(): void
    {
        $this->postJson('/api/journal/upsert?match_on=reference', ['reference' => 'R-1', 'total' => 10])->assertOk();
        $this->postJson('/api/journal/upsert?match_on=reference', ['reference' => 'R-1', 'total' => 20])->assertOk();
        $this->postJson('/api/journal/bulk/upsert?match_on=reference', [['reference' => 'R-2', 'total' => 30]])->assertOk();

        $this->assertSame([10, 20, 30], DB::table('journal')->orderBy('id')->pluck('total')->map(fn($v): int => (int) $v)->all());
    }

    /** @test */
    public function an_upsert_updates_the_row_it_collides_with_on_a_composite_unique_key(): void
    {
        DB::table('profiles')->insert(['company_id' => 1, 'user_id' => 7, 'title' => 'Old']);

        $this->postJson('/api/profiles/upsert?match_on=user_id', ['company_id' => 1, 'user_id' => 7, 'title' => 'New'])->assertOk();
        $this->postJson('/api/profiles/bulk/upsert?match_on=user_id', [
            ['company_id' => 1, 'user_id' => 7, 'title' => 'Bulk'],
            ['company_id' => 2, 'user_id' => 7, 'title' => 'Other company'],
        ])->assertOk();

        $this->assertSame(
            [['company_id' => 1, 'title' => 'Bulk'], ['company_id' => 2, 'title' => 'Other company']],
            DB::table('profiles')->orderBy('company_id')->get(['company_id', 'title'])
                ->map(fn($row): array => ['company_id' => (int) $row->company_id, 'title' => $row->title])->all()
        );
    }

    /** @test */
    public function an_upsert_matched_on_a_unique_key_still_uses_the_native_upsert(): void
    {
        DB::table('profiles')->insert(['company_id' => 1, 'user_id' => 7, 'title' => 'Old']);

        $this->postJson('/api/profiles/upsert?match_on=company_id,user_id', ['company_id' => 1, 'user_id' => 7, 'title' => 'New'])->assertOk();

        $this->assertSame(['New'], DB::table('profiles')->pluck('title')->all());
    }
}

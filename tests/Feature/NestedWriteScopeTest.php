<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordHasManyType;
use Mylekha\RecordApi\Types\RecordMetaBelongsToManyType;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\RelationshipResolverUtils;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

/**
 * Nested writes (PUT /items/{id} with "parts": [...] or "tags": [...]) must stay inside the parent
 * record and the caller's company. They used to update/delete a child by its id alone, and attach any
 * existing related id, so a caller could change or link another company's rows by guessing ids.
 */
class NestedWriteScopeTest extends TestCase
{
    private const MINE = 7;
    private const OTHER = 8;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
        $app['config']->set('record.tenant_column', 'company_id');
        $app['config']->set('record.tables', [
            'items' => new RecordTableType(
                table: 'items',
                hasTenantId: true,
                columns: ['id' => [], 'name' => [], 'company_id' => []],
                relationships: [
                    'parts' => new RecordHasManyType(table: 'item_parts', foreignKey: 'item_id'),
                    'tags' => new RecordMetaBelongsToManyType(related: 'tags', table: 'item_tags', foreignPivotKey: 'item_id', relatedPivotKey: 'tag_id'),
                ],
            ),
            'item_parts' => new RecordTableType(
                table: 'item_parts',
                hasTenantId: true,
                columns: ['id' => [], 'item_id' => [], 'name' => [], 'company_id' => []],
            ),
            'tags' => new RecordTableType(
                table: 'tags',
                hasTenantId: true,
                columns: ['id' => [], 'name' => [], 'company_id' => []],
            ),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('company_id');
        });
        Schema::create('item_parts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('item_id');
            $t->string('name');
            $t->unsignedBigInteger('company_id');
        });
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('company_id');
        });
        Schema::create('item_tags', function (Blueprint $t) {
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('tag_id');
        });
        SchemaRegistryUtils::refresh();
    }

    private function item(int $company): int
    {
        return DB::table('items')->insertGetId(['name' => 'item', 'company_id' => $company]);
    }

    private function part(int $itemId, int $company, string $name = 'part'): int
    {
        return DB::table('item_parts')->insertGetId(['item_id' => $itemId, 'name' => $name, 'company_id' => $company]);
    }

    private function write(int $itemId, array $payload): void
    {
        RelationshipResolverUtils::processRelatedData('items', $payload, $itemId, self::MINE, 'update');
    }

    /** @test */
    public function it_updates_its_own_child_row(): void
    {
        $mine = $this->item(self::MINE);
        $part = $this->part($mine, self::MINE);

        $this->write($mine, ['parts' => [['id' => $part, 'name' => 'renamed']]]);

        $this->assertSame('renamed', DB::table('item_parts')->where('id', $part)->value('name'));
    }

    /** @test */
    public function it_does_not_update_another_companys_child_row(): void
    {
        $mine = $this->item(self::MINE);
        $foreignPart = $this->part($this->item(self::OTHER), self::OTHER, 'theirs');

        $this->write($mine, ['parts' => [['id' => $foreignPart, 'name' => 'hacked']]]);

        $row = DB::table('item_parts')->where('id', $foreignPart)->first();
        $this->assertSame('theirs', $row->name);
        $this->assertSame(self::OTHER, (int) $row->company_id);
    }

    /** @test */
    public function it_does_not_move_a_child_row_of_another_parent(): void
    {
        $mine = $this->item(self::MINE);
        $otherItem = $this->item(self::MINE);
        $part = $this->part($otherItem, self::MINE, 'belongs elsewhere');

        $this->write($mine, ['parts' => [['id' => $part, 'name' => 'moved']]]);

        $this->assertSame($otherItem, (int) DB::table('item_parts')->where('id', $part)->value('item_id'));
    }

    /** @test */
    public function it_does_not_delete_another_companys_child_row(): void
    {
        $mine = $this->item(self::MINE);
        $foreignPart = $this->part($this->item(self::OTHER), self::OTHER);

        $this->write($mine, ['parts' => [['id' => $foreignPart, '_delete' => true]]]);

        $this->assertTrue(DB::table('item_parts')->where('id', $foreignPart)->exists());
    }

    /** @test */
    public function it_deletes_its_own_child_row(): void
    {
        $mine = $this->item(self::MINE);
        $part = $this->part($mine, self::MINE);

        $this->write($mine, ['parts' => [['id' => $part, '_delete' => true]]]);

        $this->assertFalse(DB::table('item_parts')->where('id', $part)->exists());
    }

    /** @test */
    public function it_attaches_its_own_company_tag(): void
    {
        $mine = $this->item(self::MINE);
        $tag = DB::table('tags')->insertGetId(['name' => 'mine', 'company_id' => self::MINE]);

        $this->write($mine, ['tags' => [['id' => $tag]]]);

        $this->assertTrue(DB::table('item_tags')->where(['item_id' => $mine, 'tag_id' => $tag])->exists());
    }

    /** @test */
    public function it_does_not_attach_another_companys_tag(): void
    {
        $mine = $this->item(self::MINE);
        $foreignTag = DB::table('tags')->insertGetId(['name' => 'theirs', 'company_id' => self::OTHER]);

        $this->write($mine, ['tags' => [['id' => $foreignTag]]]);

        $this->assertFalse(DB::table('item_tags')->where(['item_id' => $mine, 'tag_id' => $foreignTag])->exists());
    }
}

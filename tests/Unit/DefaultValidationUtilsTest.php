<?php

namespace Mylekha\RecordApi\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\DefaultValidationUtils;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

class DefaultValidationUtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('validation_parents')) {
            Schema::create('validation_parents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code')->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('validation_children')) {
            Schema::create('validation_children', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('parent_id');
                $table->string('name');
                $table->timestamps();
                $table->foreign('parent_id')->references('id')->on('validation_parents');
            });
        }

        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_builds_rules_with_unique_and_foreign_key_constraints(): void
    {
        $table = new RecordTableType(table: 'validation_children', primaryKey: 'id');
        $table->columns = SchemaRegistryUtils::getTableColumns('validation_children');

        $rules = DefaultValidationUtils::buildCreateRules($table);

        $this->assertArrayHasKey('parent_id', $rules);
        $this->assertStringContainsString('exists:validation_parents,id', $rules['parent_id']);
        $this->assertArrayHasKey('name', $rules);
    }

    /** @test */
    public function it_skips_server_generated_unique_id_columns(): void
    {
        if (!Schema::hasTable('validation_tokens')) {
            Schema::create('validation_tokens', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }

        $table = new RecordTableType(table: 'validation_tokens', uniqueIds: ['uuid']);
        $table->columns = SchemaRegistryUtils::getTableColumns('validation_tokens');

        $this->assertArrayNotHasKey('uuid', DefaultValidationUtils::buildCreateRules($table));
        $this->assertArrayNotHasKey('uuid', DefaultValidationUtils::buildUpdateRules($table, 1));
        $this->assertArrayHasKey('name', DefaultValidationUtils::buildCreateRules($table));

        $plain = new RecordTableType(table: 'validation_tokens');
        $plain->columns = SchemaRegistryUtils::getTableColumns('validation_tokens');
        $this->assertArrayHasKey('uuid', DefaultValidationUtils::buildCreateRules($plain));
    }

    /** @test */
    public function it_enforces_default_validation_when_enabled(): void
    {
        Config::set('record.default_validation.enabled', true);
        Config::set('record.default_validation.only_when_missing', true);
        Config::set('record.default_validation.unique', true);
        Config::set('record.default_validation.foreign_keys', true);

        Config::set('record.tables', [
            'validation_children' => new RecordTableType(
                table: 'validation_children',
                primaryKey: 'id'
            ),
        ]);

        SchemaRegistryUtils::refresh();

        $request = Request::create('/api/validation_children', 'POST', [
            'name' => 'Item A',
            'parent_id' => 'not-a-uuid',
        ]);

        $rules = DefaultValidationUtils::buildCreateRules(SchemaRegistryUtils::getTable('validation_children'));
        $this->assertArrayHasKey('parent_id', $rules);
        $this->assertStringContainsString('exists:validation_parents,id', $rules['parent_id']);

        $this->expectException(ValidationException::class);
        $validator = Validator::make($request->all(), $rules);
        $validator->validate();
    }
}

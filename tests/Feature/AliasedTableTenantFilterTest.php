<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mylekha\RecordApi\Services\RecordService;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Types\RecordTableType;
use Mylekha\RecordApi\Utilities\SchemaRegistryUtils;

/**
 * A table config whose route key differs from its physical table ('leave_types' → 'hrms_leave_types')
 * used to get its tenant filter qualified with the route key: `where leave_types.company_id = ?` on a
 * query against hrms_leave_types, so fetch/update/delete/restore failed with "Unknown column".
 */
class AliasedTableTenantFilterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('record.enable_tenant_id', true);
        $app['config']->set('record.tenant_column', 'company_id');
        $app['config']->set('record.tables', [
            'leave_types' => new RecordTableType(table: 'hrms_leave_types', hasTenantId: true),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('hrms_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('company_id');
        });
        SchemaRegistryUtils::refresh();
    }

    /** @test */
    public function it_finds_a_row_of_an_aliased_table_in_the_callers_company(): void
    {
        $id = DB::table('hrms_leave_types')->insertGetId(['name' => 'Annual', 'company_id' => 7]);

        $row = (new RecordService())->fetchRawRecord('leave_types', $id, 7);

        $this->assertSame('Annual', $row?->name);
    }

    /** @test */
    public function it_does_not_find_another_companys_row_of_an_aliased_table(): void
    {
        $id = DB::table('hrms_leave_types')->insertGetId(['name' => 'Annual', 'company_id' => 7]);

        $this->assertNull((new RecordService())->fetchRawRecord('leave_types', $id, 8));
    }
}

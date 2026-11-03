<?php

namespace Mylekha\RecordApi\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Mylekha\RecordApi\Tests\TestCase;
use Mylekha\RecordApi\Utilities\RelationshipResolverUtils;
use ReflectionMethod;

/**
 * The SQL text of the JSON object expressions per driver. Only the driver name is read, so no server is needed.
 */
class PostgresSqlTextTest extends TestCase
{
    private ?string $previousDefault = null;

    private function useDriver(string $driver): void
    {
        $this->previousDefault ??= Config::get('database.default');
        Config::set('database.connections.text_only', ['driver' => $driver, 'database' => 'unused', 'prefix' => '']);
        Config::set('database.default', 'text_only');
        DB::purge('text_only');
    }

    protected function tearDown(): void
    {
        if (null !== $this->previousDefault) {
            Config::set('database.default', $this->previousDefault);
        }

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function pairs(int $count): array
    {
        return array_map(fn(int $i): string => sprintf("'c%d', stores.c%d", $i, $i), range(1, $count));
    }

    private function objectExpression(array $pairs): string
    {
        $method = new ReflectionMethod(RelationshipResolverUtils::class, 'buildJsonObjectExpression');

        return $method->invoke(null, $pairs);
    }

    private function arrayAggExpression(array $pairs): string
    {
        $method = new ReflectionMethod(RelationshipResolverUtils::class, 'buildJsonArrayAggExpression');

        return $method->invoke(null, $pairs);
    }

    /** @test */
    public function mysql_json_object_text_is_one_call_whatever_the_column_count(): void
    {
        $this->useDriver('mysql');

        $this->assertSame("JSON_OBJECT('c1', stores.c1, 'c2', stores.c2)", $this->objectExpression($this->pairs(2)));
        $this->assertSame('JSON_OBJECT(' . implode(', ', $this->pairs(80)) . ')', $this->objectExpression($this->pairs(80)));
        $this->assertSame('JSON_ARRAYAGG(JSON_OBJECT(' . implode(', ', $this->pairs(80)) . '))', $this->arrayAggExpression($this->pairs(80)));
    }

    /** @test */
    public function pgsql_keeps_json_build_object_up_to_fifty_pairs(): void
    {
        $this->useDriver('pgsql');

        $this->assertSame('json_build_object(' . implode(', ', $this->pairs(50)) . ')', $this->objectExpression($this->pairs(50)));
        $this->assertSame('json_agg(json_build_object(' . implode(', ', $this->pairs(3)) . '))', $this->arrayAggExpression($this->pairs(3)));
    }

    /** @test */
    public function pgsql_chunks_more_than_fifty_pairs_into_merged_jsonb_objects(): void
    {
        $this->useDriver('pgsql');
        $pairs = $this->pairs(120);

        $expected = sprintf(
            '(jsonb_build_object(%s) || jsonb_build_object(%s) || jsonb_build_object(%s))::json',
            implode(', ', array_slice($pairs, 0, 50)),
            implode(', ', array_slice($pairs, 50, 50)),
            implode(', ', array_slice($pairs, 100))
        );

        $this->assertSame($expected, $this->objectExpression($pairs));
        $this->assertSame('json_agg(' . $expected . ')', $this->arrayAggExpression($pairs));
    }
}

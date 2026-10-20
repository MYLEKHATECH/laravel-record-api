<?php

declare(strict_types=1);

namespace Mylekha\RecordApi\Utilities;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server-generated unique-id columns (`RecordTableType::$uniqueIds`).
 *
 * Every insert the engine performs fills each listed column with a fresh UUIDv7, overriding any
 * client value; no update ever writes it. Tables without `uniqueIds` are left untouched.
 */
class UniqueIdUtils
{
    /**
     * The unique-id columns of a table schema (empty when it has none or no schema is known).
     *
     * @return list<string>
     */
    public static function columns(?object $tableSchema): array
    {
        $columns = $tableSchema->uniqueIds ?? [];

        return is_array($columns) ? array_values(array_filter($columns, is_string(...))) : [];
    }

    /**
     * Remove the unique-id columns from a payload, so a client value is never written.
     */
    public static function strip(array $payload, ?object $tableSchema): array
    {
        foreach (self::columns($tableSchema) as $column) {
            unset($payload[$column]);
        }

        return $payload;
    }

    /**
     * Set every unique-id column of a payload that is about to be inserted to a fresh UUIDv7.
     */
    public static function fill(array $payload, ?object $tableSchema): array
    {
        foreach (self::columns($tableSchema) as $column) {
            $payload[$column] = (string) Str::uuid7();
        }

        return $payload;
    }

    /**
     * Drop the unique-id columns from an upsert's update-column list, so a matched row keeps its value.
     *
     * @param  list<string> $columns
     * @return list<string>
     */
    public static function withoutColumns(array $columns, ?object $tableSchema): array
    {
        return array_values(array_diff($columns, self::columns($tableSchema)));
    }

    /**
     * An upsert cannot match on a unique-id column: the client value is never written, so every
     * such upsert would insert a new row instead of finding the existing one.
     *
     * @param list<string> $matchOn
     *
     * @throws ValidationException
     */
    public static function assertNotMatchedOn(array $matchOn, ?object $tableSchema): void
    {
        $conflict = array_values(array_intersect($matchOn, self::columns($tableSchema)));
        if ([] !== $conflict) {
            throw ValidationException::withMessages([
                'match_on' => sprintf('match_on cannot use the server-generated column(s): %s', implode(', ', $conflict)),
            ]);
        }
    }
}

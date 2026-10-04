<?php

namespace Mylekha\RecordApi\Interfaces;

use Mylekha\RecordApi\Types\RecordTableType;

interface RecordResourceInterface
{
    /**
     * Convert the resource definition to a RecordTableType.
     */
    public function toTableType(): RecordTableType;
}

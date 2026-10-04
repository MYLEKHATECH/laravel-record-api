<?php

namespace Mylekha\RecordApi\Resources;

use Mylekha\RecordApi\Interfaces\RecordResourceInterface;
use Mylekha\RecordApi\Types\RecordTableType;

abstract class RecordResource implements RecordResourceInterface
{
    /**
     * Define the table configuration.
     */
    abstract public function configure(): RecordTableType;

    /**
     * Convert the resource definition to a RecordTableType.
     */
    public function toTableType(): RecordTableType
    {
        return $this->configure();
    }
}

<?php

namespace Mylekha\RecordApi\Interfaces;

use Mylekha\RecordApi\Types\RecordFunctionType;

interface RecordFunctionInterface
{
    /**
     * Convert the resource definition to a RecordFunctionType.
     */
    public function toFunctionType(): RecordFunctionType;
}

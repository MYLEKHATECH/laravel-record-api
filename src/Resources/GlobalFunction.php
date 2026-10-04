<?php

namespace Mylekha\RecordApi\Resources;

use Mylekha\RecordApi\Interfaces\RecordFunctionInterface;
use Mylekha\RecordApi\Types\RecordFunctionType;

abstract class GlobalFunction implements RecordFunctionInterface
{
    /**
     * Define the function configuration.
     */
    abstract public function configure(): RecordFunctionType;

    public function toFunctionType(): RecordFunctionType
    {
        return $this->configure();
    }
}

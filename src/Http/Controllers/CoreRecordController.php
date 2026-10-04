<?php

namespace Mylekha\RecordApi\Http\Controllers;

use Illuminate\Routing\Controller;
use Mylekha\RecordApi\Http\Controllers\Concerns\HasBulkOperations;
use Mylekha\RecordApi\Http\Controllers\Concerns\HasControllerHelpers;
use Mylekha\RecordApi\Http\Controllers\Concerns\HasCrudOperations;
use Mylekha\RecordApi\Http\Controllers\Concerns\HasFunctionOperations;
use Mylekha\RecordApi\Services\RecordService;

class CoreRecordController extends Controller
{
    use HasControllerHelpers;
    use HasCrudOperations;
    use HasBulkOperations;
    use HasFunctionOperations;

    public function __construct(
        protected RecordService $recordService
    ) {}
}

<?php

namespace Mylekha\RecordApi\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Mylekha\RecordApi\Tests\TestCase;

class ValidateSetupCommandTest extends TestCase
{
    /** @test */
    public function it_does_not_require_the_removed_cursor_pagination_config(): void
    {
        Artisan::call('record-api:validate');

        $this->assertStringNotContainsString('cursor_pagination.php', Artisan::output());
    }
}

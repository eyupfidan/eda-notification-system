<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_api_docs_are_public_outside_the_local_environment(): void
    {
        $this->assertFalse(app()->environment('local'));

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk()->assertJsonPath('openapi', fn ($v) => str_starts_with($v, '3.'));
    }
}

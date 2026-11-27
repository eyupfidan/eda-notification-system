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

    public function test_the_spec_uses_https_behind_a_tls_terminating_proxy(): void
    {
        $this->get('/docs/api.json', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'eda.example.com'])
            ->assertOk()
            ->assertJsonPath('servers.0.url', 'https://eda.example.com');
    }
}

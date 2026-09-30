<?php

namespace Tests\Feature;

use App\Services\Docker\DockerProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiHealthTest extends TestCase
{
    public function test_health_is_public_and_does_not_query_dependencies(): void
    {
        DB::shouldReceive('connection')->never();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
        Queue::fake();

        $this->get('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'ok']);

        Queue::assertNothingPushed();
    }

    public function test_openapi_documents_the_public_health_response(): void
    {
        $document = $this->getJson('/api/v1/openapi.json')->assertOk()->json();
        $operation = $document['paths']['/health']['get'];
        $schema = $operation['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(url('/api/v1'), $document['servers'][0]['url']);
        $this->assertSame([], $operation['security']);
        $this->assertSame([200], array_keys($operation['responses']));
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['status'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['type' => 'string', 'enum' => ['ok']], $schema['properties']['status']);
        $this->assertSame(['status' => 'ok'], $schema['example']);
        $this->assertArrayNotHasKey('security', $document['paths']['/me']['get']);
    }

    public function test_existing_up_endpoint_remains_public(): void
    {
        $this->get('/up')->assertOk();
    }
}

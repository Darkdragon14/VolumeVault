<?php

namespace Tests\Feature;

use App\Services\Agents\AgentOperationSpecification;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AgentOperationPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['volumevault.ssrf.allowed_ips' => []]);
        Http::preventStrayRequests();
    }

    public static function conflictingEndpoints(): array
    {
        return [
            ['http://169.254.169.254', null],
            ['http://169.254.169.254', ''],
            ['http://169.254.169.254', 'https://93.184.216.34'],
            ['https://93.184.216.34', 'http://169.254.169.254'],
            [null, 'http://169.254.169.254'],
        ];
    }

    #[DataProvider('conflictingEndpoints')]
    public function test_nested_endpoint_cannot_hide_a_different_upload_or_download_endpoint(?string $primary, ?string $nested): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $operation['spec']['destination']['endpoint'] = $primary;
        $operation['spec']['destination']['settings']['endpoint'] = $nested;
        $this->expectException(RuntimeException::class);
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
    }

    public function test_consistent_private_endpoints_still_require_the_agent_allowlist(): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $operation['spec']['destination']['endpoint'] = 'http://192.168.50.10:9000';
        $operation['spec']['destination']['settings']['endpoint'] = 'http://192.168.50.10:9000';
        try {
            app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
            $this->fail('Private endpoints must be refused without local permission.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Agent operation is blocked by local policy.', $exception->getMessage());
        }
        config(['volumevault.ssrf.allowed_ips' => ['192.168.50.0/24']]);
        app(AgentOperationSpecification::class)->validate($operation);
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
        $this->addToAssertionCount(1);
    }

    public function test_safety_destination_is_subject_to_the_same_endpoint_consistency_check(): void
    {
        $operation = AgentOperationRuntimeTest::operation('restore');
        $operation['spec']['safety_destination'] = $operation['spec']['destination'];
        $operation['spec']['safety_destination']['endpoint'] = 'http://169.254.169.254';
        $operation['spec']['safety_destination']['settings']['endpoint'] = null;
        $this->expectException(RuntimeException::class);
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
    }

    public function test_false_like_endpoint_is_not_treated_as_an_absent_endpoint(): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $operation['spec']['destination']['endpoint'] = '0';
        $this->expectException(RuntimeException::class);
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
    }

    public function test_public_primary_endpoint_and_matching_duplicate_are_allowed(): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
        $operation['spec']['destination']['settings']['endpoint'] = $operation['spec']['destination']['endpoint'];
        app(AgentOperationSpecification::class)->validateLocalPolicy($operation);
        $this->addToAssertionCount(2);
    }
}

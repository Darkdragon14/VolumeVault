<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class InstallationDocumentationTest extends TestCase
{
    public function test_large_installation_compose_configures_non_http_services_correctly(): void
    {
        $documentation = file_get_contents(dirname(__DIR__, 2).'/docs/_tabs/installation.md');

        $this->assertNotFalse($documentation);
        $this->assertSame(1, preg_match('/## Large Installation Compose.*?```yaml\n(?<compose>.*?)\n```/s', $documentation, $matches));

        $compose = $matches['compose'];

        $this->assertStringContainsString('command: ["mkdir -p /app/storage/database', $compose);

        foreach (['queue', 'queue-metadata', 'scheduler'] as $service) {
            $this->assertMatchesRegularExpression(
                '/^  '.preg_quote($service, '/').":\n(?:    .*\n)+    healthcheck:\n      disable: true$/m",
                $compose,
            );
        }
    }
}

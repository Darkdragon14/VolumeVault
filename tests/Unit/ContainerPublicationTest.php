<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ContainerPublicationTest extends TestCase
{
    public function test_asset_build_copies_only_existing_configuration_files(): void
    {
        $root = dirname(__DIR__, 2);
        $dockerfile = file_get_contents($root.'/Dockerfile');

        $this->assertNotFalse($dockerfile);
        $this->assertSame(1, preg_match('/^COPY (package[^\n]+) \.\/$/m', $dockerfile, $matches));

        foreach (explode(' ', $matches[1]) as $source) {
            $this->assertNotEmpty(glob($root.'/'.$source), 'Missing asset build source: '.$source);
        }
    }

    public function test_deploy_services_are_registered_in_the_current_s6_user_bundle(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertNotFalse($dockerfile);
        $this->assertStringNotContainsString('/etc/s6-overlay/s6-rc.d/user/contents.d/', $dockerfile);

        foreach (['queue', 'queue-metadata', 'scheduler', 'agent-tls'] as $service) {
            $this->assertStringContainsString(
                'touch /etc/s6-overlay/user-bundles.d/user/contents.d/volumevault-'.$service,
                $dockerfile,
            );
            $this->assertStringContainsString(
                "printf 'longrun\\n' > /etc/s6-overlay/s6-rc.d/volumevault-".$service.'/type',
                $dockerfile,
            );
        }
    }
}

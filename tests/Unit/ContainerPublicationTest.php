<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ContainerPublicationTest extends TestCase
{
    #[DataProvider('phpVersions')]
    public function test_both_php_stages_enforce_the_security_release(int $versionId, int $exitCode): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertNotFalse($dockerfile);

        foreach (['runtime', 'agent'] as $stage) {
            $this->assertSame(1, preg_match('/^FROM [^\n]+ AS '.$stage.'\n(.*?)(?=^FROM |\z)/ms', $dockerfile, $stageMatches));
            $this->assertSame(1, preg_match('/^RUN php -r \'([^\n]+)\'$/m', $stageMatches[1], $guardMatches));
            $this->assertStringContainsString('PHP_VERSION_ID < 80512', $guardMatches[1]);

            $process = new Process([PHP_BINARY, '-r', str_replace('PHP_VERSION_ID', (string) $versionId, $guardMatches[1])]);
            $process->setTimeout(10);
            $process->run();

            $this->assertSame($exitCode, $process->getExitCode(), $stage.': '.$process->getErrorOutput());
            $this->assertSame('', $process->getOutput());
            $this->assertSame($exitCode === 0 ? '' : "VolumeVault requires PHP 8.5.12 or newer.\n", $process->getErrorOutput());
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function phpVersions(): array
    {
        return [
            'previous patch rejected' => [80511, 1],
            'older minor rejected' => [80499, 1],
            'security release accepted' => [80512, 0],
            'newer patch accepted' => [80513, 0],
            'newer minor accepted' => [80600, 0],
        ];
    }

    public function test_publication_supports_manual_rebuilds_with_fresh_base_images(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ghcr.yml');

        $this->assertNotFalse($workflow);
        $this->assertMatchesRegularExpression('/^on:\n  workflow_dispatch:$/m', $workflow);
        $this->assertMatchesRegularExpression('/uses: docker\/build-push-action@v6\n        with:\n          context: \.\n          pull: true\n/', $workflow);
    }

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

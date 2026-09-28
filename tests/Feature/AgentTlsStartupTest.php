<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentTlsStartupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/volumevault-startup-'.bin2hex(random_bytes(10));
        foreach (['bin', 'app', 'native'] as $directory) {
            File::makeDirectory($this->directory.'/'.$directory, 0700, true);
        }
        $this->script('bin/chown', 'echo permissions >> "$TRACE"');
        $this->script('bin/setuidgid', 'echo "user:$1" >> "$TRACE"; shift; exec "$@"');
        $this->script('bin/php', <<<'SH'
echo "php:$*" >> "$TRACE"
if [ "${2:-}" = "volumevault:agent-tls:prepare" ]; then
    [ "${FAIL_PREPARE:-false}" != true ] || exit 1
    mkdir -p "$APP/storage/app/private/agent-tls"
    echo certificate > "$APP/storage/app/private/agent-tls/server.crt"
    echo key > "$APP/storage/app/private/agent-tls/server.key"
fi
SH);
        $this->script('native/10-ssl.sh', 'echo "ssl:${SSL_MODE:-off}:${SSL_CERTIFICATE_FILE:-}:${SSL_PRIVATE_KEY_FILE:-}" >> "$TRACE"');
        $this->script('bin/init', 'echo init >> "$TRACE"');
        $entrypoint = str_replace(
            ['/command/s6-setuidgid', '/etc/entrypoint.d/', '/init'],
            [$this->directory.'/bin/setuidgid', $this->directory.'/native/', $this->directory.'/bin/init'],
            preg_replace('~(?<![\w/])/app\b~', $this->directory.'/app', file_get_contents(base_path('docker-entrypoint.sh'))),
        );
        file_put_contents($this->directory.'/entrypoint', $entrypoint);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_tls_is_prepared_after_permissions_and_before_native_ssl_setup(): void
    {
        $process = $this->runEntrypoint([], ['APP_KEY' => 'test', 'VOLUMEVAULT_AGENTS_ENABLED' => 'true']);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $trace = file_get_contents($this->directory.'/trace');
        $this->assertLessThan(strpos($trace, 'php:artisan volumevault:agent-tls:prepare'), strpos($trace, 'permissions'));
        $this->assertLessThan(strpos($trace, 'ssl:mixed:'), strpos($trace, 'php:artisan volumevault:agent-tls:prepare'));
        $this->assertStringContainsString('ssl:mixed:'.$this->directory.'/app/storage/app/private/agent-tls/server.crt:'.$this->directory.'/app/storage/app/private/agent-tls/server.key', $trace);
        $this->assertStringContainsString('init', $trace);
    }

    public function test_failed_preparation_blocks_native_ssl_startup(): void
    {
        $process = $this->runEntrypoint([], ['APP_KEY' => 'test', 'VOLUMEVAULT_AGENTS_ENABLED' => 'true', 'FAIL_PREPARE' => 'true']);
        $this->assertFalse($process->isSuccessful());
        $this->assertStringNotContainsString('ssl:', file_get_contents($this->directory.'/trace'));
    }

    public function test_disabled_agents_do_not_prepare_tls(): void
    {
        $process = $this->runEntrypoint([], ['APP_KEY' => 'test']);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringNotContainsString('volumevault:agent-tls:prepare', file_get_contents($this->directory.'/trace'));
    }

    public function test_agent_cli_gets_writable_state_and_drops_privileges_without_central_bootstrap(): void
    {
        $process = $this->runEntrypoint(['php', 'artisan', 'volumevault:agent', '--no-interaction']);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertDirectoryExists($this->directory.'/app/storage/app/agent');
        $this->assertDirectoryExists($this->directory.'/app/bootstrap/cache');
        $this->assertDirectoryDoesNotExist($this->directory.'/app/storage/database');
        $trace = file_get_contents($this->directory.'/trace');
        $this->assertStringContainsString("user:www-data\nphp:artisan volumevault:agent --no-interaction", $trace);
        $this->assertStringNotContainsString('migrate', $trace);
        $this->assertStringNotContainsString('ssl:', $trace);
    }

    private function script(string $path, string $contents): void
    {
        file_put_contents($this->directory.'/'.$path, "#!/bin/sh\nset -eu\n".$contents."\n");
        chmod($this->directory.'/'.$path, 0700);
    }

    private function runEntrypoint(array $arguments = [], array $environment = []): Process
    {
        $process = new Process(['sh', $this->directory.'/entrypoint', ...$arguments], $this->directory.'/app', array_merge([
            'PATH' => $this->directory.'/bin:'.getenv('PATH'),
            'TRACE' => $this->directory.'/trace',
            'APP' => $this->directory.'/app',
            'APP_KEY' => '',
            'DOCKER_HOST' => 'tcp://docker.invalid:2375',
            'VOLUMEVAULT_AGENTS_ENABLED' => 'false',
            'VOLUMEVAULT_MIGRATIONS_ENABLED' => 'false',
            'SSL_MODE' => false,
        ], $environment));
        $process->run();

        return $process;
    }
}

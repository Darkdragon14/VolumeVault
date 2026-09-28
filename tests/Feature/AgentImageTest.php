<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentImageTest extends TestCase
{
    private string $image;

    protected function setUp(): void
    {
        parent::setUp();
        $image = getenv('VOLUMEVAULT_AGENT_CLI_TEST_IMAGE');
        if (! is_string($image) || $image === '') {
            $this->markTestSkipped('Set VOLUMEVAULT_AGENT_CLI_TEST_IMAGE to the built agent target and provide a Docker daemon.');
        }
        $this->image = $image;
    }

    public function test_cli_boots_without_key_database_or_network_and_lists_the_agent_command(): void
    {
        $output = $this->docker(['run', '--rm', '--network', 'none', $this->image, 'php', 'artisan', 'list', '--raw']);
        $this->assertStringContainsString('volumevault:agent ', $output);
        $this->assertStringContainsString('--once', $this->docker(['run', '--rm', '--network', 'none', $this->image, 'php', 'artisan', 'volumevault:agent', '--help']));

        $config = json_decode($this->docker(['image', 'inspect', '--format', '{{json .Config}}', $this->image]), true);
        $this->assertSame(['php', 'artisan', 'volumevault:agent'], $config['Cmd']);
        $this->assertSame(['agent-entrypoint'], $config['Entrypoint']);
    }

    public function test_runtime_is_cli_only_and_runs_as_www_data(): void
    {
        $this->assertSame('www-data', trim($this->docker(['run', '--rm', $this->image, 'id', '-un'])));
        $this->docker(['run', '--rm', $this->image, 'sh', '-ec', <<<'SH'
test ! -e /app/.env
test ! -e /app/public
test ! -e /app/storage/database/database.sqlite
test ! -e /etc/s6-overlay
for binary in nginx php-fpm node npm gcc g++ make; do
    if command -v "$binary" >/dev/null 2>&1; then exit 1; fi
done
test -w /app/storage/app/agent
test -w /app/storage/app/docker-cli/logs
test -w /app/bootstrap/cache
php -r 'exit(PHP_SAPI === "cli" && extension_loaded("pcntl") && extension_loaded("openssl") && extension_loaded("zip") ? 0 : 1);'
SH]);
    }

    public function test_recreated_container_keeps_persisted_state_and_gains_socket_group_access(): void
    {
        $volume = 'volumevault-agent-image-test-'.bin2hex(random_bytes(6));
        $this->docker(['volume', 'create', $volume]);
        try {
            $this->docker(['run', '--rm', '--entrypoint', 'php', '-v', $volume.':/app/storage', $this->image, '-r', <<<'PHP'
mkdir('/app/storage/app/agent', 0700, true);
file_put_contents('/app/storage/app/agent/state.json', '{"credential":"persisted-identity"}');
chown('/app/storage/app/agent', 82);
chown('/app/storage/app/agent/state.json', 82);
$socket = stream_socket_server('unix:///app/storage/test.sock');
chmod('/app/storage/test.sock', 0660);
chgrp('/app/storage/test.sock', 23456);
PHP]);

            for ($iteration = 0; $iteration < 2; $iteration++) {
                $output = $this->docker(['run', '--rm', '-v', $volume.':/app/storage', '-e', 'DOCKER_HOST=unix:///app/storage/test.sock', $this->image, 'php', '-r', <<<'PHP'
if (! is_writable('/app/storage/test.sock') || ! is_writable('/app/storage/app/agent/state.json')) { exit(1); }
echo file_get_contents('/app/storage/app/agent/state.json');
PHP]);
                $this->assertSame('{"credential":"persisted-identity"}', trim($output));
            }
        } finally {
            $this->docker(['volume', 'rm', $volume]);
        }
    }

    private function docker(array $arguments): string
    {
        $process = new Process(['docker', ...$arguments], timeout: 60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return $process->getOutput();
    }
}

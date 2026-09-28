<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AgentDockerIntegrationTest extends TestCase
{
    private array $containers = [];

    private array $volumes = [];

    private ?string $network = null;

    private ?string $dockerFixture = null;

    protected function tearDown(): void
    {
        foreach (array_reverse($this->containers) as $container) {
            (new Process(['docker', 'rm', '-f', $container]))->run();
        }
        foreach ($this->volumes as $volume) {
            (new Process(['docker', 'volume', 'rm', $volume]))->run();
        }
        if ($this->network !== null) {
            (new Process(['docker', 'network', 'rm', $this->network]))->run();
        }
        if ($this->dockerFixture !== null) {
            @unlink($this->dockerFixture);
        }
        parent::tearDown();
    }

    public static function deploymentModes(): array
    {
        return [['hybrid'], ['orchestrator']];
    }

    #[DataProvider('deploymentModes')]
    public function test_built_image_enrolls_over_real_nginx_tls_and_recovers_identity_after_restarts(string $mode): void
    {
        $image = getenv('VOLUMEVAULT_AGENT_TEST_IMAGE');
        if (! is_string($image) || $image === '') {
            $this->markTestSkipped('Set VOLUMEVAULT_AGENT_TEST_IMAGE to a built image and provide a Docker daemon for isolated container integration tests.');
        }
        $agentImage = getenv('VOLUMEVAULT_AGENT_CLI_TEST_IMAGE') ?: $image;
        $prefix = 'volumevault-agent-test-'.bin2hex(random_bytes(6));
        $this->network = $prefix;
        $this->runDocker(['network', 'create', $this->network]);
        $server = $prefix.'-server';
        $agent = $prefix.'-agent';
        $rogue = $prefix.'-wrong-ca';
        $serverVolume = $prefix.'-server-data';
        $agentVolume = $prefix.'-agent-data';
        $rogueVolume = $prefix.'-wrong-ca-data';
        $this->volumes = [$serverVolume, $agentVolume, $rogueVolume];
        $this->containers[] = $server;
        $this->runDocker([
            'run', '-d', '--name', $server, '--network', $this->network, '--network-alias', 'orchestrator',
            '-v', $serverVolume.':/app/storage',
            '-e', 'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            '-e', 'VOLUMEVAULT_MODE='.$mode,
            '-e', 'VOLUMEVAULT_AGENTS_ENABLED=true', '-e', 'VOLUMEVAULT_AGENT_URL=https://orchestrator:8443',
            '-e', 'VOLUMEVAULT_AGENT_IMAGE='.$agentImage,
            $image,
        ]);
        $this->waitForTls($server);
        $enrollment = $this->control($server, 'issue');
        $this->assertNotEmpty($enrollment['token']);
        $this->assertNotEmpty($enrollment['ca']);

        $this->dockerFixture = tempnam(sys_get_temp_dir(), 'volumevault-docker-');
        file_put_contents($this->dockerFixture, <<<'PHP'
#!/usr/local/bin/php
<?php
if (($argv[1] ?? '') === 'info') {
    echo ($argv[3] ?? '') === '{{.ID}}' ? "agent-test-engine\n" : json_encode(['version' => '29.0.0', 'containers' => 1]);
} elseif (($argv[1] ?? '') === 'volume' && ($argv[2] ?? '') === 'ls') {
    echo json_encode(['Name' => 'agent-test-volume', 'Driver' => 'local'])."\n";
} elseif (($argv[1] ?? '') === 'volume' && ($argv[2] ?? '') === 'inspect') {
    echo json_encode([['Name' => 'agent-test-volume', 'Driver' => 'local', 'Mountpoint' => '/var/lib/docker/volumes/agent-test-volume/_data', 'Labels' => ['com.docker.compose.project' => 'agent-test'], 'Options' => []]]);
} elseif (($argv[1] ?? '') === 'ps') {
    echo json_encode(['ID' => 'abcdef123456', 'Names' => 'agent-test-container', 'Image' => 'example:latest', 'State' => 'running', 'Status' => 'Up'])."\n";
} else {
    exit(1);
}
PHP);

        $wrongCa = $this->control($server, 'wrong-ca')['ca'];
        $this->createAgent($rogue, $rogueVolume, $image, $enrollment['token'], $wrongCa);
        $this->runDocker(['start', '-a', $rogue], false);
        $this->assertSame(1, (int) trim($this->runDocker(['inspect', '-f', '{{.State.ExitCode}}', $rogue])));
        $this->assertSame('pending', $this->control($server, 'inspect')['status']);

        $this->createAgent($agent, $agentVolume, $image, $enrollment['token'], $enrollment['ca']);
        $this->runDocker(['start', '-a', $agent]);
        $first = $this->control($server, 'inspect');
        $this->assertSame('online', $first['status']);
        $this->assertSame(1, $first['volume_count']);
        $this->assertSame(1, $first['container_count']);
        $this->assertSame('29.0.0', $first['docker_version']);
        $this->assertSame(1, $first['sequence']);
        $this->assertFalse($first['plaintext_stored']);
        $this->assertSame('www-data', trim($this->runDocker(['run', '--rm', '--entrypoint', 'stat', '-v', $agentVolume.':/state', $image, '-c', '%U', '/state/app/agent/state.json'])));

        $this->runDocker(['restart', $server]);
        $this->waitForTls($server);
        if ($agentImage !== $image) {
            $this->runDocker(['rm', $agent]);
            $this->createAgent($agent, $agentVolume, $agentImage, '', $enrollment['ca'], dedicated: true);
        }
        $this->runDocker(['start', '-a', $agent]);
        $second = $this->control($server, 'inspect');
        $this->assertSame($first['instance_id'], $second['instance_id']);
        $this->assertSame($first['ca_fingerprint'], $second['ca_fingerprint']);
        $this->assertSame(2, $second['sequence']);
        $this->assertSame(1, $second['registrations']);
        $this->control($server, 'revoke');
        $this->runDocker(['start', '-a', $agent], false);
        $this->assertSame(1, (int) trim($this->runDocker(['inspect', '-f', '{{.State.ExitCode}}', $agent])));
        $this->assertSame('revoked', $this->control($server, 'inspect')['status']);
    }

    private function createAgent(string $name, string $volume, string $image, string $token, string $ca, bool $dedicated = false): void
    {
        $this->containers[] = $name;
        $this->runDocker([
            'create', '--name', $name, '--network', $this->network, '--no-healthcheck',
            '-v', $volume.':/app/storage', '-e', 'VOLUMEVAULT_ORCHESTRATOR_URL=https://orchestrator:8443',
            '-e', 'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN='.$token, '-e', 'VOLUMEVAULT_AGENT_CA='.$ca,
            '--entrypoint', 'sh', $image, '-c',
            'chmod 755 /usr/local/bin/docker && exec '.($dedicated ? 'agent-entrypoint' : 'docker-entrypoint').' php artisan volumevault:agent --once',
        ]);
        $this->runDocker(['cp', $this->dockerFixture, $name.':/usr/local/bin/docker']);
    }

    private function waitForTls(string $server): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $process = new Process(['docker', 'exec', $server, 'curl', '--fail', '--silent', '--max-time', '2', '--cacert', '/app/storage/app/private/agent-tls/ca.crt', 'https://orchestrator:8443/up']);
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            usleep(500000);
        }
        $this->fail('The built orchestrator did not become reachable with validated nginx TLS.');
    }

    /** @return array<string, mixed> */
    private function control(string $server, string $action): array
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$action = getenv('AGENT_TEST_ACTION');
if ($action === 'issue') {
    $host = App\Models\DockerHost::create(['name' => 'Integration agent']);
    $installation = app(App\Services\Agents\AgentRegistry::class)->issueEnrollment($host);
    preg_match("/'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^']+)'/", $installation['command'], $token);
    $result = ['token' => $token[1], 'ca' => base64_encode(app(App\Services\Agents\AgentTlsIdentity::class)->caCertificate())];
} elseif ($action === 'wrong-ca') {
    config(['volumevault.agents.tls_directory' => '/tmp/agent-integration-wrong-ca']);
    $result = ['ca' => base64_encode(app(App\Services\Agents\AgentTlsIdentity::class)->caCertificate())];
} else {
    $host = App\Models\DockerHost::where('driver', 'agent')->firstOrFail();
    if ($action === 'revoke') {
        app(App\Services\Agents\AgentRegistry::class)->revoke($host);
        $host->refresh();
    }
    $result = ['status' => $host->agentStatus(), 'volume_count' => $host->volumes()->count(), 'container_count' => count($host->agent_containers ?? []), 'docker_version' => $host->docker_version, 'sequence' => $host->agent_inventory_sequence, 'instance_id' => $host->agent_instance_id, 'ca_fingerprint' => hash('sha256', app(App\Services\Agents\AgentTlsIdentity::class)->caCertificate()), 'plaintext_stored' => isset($host->getAttributes()['credential']), 'registrations' => App\Models\ActivityLog::where('event_type', 'agent_registered')->count()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;

        return json_decode($this->runDocker(['exec', '-e', 'AGENT_TEST_ACTION='.$action, $server, 'php', '-r', $code]), true, flags: JSON_THROW_ON_ERROR);
    }

    private function runDocker(array $arguments, bool $mustSucceed = true): string
    {
        $process = new Process(['docker', ...$arguments]);
        $process->setTimeout(90)->run();
        if ($mustSucceed) {
            $this->assertTrue($process->isSuccessful(), 'Docker integration operation failed: '.$arguments[0].($arguments[0] === 'start' ? '\n'.$process->getOutput().$process->getErrorOutput() : ''));
        }

        return $process->getOutput();
    }
}

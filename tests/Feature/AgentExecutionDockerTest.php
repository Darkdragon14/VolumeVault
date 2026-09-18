<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Opt in with VOLUMEVAULT_AGENT_EXECUTION_DOCKER_TEST=1 and locally built
 * VOLUMEVAULT_AGENT_TEST_IMAGE (server) / VOLUMEVAULT_AGENT_CLI_TEST_IMAGE (CLI).
 * Requires privileged DinD support; never pulls images or mounts host paths.
 * Optional TEST_DIND_IMAGE and TEST_MINIO_IMAGE override the prerequisite tags.
 */
class AgentExecutionDockerTest extends TestCase
{
    private const OFFEN_IMAGE = 'offen/docker-volume-backup:latest';

    /** @var list<string> */
    private array $containers = [];

    /** @var list<string> */
    private array $volumes = [];

    private ?string $network = null;

    public function test_agent_a_backs_up_to_s3_and_agent_b_restores_on_a_distinct_engine(): void
    {
        if (getenv('VOLUMEVAULT_AGENT_EXECUTION_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Set VOLUMEVAULT_AGENT_EXECUTION_DOCKER_TEST=1 to allow isolated privileged Docker-in-Docker containers.');
        }

        $serverImage = getenv('VOLUMEVAULT_AGENT_TEST_IMAGE');
        $agentImage = getenv('VOLUMEVAULT_AGENT_CLI_TEST_IMAGE');
        if (! $serverImage || ! $agentImage) {
            $this->markTestSkipped('Build current server and CLI images, then set VOLUMEVAULT_AGENT_TEST_IMAGE and VOLUMEVAULT_AGENT_CLI_TEST_IMAGE.');
        }
        $dindImage = getenv('TEST_DIND_IMAGE') ?: 'docker:27-dind';
        $minioImage = getenv('TEST_MINIO_IMAGE') ?: 'pgsty/minio@sha256:b6bfe7239bfc83fb90d31612d9704d86039dd714f7904b3f1ad68f211e602372';
        if (! $this->succeeds(['info'])) {
            $this->markTestSkipped('A reachable Docker daemon and Docker CLI are required.');
        }
        foreach (array_unique([$serverImage, $agentImage, $dindImage, $minioImage, self::OFFEN_IMAGE]) as $image) {
            if (! $this->succeeds(['image', 'inspect', $image])) {
                $this->markTestSkipped('Prerequisite image is not local: '.$image.'. Build/pull it before running this test.');
            }
        }

        $prefix = 'vv-execution-'.bin2hex(random_bytes(6));
        $server = $prefix.'-server';
        $minio = $prefix.'-minio';
        $source = 'source-data';
        $target = 'restored-on-b';
        $marker = 'backup-from-a-'.bin2hex(random_bytes(16));
        $sentinel = 'untouched-on-b-'.bin2hex(random_bytes(16));
        $password = bin2hex(random_bytes(24));

        try {
            $this->network = $prefix;
            $this->docker(['network', 'create', '--internal', $prefix]);
            $subnet = trim($this->docker(['network', 'inspect', '-f', '{{(index .IPAM.Config 0).Subnet}}', $prefix]));
            $engines = [];
            foreach (['a', 'b'] as $side) {
                $engine = $prefix.'-dind-'.$side;
                $this->start($engine, $dindImage, [
                    '--privileged', '-e', 'DOCKER_TLS_CERTDIR=',
                    '-v', $this->volume($engine.'-data').':/var/lib/docker',
                ], ['--tls=false']);
                $this->waitFor(fn (): bool => $this->succeeds(['exec', $engine, 'docker', 'info']), 'DinD '.$side.' readiness (privileged containers must be supported)', 60);
                $this->preload($engine);
                $engines[$side] = $engine;
            }
            $this->assertNotSame(
                trim($this->docker(['exec', $engines['a'], 'docker', 'info', '--format', '{{.ID}}'])),
                trim($this->docker(['exec', $engines['b'], 'docker', 'info', '--format', '{{.ID}}'])),
            );
            $this->writeMarker($engines['a'], $source, $marker);
            $this->writeMarker($engines['b'], $source, $sentinel);
            $this->assertFalse($this->succeeds(['exec', $engines['b'], 'docker', 'volume', 'inspect', $target]));

            $this->start($minio, $minioImage, [
                '-e', 'MINIO_ROOT_USER=backup', '-e', 'MINIO_ROOT_PASSWORD='.$password,
                '-v', $this->volume($minio.'-data').':/data',
            ], ['server', '/data']);
            $endpoint = 'http://'.$this->ip($minio).':9000';
            $this->start($server, $serverImage, [
                '--network-alias', 'orchestrator',
                '-v', $this->volume($server.'-data').':/app/storage',
                '-e', 'APP_KEY=base64:'.base64_encode(random_bytes(32)),
                '-e', 'VOLUMEVAULT_MODE=orchestrator', '-e', 'DOCKER_HOST=tcp://127.0.0.1:1',
                '-e', 'VOLUMEVAULT_AGENTS_ENABLED=true',
                '-e', 'VOLUMEVAULT_AGENT_URL=https://orchestrator:8443',
                '-e', 'VOLUMEVAULT_AGENT_IMAGE='.$agentImage,
                '-e', 'VOLUMEVAULT_SSRF_ALLOWED_IPS='.$subnet,
            ]);
            $this->waitFor(fn (): bool => $this->succeeds([
                'exec', $server, 'curl', '--fail', '--silent', '--max-time', '2',
                '--cacert', '/app/storage/app/private/agent-tls/ca.crt', 'https://orchestrator:8443/up',
            ]), 'orchestrator validated TLS readiness', 60);
            $this->waitFor(fn (): bool => $this->succeeds(['exec', $server, 'curl', '--fail', '--silent', '--max-time', '2', $endpoint.'/minio/health/ready']), 'MinIO readiness', 30);
            $guard = $this->control($server, <<<'PHP'
$blocked = false;
try { App\Services\Docker\LocalDockerExecution::assertHost(App\Models\DockerHost::LOCAL_ID); }
catch (Throwable) { $blocked = true; }
$result = ['orchestrator' => App\Support\DeploymentMode::isOrchestrator(), 'socket' => file_exists('/var/run/docker.sock'), 'blocked' => $blocked];
PHP);
            $this->assertSame(['orchestrator' => true, 'socket' => false, 'blocked' => true], $guard);

            $hosts = [];
            $agents = [];
            foreach (['a', 'b'] as $side) {
                $enrollment = $this->control($server, <<<'PHP'
$host = App\Models\DockerHost::create(['name' => 'Execution '.$input['side']]);
$installation = app(App\Services\Agents\AgentRegistry::class)->issueEnrollment($host);
preg_match("/'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN=([^']+)'/", $installation['command'], $token);
$result = ['id' => $host->id, 'token' => $token[1], 'ca' => base64_encode(app(App\Services\Agents\AgentTlsIdentity::class)->caCertificate())];
PHP, ['side' => $side]);
                $hosts[$side] = $enrollment['id'];
                $agents[$side] = $prefix.'-agent-'.$side;
                $this->start($agents[$side], $agentImage, [
                    '-v', $this->volume($agents[$side].'-data').':/app/storage',
                    '-e', 'DOCKER_HOST=tcp://'.$this->ip($engines[$side]).':2375',
                    '-e', 'VOLUMEVAULT_ORCHESTRATOR_URL=https://orchestrator:8443',
                    '-e', 'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN='.$enrollment['token'],
                    '-e', 'VOLUMEVAULT_AGENT_CA='.$enrollment['ca'],
                    '-e', 'VOLUMEVAULT_SSRF_ALLOWED_IPS='.$subnet,
                ]);
            }
            $this->waitFor(function () use ($server, $hosts, $source): bool {
                return $this->control($server, <<<'PHP'
$result = ['ready' => App\Models\DockerVolume::whereIn('docker_host_id', $input['hosts'])->where('name', $input['source'])->where('exists', true)->count() === 2];
PHP, ['hosts' => array_values($hosts), 'source' => $source])['ready'];
            }, 'both real agent inventories', 90);

            $backup = $this->control($server, <<<'PHP'
$s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $input['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'backup', 'secret' => $input['password']]]);
$s3->createBucket(['Bucket' => 'execution-backups']);
$destination = App\Models\BackupDestination::create(['name' => 'Isolated MinIO', 'provider' => 'custom_s3', 'endpoint' => $input['endpoint'], 'region' => 'us-east-1', 'bucket' => 'execution-backups', 'path_prefix' => 'backups', 'access_key_id' => 'backup', 'secret_access_key' => $input['password'], 'use_path_style_endpoint' => true, 'is_active' => true]);
$job = App\Models\BackupJob::create(['name' => 'Actual agent backup', 'docker_host_id' => $input['host'], 'source_type' => 'docker_volume', 'volume_name' => $input['source'], 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'timezone' => 'UTC', 'status' => 'active', 'stop_containers_before_backup' => false]);
$run = app(App\Actions\Backup\CreateBackupRun::class)->handle($job, 'manual');
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id, 'job' => $job->id];
PHP, ['endpoint' => $endpoint, 'password' => $password, 'host' => $hosts['a'], 'source' => $source]);
            $this->interruptControlPlaneUntilResultIsDurable($server, $agents['a'], 'backup', $backup['id']);
            $completedBackup = $this->waitForRun($server, 'backup', $backup['id']);
            $this->assertNotEmpty($completedBackup['key']);
            $this->assertGreaterThan(0, $completedBackup['size']);
            $this->assertSame($hosts['a'], $completedBackup['host']);
            $operation = $completedBackup['operation'];
            $this->waitFor(fn (): bool => $this->journal($agents['a'], $operation)['phase'] === 'acknowledged', 'backup journal acknowledgement', 60);
            $before = $this->journal($agents['a'], $operation);
            $this->assertTrue($before['local_key']);
            $this->assertTrue($before['no_app_key']);
            $identity = $this->hostIdentity($server, $hosts['a']);
            $this->docker(['restart', $agents['a']]);
            $this->waitFor(fn (): bool => $this->hostIdentity($server, $hosts['a'])['sequence'] > $identity['sequence'], 'fresh inventory after agent restart', 60);
            $this->assertSame($identity['instance'], $this->hostIdentity($server, $hosts['a'])['instance']);
            $this->assertSame($before, $this->journal($agents['a'], $operation));

            $restore = $this->control($server, <<<'PHP'
$job = App\Models\BackupJob::findOrFail($input['job']);
$run = app(App\Actions\Restore\CreateRestoreRun::class)->handle($job, ['mode' => 'new_volume', 'target_docker_host_id' => $input['host'], 'target_volume_name' => $input['target'], 'selected_backup_key' => $input['key'], 'backup_run_id' => $input['backup']]);
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id];
PHP, ['job' => $backup['job'], 'host' => $hosts['b'], 'target' => $target, 'key' => $completedBackup['key'], 'backup' => $backup['id']]);
            $this->interruptControlPlaneUntilResultIsDurable($server, $agents['b'], 'restore', $restore['id']);
            $completedRestore = $this->waitForRun($server, 'restore', $restore['id']);
            $this->assertSame($hosts['b'], $completedRestore['host']);
            $this->assertSame($marker, $this->readMarker($engines['b'], $target));
            $this->assertSame($marker, $this->readMarker($engines['a'], $source));
            $this->assertSame($sentinel, $this->readMarker($engines['b'], $source));
            $this->assertFalse($this->succeeds(['exec', $engines['a'], 'docker', 'volume', 'inspect', $target]));
            $counts = $this->control($server, <<<'PHP'
$result = ['backups' => App\Models\BackupRun::count(), 'restores' => App\Models\RestoreRun::count(), 'operations' => App\Models\AgentOperation::count(), 'completed' => App\Models\AgentOperation::whereNotNull('completed_at')->count()];
PHP);
            $this->assertSame(['backups' => 1, 'restores' => 1, 'operations' => 2, 'completed' => 2], $counts);
            $objects = $this->control($server, <<<'PHP'
$s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $input['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'backup', 'secret' => $input['password']]]);
$keys = [];
foreach ($s3->getPaginator('ListObjectsV2', ['Bucket' => 'execution-backups']) as $page) {
    foreach ($page['Contents'] ?? [] as $object) {
        if (str_ends_with($object['Key'], '.tar.gz')) {
            $keys[] = $object['Key'];
        }
    }
}
$result = ['keys' => $keys];
PHP, ['endpoint' => $endpoint, 'password' => $password]);
            $this->assertSame([$completedBackup['key']], $objects['keys']);
        } finally {
            $this->cleanup();
        }
    }

    /** @param list<string> $options @param list<string> $command */
    private function start(string $name, string $image, array $options, array $command = []): void
    {
        $this->containers[] = $name;
        $this->docker(['run', '-d', '--pull=never', '--name', $name, '--network', $this->network, ...$options, $image, ...$command]);
    }

    private function volume(string $name): string
    {
        $this->volumes[] = $name;
        $this->docker(['volume', 'create', $name]);

        return $name;
    }

    private function ip(string $container): string
    {
        return trim($this->docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', $container]));
    }

    private function preload(string $engine): void
    {
        $save = new Process(['docker', 'image', 'save', self::OFFEN_IMAGE]);
        $load = new Process(['docker', 'exec', '-i', $engine, 'docker', 'image', 'load']);
        try {
            $save->setTimeout(120)->start();
            $load->setInput($save->getIterator(Process::ITER_SKIP_ERR));
            $load->setTimeout(120)->run();
            $this->assertTrue($save->isSuccessful() && $load->isSuccessful(), 'Streaming the local Offen image into DinD failed.');
        } finally {
            $save->stop();
            $load->stop();
        }
    }

    private function writeMarker(string $engine, string $volume, string $marker): void
    {
        $this->docker(['exec', $engine, 'docker', 'volume', 'create', $volume]);
        $this->docker(['exec', $engine, 'docker', 'run', '--rm', '--pull=never', '-v', $volume.':/data', '--entrypoint', 'sh', self::OFFEN_IMAGE, '-c', 'printf %s "$1" > /data/marker', 'sh', $marker]);
    }

    private function readMarker(string $engine, string $volume): string
    {
        return $this->docker(['exec', $engine, 'docker', 'run', '--rm', '--pull=never', '-v', $volume.':/data:ro', '--entrypoint', 'sh', self::OFFEN_IMAGE, '-c', 'cat /data/marker']);
    }

    /** @return array<string, mixed> */
    private function waitForRun(string $server, string $kind, int $id): array
    {
        $state = [];
        $this->waitFor(function () use ($server, $kind, $id, &$state): bool {
            $state = $this->control($server, <<<'PHP'
$backup = $input['kind'] === 'backup';
$run = ($backup ? App\Models\BackupRun::class : App\Models\RestoreRun::class)::findOrFail($input['id']);
$operations = App\Models\AgentOperation::where($backup ? 'backup_run_id' : 'restore_run_id', $run->id);
$result = ['status' => $run->status, 'key' => $backup ? $run->backup_key : null, 'size' => $backup ? $run->backup_size_bytes : null, 'host' => $backup ? $run->docker_host_id : $run->target_docker_host_id, 'operation' => (clone $operations)->value('id'), 'count' => $operations->count()];
PHP, ['kind' => $kind, 'id' => $id]);
            $this->assertNotContains($state['status'], ['failed', 'cancelled'], $kind.' failed; inspect the isolated runtime pipeline (raw logs withheld to protect credentials).');

            return $state['status'] === 'success';
        }, $kind.' completion through real agent polling', 180);
        $this->assertSame(1, $state['count']);

        return $state;
    }

    private function interruptControlPlaneUntilResultIsDurable(string $server, string $agent, string $kind, int $id): void
    {
        $operation = null;
        $this->waitFor(function () use ($server, $kind, $id, &$operation): bool {
            $state = $this->control($server, <<<'PHP'
$operation = App\Models\AgentOperation::where($input['kind'] === 'backup' ? 'backup_run_id' : 'restore_run_id', $input['id'])->first();
$result = ['id' => $operation?->id, 'status' => $operation?->status];
PHP, ['kind' => $kind, 'id' => $id]);
            $this->assertNotSame('completed', $state['status'], 'The outage must precede central result acknowledgement.');
            $operation = $state['id'];

            return $state['status'] === 'running';
        }, $kind.' assignment before outage', 90);
        $this->docker(['pause', $server]);
        try {
            $this->waitFor(fn (): bool => $this->journal($agent, $operation)['phase'] === 'finished', $kind.' finishing without the control plane', 120);
            $before = $this->journal($agent, $operation);
            $this->docker(['restart', $agent]);
            $this->waitFor(fn (): bool => $this->succeeds(['exec', $agent, 'php', '-r', 'exit(0);']), 'agent restart while control plane is unavailable', 30);
            $this->assertSame($before, $this->journal($agent, $operation));
        } finally {
            $this->docker(['unpause', $server]);
        }
    }

    /** @return array<string, mixed> */
    private function journal(string $agent, string $operation): array
    {
        return $this->control($agent, <<<'PHP'
$store = app(App\Services\Agents\AgentOperationStore::class);
$result = ['phase' => $store->read($input['operation'])['phase'] ?? null, 'local_key' => is_file($store->root().'/operations.key'), 'no_app_key' => ! getenv('APP_KEY'), 'key_fingerprint' => is_file($store->root().'/operations.key') ? hash_file('sha256', $store->root().'/operations.key') : null];
PHP, ['operation' => $operation]);
    }

    /** @return array<string, mixed> */
    private function hostIdentity(string $server, int $host): array
    {
        return $this->control($server, <<<'PHP'
$host = App\Models\DockerHost::findOrFail($input['host']);
$result = ['instance' => $host->agent_instance_id, 'sequence' => $host->agent_inventory_sequence];
PHP, ['host' => $host]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function control(string $container, string $code, array $input = []): array
    {
        $bootstrap = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode(getenv('EXECUTION_TEST_INPUT'), true, flags: JSON_THROW_ON_ERROR);
PHP;
        $output = $this->docker(['exec', '-w', '/app', '-e', 'EXECUTION_TEST_INPUT='.json_encode($input, JSON_THROW_ON_ERROR), $container, 'php', '-r', $bootstrap."\n".$code."\necho json_encode(\$result, JSON_THROW_ON_ERROR);"]);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitFor(callable $ready, string $stage, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($ready()) {
                return;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out waiting for '.$stage.'.');
    }

    /** @param list<string> $arguments */
    private function succeeds(array $arguments): bool
    {
        try {
            return (new Process(['docker', ...$arguments]))->setTimeout(10)->run() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $arguments */
    private function docker(array $arguments): string
    {
        $process = new Process(['docker', ...$arguments]);
        try {
            $exit = $process->setTimeout(120)->run();
        } catch (Throwable) {
            $this->fail('Docker '.$arguments[0].' could not finish (command arguments and output withheld).');
        }
        $this->assertSame(0, $exit, 'Docker '.$arguments[0].' failed (command arguments and output withheld).');

        return $process->getOutput();
    }

    private function cleanup(): void
    {
        $failed = [];
        foreach (array_reverse($this->containers) as $container) {
            if (! $this->succeeds(['rm', '-f', '-v', $container]) && $this->succeeds(['container', 'inspect', $container])) {
                $failed[] = $container;
            }
        }
        foreach (array_reverse($this->volumes) as $volume) {
            if (! $this->succeeds(['volume', 'rm', $volume]) && $this->succeeds(['volume', 'inspect', $volume])) {
                $failed[] = $volume;
            }
        }
        if ($this->network !== null && ! $this->succeeds(['network', 'rm', $this->network]) && $this->succeeds(['network', 'inspect', $this->network])) {
            $failed[] = $this->network;
        }
        $this->assertSame([], $failed, 'Unable to clean owned Docker test resources.');
    }
}

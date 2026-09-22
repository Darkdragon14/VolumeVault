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

    /** @var array<int, string> */
    private array $agentContainers = [];

    private function verifyRemoteStack(string $server, array $engines, array $agents, array $hosts, string $endpoint, string $password): void
    {
        $markers = ['stack-data' => 'stack-data-'.bin2hex(random_bytes(16)), 'stack-logs' => 'stack-logs-'.bin2hex(random_bytes(16))];
        $sentinel = 'foreign-stack-'.bin2hex(random_bytes(16));
        foreach ($engines as $side => $engine) {
            foreach ($markers as $volume => $marker) {
                $this->docker(['exec', $engine, 'docker', 'volume', 'create', '--label', 'com.docker.compose.project=real-stack', $volume]);
                $this->writeMarker($engine, $volume, $side === 'a' ? $marker : $sentinel);
            }
            $before = $this->hostIdentity($server, $hosts[$side]);
            $this->docker(['restart', $agents[$side]]);
            $this->waitFor(fn (): bool => $this->hostIdentity($server, $hosts[$side])['sequence'] > $before['sequence'], 'stack volume inventory on '.$side, 90);
        }

        $stack = $this->control($server, <<<'PHP'
$volumes = App\Models\DockerVolume::whereIn('docker_host_id', $input['hosts'])->whereIn('name', $input['volumes'])->get();
$destination = App\Models\BackupDestination::where('name', 'Isolated MinIO')->firstOrFail();
$summary = app(App\Actions\Backup\BackupStack::class)->handle('real-stack', ['docker_host_id' => $input['a'], 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00']]);
$jobs = App\Models\BackupJob::whereIn('volume_name', $input['volumes'])->get();
$result = ['summary' => $summary, 'jobs' => $jobs->modelKeys(), 'hosts' => $jobs->pluck('docker_host_id')->all(), 'runs' => App\Models\BackupRun::whereIn('backup_job_id', $jobs->modelKeys())->pluck('id')->all(), 'inventory' => $volumes->count(), 'projects' => $volumes->map(fn ($volume) => $volume->labels['com.docker.compose.project'] ?? null)->all()];
PHP, ['a' => $hosts['a'], 'hosts' => array_values($hosts), 'volumes' => array_keys($markers)]);
        $this->assertSame(['created' => 2, 'queued' => 2, 'skipped' => 0, 'grouped' => 0], $stack['summary']);
        $this->assertSame(4, $stack['inventory']);
        $this->assertSame(array_fill(0, 4, 'real-stack'), $stack['projects']);
        $this->assertSame([$hosts['a'], $hosts['a']], $stack['hosts']);
        foreach ($stack['runs'] as $runId) {
            $this->waitForRun($server, 'backup', $runId);
        }

        $group = $this->control($server, <<<'PHP'
$group = app(App\Actions\Backup\CreateInlineBackupGroup::class)->handle(['name' => 'Real stack group', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'notifications_enabled' => false], 'Stack execution test group.');
App\Models\BackupJob::whereIn('id', $input['jobs'])->update(['backup_job_group_id' => $group->id, 'next_run_at' => null]);
$summary = app(App\Actions\Backup\BackupStack::class)->handle('real-stack', ['docker_host_id' => $input['a']]);
$run = app(App\Actions\Backup\CreateBackupGroupRun::class)->handle($group, 'manual');
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['summary' => $summary, 'id' => $run->id, 'children' => $run->member_run_ids];
PHP, ['jobs' => $stack['jobs'], 'a' => $hosts['a']]);
        $this->assertSame(['created' => 0, 'queued' => 0, 'skipped' => 0, 'grouped' => 2], $group['summary']);
        $this->waitFor(function () use ($server, $group): bool {
            $state = $this->control($server, <<<'PHP'
Illuminate\Support\Facades\Artisan::call('volumevault:dispatch-queued-runs');
$run = App\Models\BackupGroupRun::findOrFail($input['id']);
$result = ['status' => $run->status, 'succeeded' => $run->succeeded_members];
PHP, ['id' => $group['id']]);
            $this->assertNotContains($state['status'], ['failed', 'cancelled']);

            return $state['status'] === 'success' && $state['succeeded'] === 2;
        }, 'real remote stack group completion', 180);

        $archives = $this->control($server, <<<'PHP'
$s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $input['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'backup', 'secret' => $input['password']]]);
$runs = App\Models\BackupRun::whereIn('id', $input['runs'])->orderBy('id')->get();
$checks = [];
foreach ($runs as $run) {
    $archive = gzdecode((string) $s3->getObject(['Bucket' => 'execution-backups', 'Key' => $run->backup_key])['Body']);
    $checks[] = str_contains($archive, $input['markers'][$run->source_volume_name]) && ! str_contains($archive, $input['sentinel']);
}
$children = $runs->whereNotNull('backup_group_run_id')->values();
$result = ['checks' => $checks, 'hosts' => $runs->pluck('docker_host_id')->all(), 'keys' => $runs->pluck('backup_key')->all(), 'sequential' => $children[0]->finished_at->lessThanOrEqualTo($children[1]->started_at)];
PHP, ['runs' => [...$stack['runs'], ...$group['children']], 'markers' => $markers, 'sentinel' => $sentinel, 'endpoint' => $endpoint, 'password' => $password]);
        $this->assertSame(array_fill(0, 4, true), $archives['checks']);
        $this->assertSame(array_fill(0, 4, $hosts['a']), $archives['hosts']);
        $this->assertCount(4, array_unique($archives['keys']));
        $this->assertTrue($archives['sequential']);
        foreach ($markers as $volume => $marker) {
            $this->assertSame($marker, $this->readMarker($engines['a'], $volume));
            $this->assertSame($sentinel, $this->readMarker($engines['b'], $volume));
        }
    }

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
                '-e', 'VOLUMEVAULT_ARCHIVE_RELAY_MAX_BYTES=67108864',
                '-e', 'VOLUMEVAULT_ARCHIVE_RELAY_MAX_DISK_BYTES=536870912',
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
                $this->agentContainers[$hosts[$side]] = $agents[$side];
                $this->start($agents[$side], $agentImage, [
                    '-v', $this->volume($agents[$side].'-data').':/app/storage',
                    '-e', 'DOCKER_HOST=tcp://'.$this->ip($engines[$side]).':2375',
                    '-e', 'VOLUMEVAULT_ORCHESTRATOR_URL=https://orchestrator:8443',
                    '-e', 'VOLUMEVAULT_AGENT_ENROLLMENT_TOKEN='.$enrollment['token'],
                    '-e', 'VOLUMEVAULT_AGENT_CA='.$enrollment['ca'],
                    '-e', 'VOLUMEVAULT_ARCHIVE_RELAY_MAX_BYTES=67108864',
                    '-e', 'VOLUMEVAULT_HOST_PATH_ALLOWLIST=/app/storage/destination-probe',
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

            $this->docker(['exec', $agents['a'], 'php', '-r', 'mkdir("/app/storage/destination-probe", 0700, true); file_put_contents("/app/storage/destination-probe/agent-only.tar.gz", "agent-only-archive"); chown("/app/storage/destination-probe", "www-data"); chown("/app/storage/destination-probe/agent-only.tar.gz", "www-data");']);
            $destinationStarted = microtime(true);
            $destinationOperations = $this->control($server, <<<'PHP'
$network = App\Models\BackupDestination::where('name', 'Isolated MinIO')->firstOrFail();
$local = App\Models\BackupDestination::create(['name' => 'Agent-only archives', 'provider' => 'local', 'bucket' => '', 'access_key_id' => '', 'secret_access_key' => '', 'docker_host_id' => $input['a'], 'settings' => ['archive_path' => '/app/storage/destination-probe'], 'is_active' => true]);
$operations = app(App\Services\BackupDestinations\DestinationOperations::class);
$ids = [];
foreach (['test', 'list', 'stats'] as $action) {
    $ids['network_'.$action] = $operations->create($network, $action, $input['b'])->id;
    $ids['local_'.$action] = $operations->create($local, $action)->id;
}
$result = ['ids' => $ids];
PHP, ['a' => $hosts['a'], 'b' => $hosts['b']]);
            $destinationReceipts = [];
            $this->waitFor(function () use ($server, $destinationOperations, &$destinationReceipts): bool {
                $destinationReceipts = $this->control($server, <<<'PHP'
$result = [];
foreach ($input['ids'] as $name => $id) {
    $operation = App\Models\AgentOperation::findOrFail($id);
    $result[$name] = ['status' => $operation->status, 'receipt' => $operation->result, 'host' => $operation->docker_host_id,
        'queue_seconds' => $operation->claimed_at ? $operation->claimed_at->timestamp - $operation->created_at->timestamp : null,
        'roundtrip_seconds' => $operation->completed_at ? $operation->completed_at->timestamp - $operation->created_at->timestamp : null];
}
PHP, $destinationOperations);

                return count(array_filter($destinationReceipts, fn (array $receipt): bool => $receipt['status'] === 'completed')) === 6;
            }, 'real agents test, list and measure local and MinIO destinations', 180,
                fn (): array => $this->destinationDiagnostics($server, $agents, $destinationOperations['ids']));
            if (getenv('VOLUMEVAULT_AGENT_TEST_DIAGNOSTICS') === '1') {
                $timings = [];
                foreach ($destinationReceipts as $name => $receipt) {
                    $timings[$name] = ['queue_seconds' => $receipt['queue_seconds'], 'roundtrip_seconds' => $receipt['roundtrip_seconds'], 'execution_seconds' => $receipt['receipt']['duration_seconds'] ?? null];
                }
                fwrite(STDERR, 'Destination operation timings: '.json_encode(['stage_seconds' => round(microtime(true) - $destinationStarted, 1), 'operations' => $timings], JSON_THROW_ON_ERROR).PHP_EOL);
            }
            foreach ($destinationReceipts as $name => $receipt) {
                $this->assertSame('success', $receipt['receipt']['status'], $name);
                $this->assertSame(str_starts_with($name, 'local_') ? $hosts['a'] : $hosts['b'], $receipt['host']);
            }
            $this->assertSame('agent-only.tar.gz', $destinationReceipts['local_list']['receipt']['data']['objects'][0]['key']);
            $this->assertSame(18, $destinationReceipts['local_stats']['receipt']['data']['used_bytes']);
            $this->assertSame($completedBackup['key'], $destinationReceipts['network_list']['receipt']['data']['objects'][0]['key']);
            $this->assertGreaterThan(0, $destinationReceipts['network_stats']['receipt']['data']['used_bytes']);

            $group = $this->control($server, <<<'PHP'
$group = App\Models\BackupJobGroup::create(['name' => 'Two real agents', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'status' => 'active', 'failure_policy' => 'continue', 'notifications_enabled' => false]);
$original = App\Models\BackupJob::findOrFail($input['job']);
foreach ($input['hosts'] as $host) {
    $job = $original->replicate();
    $job->forceFill(['name' => 'Grouped agent '.$host, 'docker_host_id' => $host, 'backup_job_group_id' => $group->id, 'status' => 'active', 'next_run_at' => null])->save();
}
$run = app(App\Actions\Backup\CreateBackupGroupRun::class)->handle($group, 'manual');
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id, 'children' => $run->member_run_ids, 'operations' => App\Models\AgentOperation::whereIn('backup_run_id', $run->member_run_ids)->count()];
PHP, ['job' => $backup['job'], 'hosts' => array_values($hosts)]);
            $this->assertCount(2, $group['children']);
            $this->assertSame(1, $group['operations']);
            $this->interruptControlPlaneUntilResultIsDurable($server, $agents['a'], 'backup', $group['children'][0]);
            $groupState = [];
            $this->waitFor(function () use ($server, $group, &$groupState): bool {
                $groupState = $this->control($server, <<<'PHP'
Illuminate\Support\Facades\Artisan::call('volumevault:dispatch-queued-runs');
$run = App\Models\BackupGroupRun::findOrFail($input['id']);
$result = ['status' => $run->status, 'succeeded' => $run->succeeded_members];
PHP, ['id' => $group['id']]);
                $this->assertNotContains($groupState['status'], ['failed', 'cancelled']);

                return $groupState['status'] === 'success';
            }, 'durable sequential group across two real agents', 180);
            $this->assertSame(2, $groupState['succeeded']);
            $archives = $this->control($server, <<<'PHP'
$runs = App\Models\BackupRun::whereIn('id', $input['children'])->orderBy('id')->get();
$s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $input['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'backup', 'secret' => $input['password']]]);
$markers = [];
foreach ($runs as $index => $run) {
    $archive = gzdecode((string) $s3->getObject(['Bucket' => 'execution-backups', 'Key' => $run->backup_key])['Body']);
    $markers[] = str_contains($archive, $input['markers'][$index]);
}
$result = ['markers' => $markers, 'hosts' => $runs->pluck('docker_host_id')->all(), 'keys' => $runs->pluck('backup_key')->all(), 'sequential' => $runs[0]->finished_at->lessThanOrEqualTo($runs[1]->started_at), 'operations' => App\Models\AgentOperation::whereIn('backup_run_id', $input['children'])->where('status', 'completed')->count()];
PHP, ['children' => $group['children'], 'endpoint' => $endpoint, 'password' => $password, 'markers' => [$marker, $sentinel]]);
            $this->assertSame([true, true], $archives['markers']);
            $this->assertSame(array_values($hosts), $archives['hosts']);
            $this->assertCount(2, array_unique($archives['keys']));
            $this->assertTrue($archives['sequential']);
            $this->assertSame(2, $archives['operations']);

            $this->verifyRemoteStack($server, $engines, $agents, $hosts, $endpoint, $password);

            $labelVolume = 'label-managed-data';
            $this->writeMarker($engines['a'], $labelVolume, $marker);
            $this->writeMarker($engines['b'], $labelVolume, $sentinel);
            $this->docker(['exec', $engines['a'], 'docker', 'run', '-d', '--pull=never', '--name', 'label-managed-app',
                '-v', $labelVolume.':/data', '--label', 'dev.darkdragon14.volumevault.enable=true',
                '--label', 'dev.darkdragon14.volumevault.backup.mount=/data',
                '--entrypoint', 'sh', self::OFFEN_IMAGE, '-c', 'sleep 3600']);
            $this->control($server, <<<'PHP'
$destination = App\Models\BackupDestination::where('name', 'Isolated MinIO')->firstOrFail();
App\Models\DockerLabelBackupSetting::current($input['host'])->update(['enabled' => true, 'backup_destination_id' => $destination->id]);
$result = ['enabled' => true];
PHP, ['host' => $hosts['a']]);
            $beforeLabels = $this->hostIdentity($server, $hosts['a']);
            // A normal inventory is sent every 300 seconds. Restart the isolated
            // agent to collect this newly created fixture immediately.
            $this->docker(['restart', $agents['a']]);
            $labelJob = [];
            $this->waitFor(function () use ($server, $labelVolume, $hosts, $beforeLabels, &$labelJob): bool {
                $labelJob = $this->control($server, <<<'PHP'
$jobs = App\Models\BackupJob::where('configuration_source', 'docker_label')->where('volume_name', $input['volume'])->get();
$host = App\Models\DockerHost::findOrFail($input['host']);
$result = ['id' => $jobs->first()?->id, 'hosts' => $jobs->pluck('docker_host_id')->all(), 'sequence' => $host->agent_inventory_sequence];
PHP, ['volume' => $labelVolume, 'host' => $hosts['a']]);

                return $labelJob['sequence'] > $beforeLabels['sequence'] && $labelJob['id'] !== null;
            }, 'real agent label inventory creates a host-scoped job', 90,
                fn (): array => $this->labelDiagnostics($server, $agents['a'], $hosts['a'], $labelVolume));
            $this->assertSame([$hosts['a']], $labelJob['hosts']);
            $this->assertGreaterThan($beforeLabels['sequence'], $labelJob['sequence']);
            $this->assertSame($beforeLabels['instance'], $this->hostIdentity($server, $hosts['a'])['instance']);
            $labelBackup = $this->control($server, <<<'PHP'
$job = App\Models\BackupJob::findOrFail($input['job']);
$run = app(App\Actions\Backup\CreateBackupRun::class)->handle($job, 'manual');
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id];
PHP, ['job' => $labelJob['id']]);
            $completedLabelBackup = $this->waitForRun($server, 'backup', $labelBackup['id']);
            $this->assertSame($hosts['a'], $completedLabelBackup['host']);
            $this->assertGreaterThan(0, $completedLabelBackup['size']);
            $labelArchive = $this->control($server, <<<'PHP'
$s3 = new Aws\S3\S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $input['endpoint'], 'use_path_style_endpoint' => true, 'credentials' => ['key' => 'backup', 'secret' => $input['password']]]);
$archive = gzdecode((string) $s3->getObject(['Bucket' => 'execution-backups', 'Key' => $input['key']])['Body']);
$result = ['has_a' => str_contains($archive, $input['marker']), 'has_b' => str_contains($archive, $input['sentinel'])];
PHP, ['endpoint' => $endpoint, 'password' => $password, 'key' => $completedLabelBackup['key'], 'marker' => $marker, 'sentinel' => $sentinel]);
            $this->assertSame(['has_a' => true, 'has_b' => false], $labelArchive);

            $this->control($server, <<<'PHP'
$result = app(App\Services\Agents\AgentLifecycle::class)->setMaintenance(App\Models\DockerHost::findOrFail($input['host']), true);
PHP, ['host' => $hosts['a']]);
            $this->waitFor(function () use ($server, $hosts): bool {
                return $this->control($server, <<<'PHP'
$result = app(App\Services\Agents\AgentLifecycle::class)->state(App\Models\DockerHost::findOrFail($input['host']));
PHP, ['host' => $hosts['a']])['maintenance_ready'];
            }, 'real agent maintenance acknowledgment after label backup cleanup', 90,
                fn (): array => $this->labelDiagnostics($server, $agents['a'], $hosts['a'], $labelVolume));
            $maintenance = $this->control($server, <<<'PHP'
$before = App\Models\BackupRun::count();
$blocked = false;
try {
    app(App\Actions\Backup\CreateBackupRun::class)->handle(App\Models\BackupJob::findOrFail($input['job']), 'manual');
} catch (Illuminate\Validation\ValidationException) {
    $blocked = true;
}
$result = ['blocked' => $blocked, 'no_new_run' => $before === App\Models\BackupRun::count()];
PHP, ['job' => $labelJob['id']]);
            $this->assertSame(['blocked' => true, 'no_new_run' => true], $maintenance);

            $beforeRemoval = $this->hostIdentity($server, $hosts['a']);
            $this->docker(['exec', $engines['a'], 'docker', 'rm', '-f', 'label-managed-app']);
            $this->docker(['restart', $agents['a']]);
            $removed = [];
            $this->waitFor(function () use ($server, $hosts, $labelJob, $labelVolume, $beforeRemoval, &$removed): bool {
                $removed = $this->control($server, <<<'PHP'
$job = App\Models\BackupJob::findOrFail($input['job']);
$host = App\Models\DockerHost::findOrFail($input['host']);
$result = ['sequence' => $host->agent_inventory_sequence, 'status' => $job->status, 'error' => $job->label_reconciliation_error,
    'pending' => $job->pending_label_reconciliation !== null, 'history' => $job->runs()->where('status', 'success')->count(),
    'foreign_label_jobs' => App\Models\BackupJob::where('docker_host_id', $input['other'])->where('volume_name', $input['volume'])->where('configuration_source', 'docker_label')->count()];
PHP, ['job' => $labelJob['id'], 'host' => $hosts['a'], 'other' => $hosts['b'], 'volume' => $labelVolume]);

                return $removed['sequence'] > $beforeRemoval['sequence'] && $removed['status'] === 'error';
            }, 'complete inventory retires only the removed agent label definition', 90,
                fn (): array => $this->labelDiagnostics($server, $agents['a'], $hosts['a'], $labelVolume));
            $this->assertSame('Docker label definition is no longer active.', $removed['error']);
            $this->assertFalse($removed['pending']);
            $this->assertSame(1, $removed['history']);
            $this->assertSame(0, $removed['foreign_label_jobs']);
            $this->assertSame($marker, $this->readMarker($engines['a'], $labelVolume));
            $this->assertSame($sentinel, $this->readMarker($engines['b'], $labelVolume));
            $resumed = $this->control($server, <<<'PHP'
$result = app(App\Services\Agents\AgentLifecycle::class)->setMaintenance(App\Models\DockerHost::findOrFail($input['host']), false);
PHP, ['host' => $hosts['a']]);
            $this->assertFalse($resumed['maintenance_requested']);

            $this->docker(['exec', $engines['a'], 'docker', 'volume', 'create', 'relay-archives']);
            $localBackup = $this->control($server, <<<'PHP'
$destination = App\Models\BackupDestination::create(['name' => 'Relay archives on A', 'provider' => 'docker_volume', 'docker_host_id' => $input['a'], 'bucket' => '', 'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['volume_name' => 'relay-archives'], 'is_active' => true]);
$job = App\Models\BackupJob::create(['name' => 'Actual local archive relay', 'docker_host_id' => $input['a'], 'source_type' => 'docker_volume', 'volume_name' => $input['source'], 'backup_destination_id' => $destination->id, 'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'timezone' => 'UTC', 'status' => 'active', 'stop_containers_before_backup' => false]);
$run = app(App\Actions\Backup\CreateBackupRun::class)->handle($job, 'manual');
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id, 'job' => $job->id, 'capabilities' => App\Models\DockerHost::whereIn('id', [$input['a'], $input['b']])->get()->every(fn ($host) => in_array('archive-relay-v1', $host->agent_capabilities, true))];
PHP, ['a' => $hosts['a'], 'b' => $hosts['b'], 'source' => $source]);
            $this->assertTrue($localBackup['capabilities']);
            $completedLocalBackup = $this->waitForRun($server, 'backup', $localBackup['id']);
            $originalDigest = $this->archiveDigest($engines['a'], 'relay-archives', $completedLocalBackup['key']);
            $relayRestore = $this->control($server, <<<'PHP'
$job = App\Models\BackupJob::findOrFail($input['job']);
$run = app(App\Actions\Restore\CreateRestoreRun::class)->handle($job, ['mode' => 'new_volume', 'target_docker_host_id' => $input['b'], 'target_volume_name' => 'relay-restored-on-b', 'selected_backup_key' => $input['key'], 'backup_run_id' => $input['backup']]);
app(App\Actions\Runs\DispatchQueuedRun::class)->handle($run);
$result = ['id' => $run->id, 'relay' => $run->archiveRelay->id, 'source_operation' => $run->archiveRelay->source_agent_operation_id];
PHP, ['job' => $localBackup['job'], 'b' => $hosts['b'], 'key' => $completedLocalBackup['key'], 'backup' => $localBackup['id']]);
            $completedRelay = $this->waitForRun($server, 'restore', $relayRestore['id']);
            $this->assertSame($marker, $this->readMarker($engines['b'], 'relay-restored-on-b'));
            $this->assertSame($marker, $this->readMarker($engines['a'], $source));
            $this->assertSame($sentinel, $this->readMarker($engines['b'], $source));
            $this->assertSame($originalDigest, $this->archiveDigest($engines['a'], 'relay-archives', $completedLocalBackup['key']));
            $this->assertSame('', trim($this->docker(['exec', $engines['a'], 'docker', 'container', 'ls', '-aq', '--filter', 'name=^/volumevault-destination-'.$relayRestore['source_operation'].'$'])));
            $this->assertSame('', trim($this->docker(['exec', $engines['b'], 'docker', 'container', 'ls', '-aq', '--filter', 'name=^/volumevault-restore-'])));
            foreach (['a' => $relayRestore['source_operation'], 'b' => $completedRelay['operation']] as $side => $operationId) {
                $this->waitFor(fn (): bool => $this->journal($agents[$side], $operationId)['phase'] === 'acknowledged', 'relay '.$side.' durable acknowledgement', 60);
                $removed = $this->control($agents[$side], <<<'PHP'
$result = ['removed' => ! is_dir(app(App\Services\Agents\AgentOperationStore::class)->directory($input['id']))];
PHP, ['id' => $operationId]);
                $this->assertTrue($removed['removed']);
            }
            $spool = $this->control($server, <<<'PHP'
app(App\Services\Agents\ArchiveRelays::class)->coordinate();
$relay = App\Models\ArchiveRelay::findOrFail($input['relay']);
$result = ['cleaned' => $relay->cleaned_at !== null, 'removed' => ! is_dir(app(App\Services\Agents\ArchiveRelayStorage::class)->directory($relay->id)), 'size' => $relay->size_bytes, 'sha256' => $relay->sha256];
PHP, ['relay' => $relayRestore['relay']]);
            $this->assertTrue($spool['cleaned']);
            $this->assertTrue($spool['removed']);
            $this->assertGreaterThan(0, $spool['size']);
            $this->assertSame($originalDigest, $spool['sha256']);
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
        $archive = tmpfile();
        $this->assertIsResource($archive);
        try {
            $save->setTimeout(120)->run(function (string $type, string $chunk) use ($archive): void {
                if ($type === Process::OUT) {
                    fwrite($archive, $chunk);
                }
            });
            $this->assertTrue($save->isSuccessful(), 'Exporting the local Offen image failed.');
            rewind($archive);
            $load->setInput($archive);
            $load->setTimeout(120)->run();
            $this->assertTrue($save->isSuccessful() && $load->isSuccessful(), 'Streaming the local Offen image into DinD failed.');
        } finally {
            $save->stop();
            $load->stop();
            fclose($archive);
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

    private function archiveDigest(string $engine, string $volume, string $key): string
    {
        $output = $this->docker(['exec', $engine, 'docker', 'run', '--rm', '-v', $volume.':/archives:ro',
            '--entrypoint', 'sha256sum', self::OFFEN_IMAGE, '--', '/archives/'.$key]);
        $digest = explode(' ', trim($output), 2)[0];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest);

        return $digest;
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
            if (in_array($state['status'], ['failed', 'cancelled'], true)) {
                $this->fail($kind.' #'.$id.' failed. Safe diagnostics: '.json_encode($this->runDiagnostics($server, $kind, $id), JSON_THROW_ON_ERROR));
            }

            return $state['status'] === 'success';
        }, $kind.' #'.$id.' completion through real agent polling', 180,
            fn (): array => $this->runDiagnostics($server, $kind, $id));
        $this->assertSame(1, $state['count']);

        return $state;
    }

    /** Only allowlisted lifecycle metadata leaves the orchestrator and agent journals. */
    private function runDiagnostics(string $server, string $kind, int $id): array
    {
        $central = $this->control($server, <<<'PHP'
$backup = $input['kind'] === 'backup';
$run = ($backup ? App\Models\BackupRun::class : App\Models\RestoreRun::class)::findOrFail($input['id']);
$relay = $backup ? null : $run->archiveRelay;
$operations = App\Models\AgentOperation::where($backup ? 'backup_run_id' : 'restore_run_id', $run->id)
    ->when($relay, fn ($query) => $query->orWhere('id', $relay->source_agent_operation_id))->get();
$result = [
    'run' => $run->only(['id', 'status', 'started_at', 'finished_at', 'last_heartbeat_at', 'dispatch_attempted_at', 'dispatch_published_at', 'docker_container_id', 'docker_container_cleanup_pending']),
    'relay' => $relay?->only(['id', 'status', 'source_docker_host_id', 'target_docker_host_id', 'source_agent_operation_id', 'size_bytes', 'uploaded_bytes', 'downloaded_bytes', 'expires_at', 'cleaned_at']),
    'operations' => $operations->map(fn ($operation) => $operation->only(['id', 'docker_host_id', 'kind', 'status', 'claimed_at', 'last_progress_at', 'completed_at']))->all(),
    'hosts' => App\Models\DockerHost::whereIn('id', $operations->pluck('docker_host_id'))->get()->map(fn ($host) => $host->only(['id', 'last_seen_at', 'agent_active_operations', 'maintenance_requested_at', 'agent_capabilities']))->all(),
    'queue' => ['pending' => Illuminate\Support\Facades\DB::table('jobs')->count(), 'failed' => Illuminate\Support\Facades\DB::table('failed_jobs')->count()],
];
PHP, ['kind' => $kind, 'id' => $id]);
        $agents = [];
        foreach ($central['operations'] as $operation) {
            $agent = $this->agentContainers[$operation['docker_host_id']] ?? null;
            if ($agent === null) {
                continue;
            }
            $agents[$operation['id']] = $this->control($agent, <<<'PHP'
$store = app(App\Services\Agents\AgentOperationStore::class);
try {
    $entry = $store->read($input['id']);
    $directory = $store->directory($input['id']);
    $result = ['phase' => $entry['phase'] ?? null, 'kind' => $entry['kind'] ?? null,
        'outcome' => $entry['result']['status'] ?? null, 'cleanup_complete' => $entry['result']['cleanup_complete'] ?? null,
        'helper_recorded' => isset($entry['helper_name']), 'runtime_database' => is_file($directory.'/runtime.sqlite')];
    foreach (['export.tar.gz', 'export.json', 'relay.tar.gz'] as $file) {
        $result['files'][$file] = is_file($directory.'/'.$file) ? filesize($directory.'/'.$file) : null;
    }
    if ($result['runtime_database']) {
        $db = new PDO('sqlite:'.$directory.'/runtime.sqlite');
        $db->exec('PRAGMA query_only = ON');
        foreach (['backup_runs', 'restore_runs'] as $table) {
            $columns = array_intersect(['id', 'status', 'started_at', 'finished_at', 'last_heartbeat_at', 'docker_container_id', 'docker_container_cleanup_pending'],
                array_column($db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name'));
            $result[$table] = $columns === [] ? [] : $db->query('SELECT '.implode(',', $columns).' FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $exception) {
    $result = ['exception_class' => get_class($exception)];
}
PHP, ['id' => $operation['id']]);
        }

        return ['central' => $central, 'agents' => $agents];
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
        $failure = <<<'PHP'
} catch (Throwable $exception) {
    $categories = [];
    foreach (['no such table', 'database is locked', 'readonly database', 'Permission denied', 'No application encryption key', 'Connection refused'] as $category) {
        if (str_contains($exception->getMessage(), $category)) {
            $categories[] = $category;
        }
    }
    echo json_encode(['_control_error' => get_class($exception), 'code' => (string) $exception->getCode(),
        'source' => basename($exception->getFile()).':'.$exception->getLine(), 'categories' => $categories], JSON_THROW_ON_ERROR);
}
PHP;
        $output = $this->docker(['exec', '-w', '/app', '-e', 'EXECUTION_TEST_INPUT='.json_encode($input, JSON_THROW_ON_ERROR), $container, 'php', '-r', "try {\n".$bootstrap."\n".$code."\necho json_encode(\$result, JSON_THROW_ON_ERROR);\n".$failure]);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('_control_error', $result, 'Isolated control failed: '.json_encode(isset($result['_control_error']) ? $result : [], JSON_THROW_ON_ERROR));

        return $result;
    }

    private function waitFor(callable $ready, string $stage, int $seconds, ?callable $diagnostics = null): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($ready()) {
                return;
            }
            usleep(500000);
        } while (microtime(true) < $deadline);
        $details = $diagnostics === null ? '' : ' Diagnostics: '.json_encode($diagnostics(), JSON_THROW_ON_ERROR);
        $this->fail('Timed out waiting for '.$stage.'.'.$details);
    }

    /** Only status fields, validation field names and fixed diagnostics may leave the agents. */
    private function destinationDiagnostics(string $server, array $agents, array $ids): array
    {
        $central = $this->control($server, <<<'PHP'
$result = [];
foreach ($input['ids'] as $name => $id) {
    $operation = App\Models\AgentOperation::findOrFail($id);
    $host = App\Models\DockerHost::findOrFail($operation->docker_host_id);
    $safeErrors = ['Destination operation failed. Check credentials, reachability and host policy.', 'Destination operation rejected by agent-local policy.'];
    $result[$name] = [...$operation->only(['id', 'status', 'claimed_at', 'last_progress_at', 'completed_at']),
        'outcome' => $operation->result['status'] ?? null,
        'error' => in_array($operation->result['error_message'] ?? null, $safeErrors, true) ? $operation->result['error_message'] : null,
        'host' => $host->only(['last_seen_at', 'agent_active_operations', 'agent_capabilities', 'maintenance_requested_at'])];
}
PHP, ['ids' => $ids]);
        $journals = [];
        foreach ($agents as $side => $agent) {
            $journals[$side] = $this->control($agent, <<<'PHP'
$store = app(App\Services\Agents\AgentOperationStore::class);
$result = [];
foreach ($input['ids'] as $name => $id) {
    try {
        $entry = $store->read($id);
        $state = ['phase' => $entry['phase'] ?? null, 'outcome' => $entry['result']['status'] ?? null,
            'cleanup_complete' => $entry['result']['cleanup_complete'] ?? null,
            'runtime_database' => is_file($store->directory($id).'/runtime.sqlite')];
        $safeErrors = ['Destination operation failed. Check credentials, reachability and host policy.', 'Destination operation rejected by agent-local policy.'];
        $state['error'] = in_array($entry['result']['error_message'] ?? null, $safeErrors, true) ? $entry['result']['error_message'] : null;
        if (isset($entry['spec'], $entry['result'])) {
            try {
                app(App\Services\BackupDestinations\DestinationOperations::class)->validateResult($entry['spec']['action'], $entry['result'], $entry['spec']['limit']);
                $state['result_valid'] = true;
            } catch (Illuminate\Validation\ValidationException $exception) {
                $state['validation_fields'] = array_keys($exception->errors());
            } catch (Throwable $exception) {
                $state['validation_exception'] = get_class($exception);
            }
        }
        $result[$name] = $state;
    } catch (Throwable $exception) {
        $result[$name] = ['exception_class' => get_class($exception),
            'journal_unreadable' => $exception->getMessage() === 'Operation journal is unreadable; recovery required.'];
    }
}
PHP, ['ids' => $ids]);
            $logs = new Process(['docker', 'logs', '--tail', '50', $agent]);
            $logs->setTimeout(10)->run();
            foreach (['Agent cycle failed; check connectivity, enrollment, and Docker availability.', 'Agent state is unavailable; exiting to reload the persisted identity.'] as $message) {
                $journals[$side]['log_counts'][$message] = substr_count($logs->getOutput().$logs->getErrorOutput(), $message);
            }
        }

        return ['central' => $central, 'agents' => $journals];
    }

    /** Only allowlisted metadata and known credential-free log messages leave the isolated runtime. */
    private function labelDiagnostics(string $server, string $agent, int $hostId, string $volume): array
    {
        $serverState = $this->control($server, <<<'PHP'
$host = App\Models\DockerHost::findOrFail($input['host']);
$settings = App\Models\DockerLabelBackupSetting::current($host->id);
$result = [
    'host' => $host->only(['agent_inventory_sequence', 'last_inventory_at', 'last_seen_at', 'docker_status', 'agent_capabilities']),
    'settings' => $settings->only(['enabled', 'last_synced_at', 'last_sync_error']),
    'maintenance' => app(App\Services\Agents\AgentLifecycle::class)->state($host),
    'volume_present' => App\Models\DockerVolume::where('docker_host_id', $host->id)->where('name', $input['volume'])->where('exists', true)->exists(),
    'jobs' => App\Models\BackupJob::where('docker_host_id', $host->id)->where('volume_name', $input['volume'])->get(['id', 'configuration_source', 'status', 'label_reconciliation_error'])->toArray(),
];
PHP, ['host' => $hostId, 'volume' => $volume]);
        $agentState = $this->control($agent, <<<'PHP'
try {
    $inventory = app(App\Actions\Docker\CollectAgentInventory::class)->handle(fn () => null);
    $result = ['collection_succeeded' => true, 'volumes' => count($inventory['volumes']), 'containers' => count($inventory['containers']),
        'label_inventory_complete' => $inventory['label_inventory']['complete'] ?? null,
        'label_containers' => count($inventory['label_inventory']['containers'] ?? []),
        'fixture' => collect($inventory['label_inventory']['containers'] ?? [])->where('name', 'label-managed-app')->map(fn ($container) => [
            'name' => $container['name'], 'running' => $container['running'], 'mounts' => $container['mounts'],
            'enabled' => ($container['labels']['dev.darkdragon14.volumevault.enable'] ?? null) === 'true',
            'mount_selector_matches' => ($container['labels']['dev.darkdragon14.volumevault.backup.mount'] ?? null) === '/data',
        ])->values()->all()];
} catch (Throwable $exception) {
    $result = ['collection_succeeded' => false, 'exception_class' => get_class($exception)];
}
PHP);
        $logs = new Process(['docker', 'logs', '--tail', '50', $agent]);
        $logs->setTimeout(10)->run();
        $messages = [];
        foreach (['Docker inventory unavailable.', 'Agent cycle failed; check connectivity, enrollment, and Docker availability.', 'Agent state is unavailable; exiting to reload the persisted identity.', 'Agent protocol is incompatible with the orchestrator; install a compatible agent image.'] as $message) {
            $messages[$message] = substr_count($logs->getOutput().$logs->getErrorOutput(), $message);
        }

        return ['server' => $serverState, 'agent_inventory' => $agentState, 'agent_log_message_counts' => $messages];
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

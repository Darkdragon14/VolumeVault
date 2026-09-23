<?php

namespace Tests\Feature;

use App\Actions\Backup\RunBackup;
use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Actions\Docker\ClearDockerVolume;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\CreateDockerVolume;
use App\Actions\Docker\ExportRelayArchive;
use App\Actions\Docker\FindContainersUsingVolume;
use App\Actions\Docker\InspectDockerVolume;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Docker\RunBackupContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Docker\StartDockerContainers;
use App\Actions\Docker\StopDockerContainers;
use App\Actions\Docker\VerifyRestoreArchive;
use App\Actions\Restore\RunRestore;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use App\Services\Agents\AgentOperationRuntime;
use App\Services\Agents\AgentOperationStore;
use App\Services\Agents\AgentOperationSupervisor;
use App\Services\Agents\AgentState;
use App\Services\Agents\AgentTlsIdentity;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AgentOperationRuntimeTest extends TestCase
{
    private string $root;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = storage_path();
        $this->root = sys_get_temp_dir().'/agent-operation-test-'.Str::uuid();
        config(['volumevault.agents.client.state_directory' => $this->root]);
        $this->mock(DockerProcess::class)->shouldReceive('run')->never();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        Model::encryptUsing(null);
        app()->useStoragePath($this->storage);
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public static function operation(string $kind = 'backup'): array
    {
        return [
            'id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => $kind,
            'spec' => [
                'version' => 1,
                'job' => ['name' => 'Remote source', 'source_type' => 'docker_volume', 'volume_name' => 'source-on-a', 'stop_containers_before_backup' => false, 'timezone' => 'UTC'],
                'destination' => ['name' => 'Shared archives', 'provider' => 'custom_s3', 'endpoint' => 'https://93.184.216.34', 'bucket' => 'backups', 'access_key_id' => 'test-access', 'secret_access_key' => 'test-secret', 'settings' => [], 'secrets' => []],
                'run' => $kind === 'backup'
                    ? ['backup_filename' => 'central-unique.tar.gz']
                    : ['selected_backup_key' => 'central-unique.tar.gz', 'source_volume_name' => 'source-on-a', 'target_volume_name' => 'target-on-b', 'mode' => 'new_volume', 'backup_before_overwrite' => false],
            ],
        ];
    }

    public function test_backup_uses_private_database_local_key_and_existing_pipeline_with_metadata(): void
    {
        $operation = self::operation();
        $centralKey = config('app.key');
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->with('source-on-a')->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturnUsing(function (BackupRun $run): DockerProcessResult {
            $this->assertSame('central-unique.tar.gz', $run->backup_filename);
            $this->assertSame(1, $run->docker_host_id);

            return new DockerProcessResult([], 0, 'Uploaded using test-secret', '');
        });
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andReturn(['key' => 'backups/central-unique.tar.gz', 'size' => 123]);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $result = app(AgentOperationSupervisor::class)->pendingResult()['result'];
        $this->assertSame('success', $result['status']);
        $this->assertSame('backups/central-unique.tar.gz', $result['backup_key']);
        $this->assertSame(123, $result['backup_size_bytes']);
        $this->assertStringNotContainsString('test-secret', $result['logs']);
        $this->assertNotSame($centralKey, config('app.key'));
        $this->assertSame($store->directory($operation['id']).'/runtime.sqlite', DB::connection()->getDatabaseName());
        $this->assertStringNotContainsString('test-secret', DB::table('backup_destinations')->value('secret_access_key'));
        $this->assertSame('test-secret', BackupDestination::first()->secret_access_key);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame(1, BackupRun::count());
    }

    public function test_metadata_only_operations_survive_native_worker_restart_without_backup_execution(): void
    {
        $directory = $this->root.'/archives';
        File::ensureDirectoryExists($directory);
        $store = app(AgentOperationStore::class);
        $spec = ['version' => 1, 'action' => 'metadata', 'limit' => 1,
            'destination' => ['name' => 'Archive', 'provider' => 'local', 'settings' => ['archive_path' => $directory], 'secrets' => []],
            'archive' => ['filename' => 'frozen.tar.gz', 'key' => null, 'size' => null]];
        $execute = function (string $id): void {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('artisan'), 'volumevault:agent-execute', $id], base_path(), [
                'VOLUMEVAULT_AGENT_STATE_DIRECTORY' => $this->root,
                'VOLUMEVAULT_HOST_PATH_ALLOWLIST' => $this->root,
                'APP_ENV' => 'testing',
            ]);
            $process->setTimeout(60)->mustRun();
        };
        $first = ['id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => 'destination', 'spec' => $spec];
        $store->accept($first);
        $execute($first['id']);
        $this->assertSame('failed', $store->read($first['id'])['result']['status']);
        File::put($directory.'/frozen.tar.gz', gzencode('archive contents'));
        $second = [...$first, 'id' => (string) Str::uuid()];
        $store->acknowledge($first['id']);
        $store->accept($second);
        $execute($second['id']);
        $result = $store->read($second['id'])['result'];
        $this->assertSame('success', $result['status']);
        $this->assertSame('frozen.tar.gz', $result['data']['backup_key']);
        $this->assertSame(filesize($directory.'/frozen.tar.gz'), $result['data']['backup_size_bytes']);
        $execute($second['id']);
        $this->assertSame($result, $store->read($second['id'])['result']);
        $database = new \PDO('sqlite:'.$store->directory($second['id']).'/runtime.sqlite');
        $this->assertSame(0, (int) $database->query('select count(*) from backup_runs')->fetchColumn());
    }

    public function test_export_runtime_uploads_with_real_client_from_child_state_while_parent_loop_is_open(): void
    {
        config([
            'volumevault.agents.url' => 'https://vault.example:8443',
            'volumevault.agents.tls_directory' => $this->root.'/tls',
            'volumevault.agents.client.url' => 'https://vault.example:8443',
            'volumevault.agents.client.enrollment_token' => Str::uuid().'.'.bin2hex(random_bytes(32)),
        ]);
        config(['volumevault.agents.client.ca_certificate' => base64_encode((new AgentTlsIdentity)->caCertificate())]);
        $parent = new AgentState;
        $parent->open();
        try {
            $parent->markEnrolled();
            $identity = $parent->identity();
            $before = file_get_contents($this->root.'/state.json');
            $operation = [
                'id' => (string) Str::uuid(), 'token' => str_repeat('a', 64), 'kind' => 'archive_export',
                'spec' => ['version' => 1, 'destination' => [
                    'name' => 'Source archive', 'provider' => 'docker_volume', 'bucket' => '',
                    'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['volume_name' => 'archives'], 'secrets' => [],
                ], 'relay' => ['id' => (string) Str::uuid(), 'key' => 'backup.tar.gz', 'max_bytes' => 1048576]],
            ];
            $archive = gzencode('worker export marker');
            $store = app(AgentOperationStore::class);
            $store->accept($operation);
            $this->mock(ExportRelayArchive::class)->shouldReceive('handle')->once()->andReturnUsing(
                fn (BackupDestination $destination, string $key, string $path) => file_put_contents($path, $archive));
            $this->mock(CleanupDestinationOperationHelper::class)->shouldReceive('handle')->once()->andReturnTrue();
            Http::preventStrayRequests();
            Http::fake(function ($request, array $options) use ($operation, $identity, $parent, $archive) {
                $this->assertSame('https://vault.example:8443/agent/v1/operations/'.$operation['id'].'/relay', $request->url());
                $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.$identity['host_uuid'].'.'.$identity['credential']));
                $this->assertSame($parent->caPath(), $options['verify']);
                $this->assertSame($archive, base64_decode($request['chunk'], true));

                return Http::response(['offset' => strlen($archive)]);
            });
            $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
            $this->assertSame('finished', $store->read($operation['id'])['phase']);
            $this->assertSame('success', $store->read($operation['id'])['result']['status']);
            Http::assertSentCount(1);
            $this->assertSame($before, file_get_contents($this->root.'/state.json'));
        } finally {
            $parent->close();
        }
    }

    public function test_destination_runtime_uses_private_database_and_durable_receipt_before_cleanup(): void
    {
        $operation = self::operation();
        $operation['kind'] = 'destination';
        $operation['spec'] = ['version' => 1, 'destination' => $operation['spec']['destination'], 'action' => 'list', 'limit' => 1000, 'cursor' => null];
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->mock(DestinationStorage::class)->shouldReceive('listBackupObjectsPage')->once()->andReturn([
            'objects' => [['key' => 'test-access.tar.gz', 'display_name' => 'test-access.tar.gz', 'size' => 17, 'last_modified' => null]], 'next_cursor' => null,
        ]);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $receipt = $store->read($operation['id']);
        $this->assertSame('success', $receipt['result']['status']);
        $this->assertSame('test-access.tar.gz', $receipt['result']['data']['objects'][0]['key']);
        $this->assertFileExists($store->directory($operation['id']).'/runtime.sqlite');
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $store->acknowledge($operation['id']);
        $this->assertSame('acknowledged', $store->read($operation['id'])['phase']);
        $this->assertDirectoryDoesNotExist($store->directory($operation['id']));
        $store->accept($operation);
        $this->assertSame([], $store->active());
    }

    public function test_destination_local_policy_failure_is_durably_reported_without_leaking_credentials(): void
    {
        $operation = self::operation();
        $operation['kind'] = 'destination';
        $operation['spec'] = ['version' => 1, 'destination' => [...$operation['spec']['destination'], 'endpoint' => 'http://127.0.0.1'], 'action' => 'test', 'limit' => 1, 'cursor' => null];
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->mock(DestinationStorage::class)->shouldNotReceive('testReadOnly');
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $receipt = $store->read($operation['id']);
        $this->assertSame('failed', $receipt['result']['status']);
        $this->assertTrue($receipt['result']['cleanup_complete']);
        $this->assertStringNotContainsString('test-secret', json_encode($receipt['result']));
    }

    public function test_destination_helper_identity_is_durable_and_recovery_never_replays_a_timed_out_helper(): void
    {
        $operation = self::operation();
        $operation['kind'] = 'destination';
        $operation['spec'] = ['version' => 1, 'destination' => ['name' => 'Docker archives', 'provider' => 'docker_volume', 'settings' => ['volume_name' => 'archives']], 'action' => 'stats', 'limit' => 1000, 'cursor' => null];
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $process = new class($store, $operation['id']) extends DockerProcess
        {
            public int $launches = 0;

            public bool $removeWorks = false;

            public bool $confirmedAbsent = false;

            public function __construct(private AgentOperationStore $store, private string $id) {}

            public function run(array $command, int $timeout = 300, array $environment = []): DockerProcessResult
            {
                if ($command[1] === 'run') {
                    $receipt = $this->store->read($this->id);
                    Assert::assertSame('executing', $receipt['phase']);
                    Assert::assertContains($receipt['helper_name'], $command);
                    $this->launches++;

                    return new DockerProcessResult($command, 124, '', 'test-secret timeout', true);
                }
                if ($command[1] === 'rm' && ! $this->removeWorks) {
                    return new DockerProcessResult($command, 1, '', 'daemon unavailable');
                }
                if ($command[1] === 'container') {
                    return $this->confirmedAbsent ? new DockerProcessResult($command, 1, '', 'Error: No such container: '.end($command))
                        : new DockerProcessResult($command, 0, '[{}]', '');
                }

                return new DockerProcessResult($command, 0, '{}', '');
            }
        };
        app()->instance(DockerProcess::class, $process);
        $this->assertFalse(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame('executing', $store->read($operation['id'])['phase']);
        $this->assertArrayNotHasKey('result', $store->read($operation['id']));
        $process->removeWorks = true;
        $this->assertFalse(app(AgentOperationRuntime::class)->handle($operation['id']));
        $process->confirmedAbsent = true;
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame(1, $process->launches);
        $receipt = $store->read($operation['id']);
        $this->assertSame('failed', $receipt['result']['status']);
        $this->assertTrue($receipt['result']['cleanup_complete']);
        $this->assertStringNotContainsString('test-secret', json_encode($receipt['result']));
        $store->acknowledge($operation['id']);
        $this->assertDirectoryDoesNotExist($store->directory($operation['id']));
    }

    public function test_restore_on_b_does_not_inspect_original_source_on_a(): void
    {
        $operation = self::operation('restore');
        app(AgentOperationStore::class)->accept($operation);
        $ownershipToken = null;
        $inspector = $this->mock(InspectDockerVolume::class);
        $inspector->shouldReceive('handle')->once()->with('target-on-b')->andThrow(new RuntimeException('No such volume'));
        $this->mock(CreateDockerVolume::class)->shouldReceive('handle')->once()->with('target-on-b', Mockery::type('string'))
            ->andReturnUsing(function (string $name, string $token) use (&$ownershipToken): void {
                $ownershipToken = $token;
            });
        $inspector->shouldReceive('handle')->once()->with('target-on-b')->andReturnUsing(function () use (&$ownershipToken): array {
            $this->assertNotNull($ownershipToken);

            return ['labels' => [CreateDockerVolume::RESTORE_OWNERSHIP_LABEL => $ownershipToken]];
        });
        $this->mock(DestinationStorage::class)->shouldReceive('download')->once()->andReturnUsing(function ($destination, $key, $path): void {
            file_put_contents($path, 'verified archive fixture');
        });
        $this->mock(VerifyRestoreArchive::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->mock(RunRestoreContainer::class)->shouldReceive('handle')->once()->andReturnUsing(function (RestoreRun $run): DockerProcessResult {
            $this->assertSame('source-on-a', $run->source_volume_name);
            $this->assertSame('target-on-b', $run->target_volume_name);

            return new DockerProcessResult([], 0, 'Restored', '');
        });
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $result = app(AgentOperationSupervisor::class)->pendingResult()['result'];
        $this->assertSame('success', $result['status']);
        $this->assertSame('target-on-b', $result['target_volume_name']);
        $this->assertTrue($result['cleanup_complete']);
    }

    public function test_recovery_watches_live_helper_then_reconciles_and_restarts_without_replay(): void
    {
        $operation = self::operation();
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturnUsing(function (BackupRun $run): never {
            $run->update(['docker_container_id' => 'volumevault-backup-1-unique', 'stopped_container_ids' => ['application'], 'last_heartbeat_at' => now()->subMinutes(3)]);
            throw new RuntimeException('Interrupted test-secret');
        });
        $alive = true;
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->andReturnUsing(function () use (&$alive): bool {
            return $alive;
        });
        $this->mock(RemoveDockerContainer::class)->shouldReceive('handle')->atLeast()->once();
        $this->mock(StartDockerContainers::class)->shouldReceive('handle')->once()->with(['application']);
        // RunBackup catches the interruption and marks terminal; its persisted
        // stopped list still belongs to recovery, which must wait for the helper.
        $runtime = app(AgentOperationRuntime::class);
        $this->assertFalse($runtime->handle($operation['id']));
        $this->assertNull(app(AgentOperationSupervisor::class)->pendingResult());
        BackupRun::first()->update(['status' => 'running', 'last_heartbeat_at' => now()->subMinutes(3)]);
        $alive = false;
        $this->assertTrue($runtime->handle($operation['id']));
        $result = app(AgentOperationSupervisor::class)->pendingResult()['result'];
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['cleanup_complete']);
        $this->assertEmpty(BackupRun::first()->stopped_container_ids);
        $this->assertStringNotContainsString('test-secret', json_encode($result));
    }

    public function test_metadata_lookup_failure_does_not_invent_archive_key(): void
    {
        $operation = self::operation();
        app(AgentOperationStore::class)->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andThrow(new RuntimeException('test-secret'));
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertNull(app(AgentOperationSupervisor::class)->pendingResult()['result']['backup_key']);
    }

    #[DataProvider('interruptedClientResults')]
    public function test_real_backup_cleanup_blocks_restart_until_helper_recovery(bool $throws): void
    {
        Exceptions::fake();
        $operation = self::operation();
        $operation['spec']['job']['stop_containers_before_backup'] = true;
        app(AgentOperationStore::class)->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(FindContainersUsingVolume::class)->shouldReceive('handle')->once()->andReturn([
            ['id' => 'application', 'name' => 'application', 'state' => 'running'],
        ]);
        $this->mock(StopDockerContainers::class)->shouldReceive('handle')->once();
        $events = [];
        $docker = $this->mock(DockerProcess::class)->makePartial();
        $docker->shouldReceive('run')->once()->andReturnUsing(function (array $command) use ($throws, &$events): DockerProcessResult {
            $this->assertSame(['docker', 'run', '--rm'], array_slice($command, 0, 3));
            $this->assertTrue(BackupRun::first()->docker_container_cleanup_pending);
            $events[] = 'run';
            if ($throws) {
                throw new RuntimeException('client disconnected');
            }

            return new DockerProcessResult($command, 1, '', 'client disconnected');
        });
        $alive = true;
        $this->mock(RemoveDockerContainer::class)->shouldReceive('handle')->twice()->andReturnUsing(function () use (&$alive, &$events): void {
            $events[] = 'remove';
            if ($alive) {
                throw new RuntimeException('helper removal failed');
            }
        });
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->andReturnUsing(function () use (&$alive): bool {
            return $alive;
        });
        $this->mock(StartDockerContainers::class)->shouldReceive('handle')->once()->with(['application'])->andReturnUsing(function () use (&$events): void {
            $events[] = 'start';
            $this->assertFalse(BackupRun::first()->docker_container_cleanup_pending);
        });

        $runtime = app(AgentOperationRuntime::class);
        $this->assertFalse($runtime->handle($operation['id']));
        $this->assertSame(['run', 'remove'], $events);
        $this->assertTrue(BackupRun::first()->docker_container_cleanup_pending);
        $this->assertSame(['application'], BackupRun::first()->stopped_container_ids);
        $this->assertNull(app(AgentOperationSupervisor::class)->pendingResult());

        $alive = false;
        $this->assertTrue($runtime->handle($operation['id']));
        $this->assertSame(['run', 'remove', 'remove', 'start'], $events);
        $this->assertEmpty(BackupRun::first()->stopped_container_ids);
        $this->assertSame('failed', app(AgentOperationSupervisor::class)->pendingResult()['result']['status']);
        $this->assertTrue($runtime->handle($operation['id']));
        $this->assertSame(['run', 'remove', 'remove', 'start'], $events);
    }

    public static function interruptedClientResults(): array
    {
        return ['failed result' => [false], 'exception' => [true]];
    }

    public function test_in_place_safety_backup_uses_b_target_before_clear_and_extract(): void
    {
        $operation = self::operation('restore');
        $operation['spec']['run']['mode'] = 'inplace';
        $operation['spec']['run']['confirmation_text'] = 'target-on-b';
        $operation['spec']['run']['backup_before_overwrite'] = true;
        $operation['spec']['safety_destination'] = [...$operation['spec']['destination'], 'name' => 'Safety destination', 'bucket' => 'safety-bucket', 'secret_access_key' => 'safety-only-secret'];
        app(AgentOperationStore::class)->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->twice()->with('target-on-b')->andReturn([]);
        $this->mock(DestinationStorage::class)->shouldReceive('download')->once()->andReturnUsing(function ($destination, $key, $path): void {
            $this->assertSame('backups', $destination->bucket);
            file_put_contents($path, 'archive');
        });
        $this->mock(VerifyRestoreArchive::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturnUsing(function (BackupRun $run): DockerProcessResult {
            $this->assertSame('target-on-b', $run->executionJob()->volume_name);
            $this->assertSame('pre_restore', $run->trigger);
            $this->assertSame('safety-bucket', $run->executionJob()->destination->bucket);
            $this->assertSame('safety-only-secret', $run->executionJob()->destination->secret_access_key);

            return new DockerProcessResult([], 0, 'safety-only-secret', '');
        });
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andReturn(['key' => 'safety.tar.gz', 'size' => 20]);
        $this->mock(ClearDockerVolume::class)->shouldReceive('handle')->once()->andReturnUsing(function ($volume): void {
            $this->assertSame('target-on-b', $volume);
            $this->assertSame('success', BackupRun::first()->status);
            $this->assertSame('safety.tar.gz', BackupRun::first()->backup_key);
        });
        $this->mock(RunRestoreContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame('success', app(AgentOperationSupervisor::class)->pendingResult()['result']['status']);
        $this->assertStringNotContainsString('safety-only-secret', (string) BackupRun::first()->logs);
        $this->assertStringNotContainsString('safety-only-secret', (string) DB::table('backup_destinations')->where('bucket', 'safety-bucket')->value('secret_access_key'));
        $supervisor = app(AgentOperationSupervisor::class);
        $receipt = $supervisor->pendingResult();
        $filename = BackupRun::first()->backup_filename;
        $this->assertSame([
            'status' => 'success',
            'backup_filename' => $filename,
            'backup_key' => 'safety.tar.gz',
            'backup_size_bytes' => 20,
            'duration_seconds' => BackupRun::first()->duration_seconds,
            'error_message' => null,
        ], $receipt['result']['safety_backup']);
        $this->assertSame($receipt, (new AgentOperationSupervisor(new AgentOperationStore))->pendingResult());
        DB::purge('sqlite');
        $supervisor->acknowledge($operation['id']);
        $this->assertDirectoryDoesNotExist(app(AgentOperationStore::class)->directory($operation['id']));
        $this->assertSame('safety.tar.gz', $receipt['result']['safety_backup']['backup_key']);
        $this->assertSame($filename, $receipt['result']['safety_backup']['backup_filename']);
    }

    public function test_safety_receipt_redacts_diagnostics_without_rewriting_archive_identity(): void
    {
        $operation = self::operation('restore');
        app(AgentOperationStore::class)->accept($operation);
        $this->mock(RunRestore::class)->shouldReceive('handle')->once()->andReturnUsing(function (RestoreRun $run): void {
            $backup = BackupRun::create([
                'backup_job_id' => $run->backup_job_id,
                'status' => 'failed', 'trigger' => 'pre_restore',
                'backup_filename' => 'safety-test-secret.tar.gz',
                'source_volume_name' => 'target-test-secret',
                'error_message' => 'Upload failed: test-secret',
                'backup_key' => null, 'backup_size_bytes' => null,
                'duration_seconds' => 5, 'finished_at' => now(),
            ]);
            $run->update(['pre_restore_backup_run_id' => $backup->id, 'status' => 'failed', 'finished_at' => now()]);
        });
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $safety = app(AgentOperationSupervisor::class)->pendingResult()['result']['safety_backup'];
        $this->assertSame('failed', $safety['status']);
        $this->assertSame('safety-test-secret.tar.gz', $safety['backup_filename']);
        $this->assertArrayNotHasKey('source_volume_name', $safety);
        $this->assertSame('Upload failed: [redacted]', $safety['error_message']);
        $this->assertNull($safety['backup_key']);
        $this->assertNull($safety['backup_size_bytes']);
        $this->assertSame(5, $safety['duration_seconds']);
        $this->assertStringNotContainsString('test-secret', $safety['error_message']);
    }

    public function test_local_policy_failure_is_durable_and_duplicate_after_ack_ignores_policy(): void
    {
        $operation = self::operation();
        $operation['spec']['destination']['endpoint'] = 'http://169.254.169.254';
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->assertSame('accepted', $store->read($operation['id'])['phase']);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $result = app(AgentOperationSupervisor::class)->pendingResult()['result'];
        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['cleanup_complete']);
        $this->assertSame(0, BackupRun::count());
        $store->acknowledge($operation['id']);
        $store->accept($operation);
        $this->assertSame('acknowledged', $store->read($operation['id'])['phase']);
    }

    public function test_recovery_resumes_same_provably_unstarted_queued_run(): void
    {
        $operation = self::operation();
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->mock(RunBackup::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('Crash before claim'));
        $runtime = app(AgentOperationRuntime::class);
        $this->assertFalse($runtime->handle($operation['id']));
        $id = BackupRun::first()->id;
        $this->assertSame('executing', $store->read($operation['id'])['phase']);
        $this->assertSame('queued', BackupRun::first()->status);
        $this->app->forgetInstance(RunBackup::class);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andReturn(['key' => 'original.tar.gz']);
        $this->assertTrue($runtime->handle($operation['id']));
        $this->assertSame($id, BackupRun::sole()->id);
        $this->assertSame('success', BackupRun::sole()->status);
        $this->assertTrue($runtime->handle($operation['id']));
    }

    public function test_blocked_safety_destination_fails_before_restore_or_safety_backup(): void
    {
        $operation = self::operation('restore');
        $operation['spec']['run'] = [...$operation['spec']['run'], 'mode' => 'inplace', 'backup_before_overwrite' => true, 'confirmation_text' => 'target-on-b'];
        $operation['spec']['safety_destination'] = [...$operation['spec']['destination'], 'endpoint' => 'http://127.0.0.1'];
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame('failed', $store->read($operation['id'])['result']['status']);
        $this->assertSame(0, RestoreRun::count());
        $this->assertSame(0, BackupRun::count());
    }

    public function test_local_path_policy_rejection_is_reported_after_acceptance(): void
    {
        config(['volumevault.host_path_allowlist' => []]);
        $operation = self::operation();
        $operation['spec']['destination'] = ['name' => 'Disallowed local archive', 'provider' => 'local', 'settings' => ['archive_path' => '/not-allowed/archive']];
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $this->assertTrue(app(AgentOperationRuntime::class)->handle($operation['id']));
        $this->assertSame('failed', $store->read($operation['id'])['result']['status']);
        $this->assertSame(0, BackupRun::count());
    }

    public function test_executing_operation_with_lost_database_is_never_recreated(): void
    {
        $operation = self::operation();
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $store->markExecuting($operation['id']);
        $this->expectExceptionMessage('Operation database is missing; refusing to replay.');
        app(AgentOperationRuntime::class)->handle($operation['id']);
    }

    public function test_terminal_run_without_result_recovers_cleanup_instead_of_reexecuting(): void
    {
        $operation = self::operation();
        app(AgentOperationStore::class)->accept($operation);
        $this->mock(InspectDockerVolume::class)->shouldReceive('handle')->once()->andReturn([]);
        $this->mock(RunBackupContainer::class)->shouldReceive('handle')->once()->andReturnUsing(function (BackupRun $run): DockerProcessResult {
            $run->update(['docker_container_id' => 'volumevault-backup-1-finished', 'stopped_container_ids' => ['app'], 'docker_container_cleanup_pending' => true]);

            return new DockerProcessResult([], 0, 'Archive completed', '');
        });
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->andReturn(false);
        $attempts = 0;
        $this->mock(RemoveDockerContainer::class)->shouldReceive('handle')->andReturnUsing(function () use (&$attempts): void {
            if ($attempts++ === 0) {
                throw new RuntimeException('Docker unavailable during cleanup');
            }
        });
        $this->mock(StartDockerContainers::class)->shouldReceive('handle')->once()->with(['app']);
        $this->mock(ListBackupObjects::class)->shouldReceive('findByFilename')->once()->andReturn(['key' => 'confirmed.tar.gz', 'size' => 12]);
        $runtime = app(AgentOperationRuntime::class);
        try {
            $runtime->handle($operation['id']);
            $this->fail('Cleanup failure must not publish a result');
        } catch (RuntimeException) {
            $this->assertNull(app(AgentOperationSupervisor::class)->pendingResult());
            $this->assertSame('success', BackupRun::first()->status);
        }
        $this->assertTrue($runtime->handle($operation['id']));
        $result = app(AgentOperationSupervisor::class)->pendingResult()['result'];
        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['cleanup_complete']);
        $this->assertSame('confirmed.tar.gz', $result['backup_key']);
    }
}

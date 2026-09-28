<?php

namespace Tests\Feature;

use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\AgentOperationStore;
use App\Services\Agents\AgentOperationSupervisor;
use App\Services\Agents\AgentStateException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentOperationStoreSupervisorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/agent-store-test-'.Str::uuid();
        config(['volumevault.agents.client.state_directory' => $this->root, 'app.key' => '']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_encrypted_acceptance_duplicates_immutable_result_and_ack_tombstone(): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $store = app(AgentOperationStore::class);
        $supervisor = new AgentOperationSupervisor($store);
        $supervisor->accept($operation);
        $supervisor->accept($operation);
        $this->assertSame(1, $supervisor->activeCount());
        $this->assertStringNotContainsString('test-secret', file_get_contents($store->directory($operation['id']).'.json'));
        $this->assertSame(0700, fileperms($this->root) & 0777);
        $this->assertSame(0600, fileperms($this->root.'/operations.key') & 0777);
        $result = ['status' => 'success', 'cleanup_complete' => true];
        $store->finish($operation['id'], $result);
        $store->finish($operation['id'], ['status' => 'failed', 'cleanup_complete' => true]);
        $this->assertSame($result, $supervisor->pendingResult()['result']);
        $this->assertSame(1, $supervisor->activeCount());
        $store->secureDirectory($store->directory($operation['id']));
        file_put_contents($store->directory($operation['id']).'/runtime.sqlite', 'private');
        $supervisor->acknowledge($operation['id']);
        $supervisor->accept($operation);
        $this->assertSame(0, $supervisor->activeCount());
        $this->assertDirectoryDoesNotExist($store->directory($operation['id']));
        $this->assertArrayNotHasKey('spec', $store->read($operation['id']));
        $this->assertSame($operation['token'], $store->read($operation['id'])['token']);
    }

    public function test_conflicting_delivery_is_rejected(): void
    {
        $operation = AgentOperationRuntimeTest::operation();
        $store = app(AgentOperationStore::class);
        $store->accept($operation);
        $operation['spec']['run']['backup_filename'] = 'different.tar.gz';
        $this->expectException(RuntimeException::class);
        $store->accept($operation);
    }

    public function test_failed_worker_requires_restart_instead_of_silently_reporting_progress_forever(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $supervisor = new class($store) extends AgentOperationSupervisor
        {
            public ?Process $worker = null;

            protected function makeProcess(string $id): Process
            {
                return $this->worker = new Process([PHP_BINARY, '-r', 'exit(1);']);
            }
        };
        $supervisor->accept($operation);
        $supervisor->tick();
        $supervisor->worker->wait();
        $this->expectException(AgentStateException::class);
        try {
            $supervisor->tick();
        } finally {
            $this->assertSame(1, $supervisor->activeCount());
            $this->assertNull($supervisor->pendingResult());
        }
    }

    public function test_active_reads_only_outstanding_operations_even_with_corrupt_archived_receipts(): void
    {
        $store = new class extends AgentOperationStore
        {
            public array $reads = [];

            public function read(string $id): ?array
            {
                $this->reads[] = $id;

                return parent::read($id);
            }
        };
        for ($index = 0; $index < 40; $index++) {
            $operation = AgentOperationRuntimeTest::operation();
            $store->accept($operation);
            $store->finish($operation['id'], ['cleanup_complete' => true]);
            $store->acknowledge($operation['id']);
        }
        file_put_contents($this->root.'/operations/receipts/'.$operation['id'].'.json', 'corrupted archive');
        $pending = AgentOperationRuntimeTest::operation();
        $store->accept($pending);
        $worker = $store->workerLock($pending['id']);
        fclose($worker);
        $store->secureDirectory($store->directory($pending['id']));
        $store->reads = [];
        $this->assertSame([$pending['id']], array_column($store->active(), 'id'));
        $this->assertSame([$pending['id']], $store->reads);
        $this->assertCount(1, glob($this->root.'/operations/*.worker.lock'));
        $this->assertCount(40, glob($this->root.'/operations/receipts/*.worker.lock'));
        $this->expectExceptionMessage('Operation journal is unreadable');
        $store->accept($operation);
    }

    public function test_archived_worker_marker_blocks_replay_when_receipt_is_lost(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $store->accept($operation);
        $store->finish($operation['id'], ['cleanup_complete' => true]);
        $store->acknowledge($operation['id']);
        unlink($this->root.'/operations/receipts/'.$operation['id'].'.json');
        $this->assertSame([], $store->active());
        $this->expectExceptionMessage('Operation receipt is missing');
        $store->accept($operation);
    }

    public function test_missing_key_is_not_replaced_when_archives_exist(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $store->accept($operation);
        $store->finish($operation['id'], ['cleanup_complete' => true]);
        $store->acknowledge($operation['id']);
        unlink($this->root.'/operations.key');
        try {
            $store->localKey();
            $this->fail('Missing key must not be regenerated');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Operation key is missing', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($this->root.'/operations.key');
        unlink($this->root.'/operations/receipts/'.$operation['id'].'.json');
        $this->expectExceptionMessage('Operation key is missing');
        $store->accept(AgentOperationRuntimeTest::operation());
    }

    public function test_legacy_acknowledged_receipts_are_readable_and_migrated_once(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $id = $operation['id'];
        $store->accept($operation);
        $store->finish($id, ['cleanup_complete' => true]);
        $store->acknowledge($id);
        $archive = $this->root.'/operations/receipts/'.$id;
        rename($archive.'.json', $store->directory($id).'.json');
        rename($archive.'.worker.lock', $store->directory($id).'.worker.lock');
        $this->assertSame('acknowledged', $store->read($id)['phase']);
        $this->assertSame([], $store->active());
        $this->assertFileExists($archive.'.json');
        $this->assertFileExists($archive.'.worker.lock');
        $this->assertFileDoesNotExist($store->directory($id).'.json');
        $this->assertFileDoesNotExist($store->directory($id).'.worker.lock');
        $store->acknowledge($id);
        $lock = $store->workerLock($id);
        $this->assertIsResource($lock);
        $this->assertNull($store->workerLock($id));
        fclose($lock);
        $store->accept($operation);
        $this->assertSame([], $store->active());
        $this->assertFileDoesNotExist($store->directory($id).'.worker.lock');
    }

    public function test_interrupted_ack_archival_finishes_cleanup_without_losing_tombstone(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $id = $operation['id'];
        $store->accept($operation);
        $store->finish($id, ['cleanup_complete' => true]);
        $finishedJournal = file_get_contents($store->directory($id).'.json');
        $store->acknowledge($id);
        $archive = $this->root.'/operations/receipts/'.$id;
        rename($archive.'.worker.lock', $store->directory($id).'.worker.lock');
        file_put_contents($store->directory($id).'.json', $finishedJournal);
        $store->secureDirectory($store->directory($id));
        file_put_contents($store->directory($id).'/runtime.sqlite', 'private runtime');
        $this->assertSame('acknowledged', $store->read($id)['phase']);
        $this->assertSame([], $store->active());
        $this->assertDirectoryDoesNotExist($store->directory($id));
        $this->assertFileDoesNotExist($store->directory($id).'.json');
        $this->assertFileExists($archive.'.worker.lock');
        $this->assertSame($operation['token'], $store->read($id)['token']);
    }

    public function test_receipts_directory_symlink_is_rejected(): void
    {
        $store = app(AgentOperationStore::class);
        $store->secureDirectory($this->root.'/operations');
        symlink($this->root, $this->root.'/operations/receipts');
        try {
            $this->expectExceptionMessage('Unsafe operation storage');
            $store->active();
        } finally {
            unlink($this->root.'/operations/receipts');
        }
    }

    public function test_a_second_operation_is_rejected_even_when_first_result_is_pending(): void
    {
        $store = app(AgentOperationStore::class);
        $first = AgentOperationRuntimeTest::operation();
        $store->accept($first);
        $store->finish($first['id'], ['cleanup_complete' => true]);
        $this->expectException(RuntimeException::class);
        $store->accept(AgentOperationRuntimeTest::operation());
    }

    public function test_traversal_and_unapproved_fields_are_rejected_without_persistence(): void
    {
        $store = app(AgentOperationStore::class);
        foreach (['id', 'script', 'volume'] as $invalid) {
            $operation = AgentOperationRuntimeTest::operation();
            match ($invalid) {
                'id' => $operation['id'] = '../other',
                'script' => $operation['spec']['script'] = 'touch /tmp/not-executed',
                'volume' => $operation['spec']['job']['volume_name'] = '/:/host',
            };
            try {
                $store->accept($operation);
                $this->fail('Invalid operation accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString('test-secret', $exception->getMessage());
            }
        }
        $this->assertSame([], $store->active());
    }

    public function test_supervisor_starts_async_once_and_respects_worker_lock(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $process = \Mockery::mock(Process::class);
        $process->shouldReceive('disableOutput')->once();
        $process->shouldReceive('start')->once();
        $process->shouldReceive('isRunning')->andReturn(true);
        $process->shouldReceive('stop')->once()->with(1);
        $supervisor = new class($store, $process) extends AgentOperationSupervisor
        {
            public function __construct(AgentOperationStore $store, private Process $fake)
            {
                parent::__construct($store);
            }

            protected function makeProcess(string $id): Process
            {
                return $this->fake;
            }
        };
        $supervisor->accept($operation);
        $lock = $store->workerLock($operation['id']);
        $supervisor->tick();
        fclose($lock);
        $supervisor->tick();
        $supervisor->tick();
        $supervisor->stop();
        $this->assertSame(1, $supervisor->activeCount());
    }

    public function test_actual_child_command_boots_without_a_central_key_or_database(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $supervisor = new class($store) extends AgentOperationSupervisor
        {
            protected function makeProcess(string $id): Process
            {
                $process = parent::makeProcess($id);
                $process->setEnv([...$process->getEnv(), 'DOCKER_HOST' => 'unix:///nonexistent-volumevault-test.sock']);

                return $process;
            }
        };
        $supervisor->accept($operation);
        try {
            $supervisor->tick();
            $deadline = microtime(true) + 15;
            while ($supervisor->pendingResult() === null && microtime(true) < $deadline) {
                usleep(100000);
            }
            $result = $supervisor->pendingResult();
            $this->assertNotNull($result, 'Child must persist a failed result for the deliberately unavailable Docker socket.');
            $this->assertSame('failed', $result['result']['status']);
            $this->assertTrue($result['result']['cleanup_complete']);
            $this->assertFileExists($store->directory($operation['id']).'/runtime.sqlite');
            $this->assertSame('', config('app.key'));
        } finally {
            $supervisor->stop();
        }
    }

    public function test_lost_receipt_cannot_create_a_fresh_operation(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $store->accept($operation);
        $store->secureDirectory($store->directory($operation['id']));
        unlink($store->directory($operation['id']).'.json');
        $this->expectException(RuntimeException::class);
        $store->accept($operation);
    }

    public function test_wrong_token_is_rejected_after_acknowledgement(): void
    {
        $store = app(AgentOperationStore::class);
        $operation = AgentOperationRuntimeTest::operation();
        $store->accept($operation);
        $store->finish($operation['id'], ['cleanup_complete' => true]);
        $store->acknowledge($operation['id']);
        $operation['token'] = str_repeat('b', 64);
        $this->expectException(RuntimeException::class);
        $store->accept($operation);
    }

    public function test_existing_provider_shapes_are_accepted_without_an_application_key(): void
    {
        config(['volumevault.host_path_allowlist' => ['/safe']]);
        $destinations = [
            ['provider' => 'aws_s3', 'bucket' => 'archives', 'access_key_id' => 'key', 'secret_access_key' => 'secret'],
            ['provider' => 'cloudflare_r2', 'endpoint' => 'https://93.184.216.34', 'bucket' => 'archives', 'secrets' => ['access_key_id' => 'key', 'secret_access_key' => 'secret']],
            ['provider' => 'custom_s3', 'endpoint' => 'https://93.184.216.34', 'bucket' => 'archives', 'secrets' => ['access_key_id' => 'key', 'secret_access_key' => 'secret']],
            ['provider' => 'ssh', 'endpoint' => '93.184.216.34', 'settings' => ['host' => '93.184.216.34', 'remote_path' => '/backups'], 'secrets' => ['user' => 'backup', 'password' => 'secret']],
            ['provider' => 'webdav', 'settings' => ['url' => 'https://93.184.216.34', 'path' => '/backups'], 'secrets' => ['username' => 'backup', 'password' => 'secret']],
            ['provider' => 'azure_blob', 'settings' => ['account_name' => 'account', 'container' => 'archives'], 'secrets' => ['account_key' => 'secret']],
            ['provider' => 'dropbox', 'settings' => ['remote_path' => '/archives'], 'secrets' => ['app_key' => 'key', 'app_secret' => 'secret', 'refresh_token' => 'token']],
            ['provider' => 'google_drive', 'settings' => ['folder_id' => 'archives'], 'secrets' => ['credentials_json' => '{"type":"service_account","private_key":"secret"}']],
            ['provider' => 'local', 'settings' => ['archive_path' => '/safe/archives']],
            ['provider' => 'docker_volume', 'settings' => ['volume_name' => 'archives', 'path_prefix' => 'backups']],
        ];
        foreach ($destinations as $destination) {
            $operation = AgentOperationRuntimeTest::operation();
            $operation['spec']['destination'] = ['name' => 'Destination', ...$destination];
            $operation['spec']['destination']['settings']['storage_limit_warning_bytes'] = 100;
            app(AgentOperationSpecification::class)->validate($operation);
            $this->addToAssertionCount(1);
        }
    }

    public function test_safety_destination_cannot_supply_host_identity_or_policy(): void
    {
        $operation = AgentOperationRuntimeTest::operation('restore');
        $operation['spec']['safety_destination'] = [...$operation['spec']['destination'], 'docker_host_id' => 42];
        $this->expectException(RuntimeException::class);
        app(AgentOperationStore::class)->accept($operation);
    }
}

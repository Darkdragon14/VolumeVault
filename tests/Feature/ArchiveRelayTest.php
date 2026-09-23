<?php

namespace Tests\Feature;

use App\Actions\Backup\CreateBackupRunRecord;
use App\Actions\Docker\CleanupDestinationOperationHelper;
use App\Actions\Docker\ContainerIsAlive;
use App\Actions\Docker\ExportRelayArchive;
use App\Actions\Docker\RemoveDockerContainer;
use App\Actions\Docker\RunRestoreContainer;
use App\Actions\Docker\VerifyRestoreArchive;
use App\Actions\Restore\CreateRestoreRun;
use App\Actions\Restore\Modes\NewVolumeRestore;
use App\Actions\Restore\RunRestore;
use App\Actions\Runs\DispatchQueuedRun;
use App\Exceptions\ArchiveRelayConnectionException;
use App\Jobs\ExportLocalArchiveRelay;
use App\Jobs\RunRestoreJob;
use App\Models\AgentOperation;
use App\Models\ArchiveRelay;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\RestoreRun;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\AgentOperationBroker;
use App\Services\Agents\AgentOperationSpecification;
use App\Services\Agents\ArchiveRelayRuntime;
use App\Services\Agents\ArchiveRelays;
use App\Services\Agents\ArchiveRelayStorage;
use App\Services\Agents\LocalArchiveRelayTarget;
use App\Services\BackupDestinations\ListBackupObjects;
use App\Services\BackupDestinations\SecureLocalArchiveReader;
use App\Services\Docker\DockerProcess;
use App\Services\Docker\DockerProcessResult;
use App\Services\Notifications\SendShoutrrrNotification;
use App\Support\VolumeJobLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArchiveRelayTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/relay-'.Str::uuid());
        config(['volumevault.mode' => 'orchestrator', 'volumevault.archive_relay.directory' => $this->directory,
            'volumevault.archive_relay.max_bytes' => 4194304, 'volumevault.archive_relay.max_disk_bytes' => 25165824]);
        Queue::fake();
        $this->mock(DockerProcess::class)->shouldNotReceive('run');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_remote_relay_is_ordered_encrypted_integrity_checked_and_cleaned_only_after_durable_target_completion(): void
    {
        [$run, $source, $target] = $this->restore();
        $run->job->update(['notifications_enabled' => true]);
        $channel = \App\Models\NotificationChannel::create(['name' => 'Restore events', 'service' => 'advanced', 'url' => 'ntfy://notify.test/restore', 'notification_level' => 'info', 'is_active' => true]);
        $run->job->notificationChannels()->attach($channel);
        $relay = $run->archiveRelay;
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
        $this->assertNull(app(AgentOperationBroker::class)->pull($target));
        $export = app(AgentOperationBroker::class)->pull($source);
        $this->assertSame('archive_export', $export['kind']);
        app(AgentOperationSpecification::class)->validate($export);
        $this->assertSame($export, app(AgentOperationBroker::class)->pull($source));
        $archive = random_bytes(ArchiveRelayStorage::CHUNK_BYTES + 13);
        $hash = hash('sha256', $archive);
        $storage = app(ArchiveRelayStorage::class);
        $send = fn (int $offset, string $chunk): array => app(ArchiveRelays::class)->transfer($source, $export['id'], $export['token'],
            ['action' => 'upload', 'offset' => $offset, 'chunk' => base64_encode($chunk), 'size_bytes' => strlen($archive), 'sha256' => $hash]);
        $first = substr($archive, 0, ArchiveRelayStorage::CHUNK_BYTES);
        $this->assertSame(['offset' => ArchiveRelayStorage::CHUNK_BYTES], $send(0, $first));
        $this->assertSame(['offset' => ArchiveRelayStorage::CHUNK_BYTES], $send(0, $first));
        $this->assertNotSame($first, file_get_contents($storage->directory($relay->id).'/0'));
        $this->assertSame(0600, fileperms($storage->directory($relay->id).'/0') & 0777);
        $this->assertSame(0700, fileperms($storage->directory($relay->id)) & 0777);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame(0, $run->finalizations()->where('type', 'started_notification')->count());
        $send(ArchiveRelayStorage::CHUNK_BYTES, substr($archive, ArchiveRelayStorage::CHUNK_BYTES));
        $source->forceFill(['maintenance_requested_at' => now()])->save();
        app(AgentOperationBroker::class)->complete($source, $export['id'], $export['token'], $this->receipt());
        app(AgentOperationBroker::class)->complete($source, $export['id'], $export['token'], $this->receipt());
        $this->assertSame('ready', $relay->fresh()->status);
        $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));
        $restore = app(AgentOperationBroker::class)->pull($target);
        $this->assertSame($restore, app(AgentOperationBroker::class)->pull($target));
        $this->assertSame(1, $run->finalizations()->where('type', 'started_notification')->count());
        $this->assertSame('restore', $restore['kind']);
        app(AgentOperationSpecification::class)->validate($restore);
        $this->assertSame($hash, $restore['spec']['relay']['sha256']);
        $download = fn (array $data): array => app(ArchiveRelays::class)->transfer($target, $restore['id'], $restore['token'], $data);
        try {
            app(ArchiveRelayRuntime::class)->download($restore['spec']['relay'], $this->directory.'/agent-target', function (array $data) use ($download): array {
                $response = $download($data);
                if ($data['offset'] === ArchiveRelayStorage::CHUNK_BYTES) {
                    throw new ArchiveRelayConnectionException('Lost response.');
                }

                return $response;
            });
            $this->fail('Simulated download interruption did not occur.');
        } catch (ArchiveRelayConnectionException) {
            $this->assertSame(ArchiveRelayStorage::CHUNK_BYTES, filesize($this->directory.'/agent-target/relay.tar.gz'));
        }
        $downloadPath = app(ArchiveRelayRuntime::class)->download($restore['spec']['relay'], $this->directory.'/agent-target', $download);
        $this->assertSame($hash, hash_file('sha256', $downloadPath));
        $this->assertSame($downloadPath, app(ArchiveRelayRuntime::class)->download($restore['spec']['relay'], $this->directory.'/agent-target', $download));
        app(ArchiveRelays::class)->coordinate();
        $this->assertDirectoryExists($storage->directory($relay->id));
        app(AgentOperationBroker::class)->complete($target, $restore['id'], $restore['token'], $this->receipt());
        app(ArchiveRelays::class)->coordinate();
        $this->assertDirectoryDoesNotExist($storage->directory($relay->id));
        $this->assertNotNull($relay->fresh()->cleaned_at);
        $this->assertSame('success', $run->fresh()->status);
        $this->assertSame('historical-source', $run->source_volume_name);
        $this->assertSame('immutable.tar.gz', $run->selected_backup_key);
    }

    public function test_wrong_host_conflicting_retry_out_of_order_and_oversize_are_rejected(): void
    {
        [$run, $source, $target] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $data = ['action' => 'upload', 'offset' => 0, 'chunk' => base64_encode('abc'), 'size_bytes' => 3, 'sha256' => hash('sha256', 'abc')];
        $relays = app(ArchiveRelays::class);
        foreach ([
            [$target, $data, 404],
            [$source, [...$data, 'offset' => 1048576, 'size_bytes' => 1048579], 409],
            [$source, [...$data, 'size_bytes' => 4194305], 422],
        ] as [$host, $payload, $status]) {
            try {
                $relays->transfer($host, $operation['id'], $operation['token'], $payload);
                $this->fail('Unsafe transfer was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame($status, $exception->getStatusCode());
            }
        }
        $relays->transfer($source, $operation['id'], $operation['token'], $data);
        try {
            $relays->transfer($source, $operation['id'], $operation['token'], [...$data, 'chunk' => base64_encode('xyz')]);
            $this->fail('Conflicting retry was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_corruption_and_incomplete_upload_cannot_be_published(): void
    {
        [$run, $source] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $relay = $run->archiveRelay;
        $storage = app(ArchiveRelayStorage::class);
        $storage->upload($relay, 0, 'abc', 3, hash('sha256', 'xyz'));
        try {
            $storage->verify($relay->fresh());
            $this->fail('Incorrect archive digest was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
        $this->assertSame('running', AgentOperation::findOrFail($operation['id'])->status);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_relay_requires_both_capabilities_and_new_volume_and_preserves_same_host_path(): void
    {
        [$run, $source, $target] = $this->restore();
        $destination = $run->destination;
        $relays = app(ArchiveRelays::class);
        $this->assertFalse($relays->required($destination, $source->id));
        $this->assertTrue($relays->required($destination, $target->id));
        $target->forceFill(['agent_capabilities' => ['inventory-v1', 'restore-v1']])->save();
        foreach (['new_volume', 'inplace'] as $mode) {
            try {
                $relays->validate($destination, $target->id, $mode);
                $this->fail('Unsupported relay was admitted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    public function test_fresh_listing_receipt_is_bound_to_source_owner_not_restore_target(): void
    {
        [$run, $source, $target] = $this->restore();
        $destination = $run->destination;
        $receipt = AgentOperation::create(['id' => (string) Str::uuid(), 'kind' => 'destination',
            'status' => 'completed', 'docker_host_id' => $source->id, 'backup_destination_id' => $destination->id,
            'locator_fingerprint' => $destination->locatorFingerprint(), 'destination_action' => 'list', 'claimed_at' => now(),
            'result' => ['status' => 'success', 'data' => ['objects' => [['key' => 'listed.tar.gz']]]]]);
        $data = ['selected_backup_key' => 'listed.tar.gz', 'destination_operation_id' => $receipt->id,
            'target_docker_host_id' => $target->id, 'target_volume_name' => 'from-list', 'mode' => 'new_volume'];
        $listed = app(CreateRestoreRun::class)->handle($run->job, $data);
        $this->assertSame('listed.tar.gz', $listed->archiveRelay->source_key);
        $receipt->update(['docker_host_id' => $target->id]);
        $this->expectException(ValidationException::class);
        app(CreateRestoreRun::class)->handle($run->job, $data);
    }

    public function test_source_helper_cleanup_blocks_publication_and_restart_does_not_export_again(): void
    {
        [$run, $source] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $relay = $run->archiveRelay;
        File::ensureDirectoryExists($this->directory.'/original', 0700);
        $tar = new \PharData($this->directory.'/original/archive.tar');
        $tar->addFromString('backup/historical-source/marker', 'real-archive-marker');
        $archive = gzencode(file_get_contents($this->directory.'/original/archive.tar'));
        file_put_contents($this->directory.'/original/immutable.tar.gz', $archive);
        $this->mock(ExportRelayArchive::class)->shouldReceive('handle')->once()->andReturnUsing(
            function (BackupDestination $destination, string $key, string $path) use ($archive): void {
                file_put_contents($path, $archive);
            });
        $this->mock(CleanupDestinationOperationHelper::class)->shouldReceive('handle')->twice()->andReturn(false, true);
        $upload = fn (array $data): array => app(ArchiveRelays::class)->transfer($source, $operation['id'], $operation['token'], $data);
        $runtime = new ArchiveRelayRuntime;
        $path = $this->directory.'/source-runtime';
        $this->assertNull($runtime->export($operation, $path, true, $upload));
        $this->assertSame(0, $relay->fresh()->uploaded_bytes);
        $result = (new ArchiveRelayRuntime)->export($operation, $path, false, $upload);
        $this->assertSame('success', $result['status']);
        app(AgentOperationBroker::class)->complete($source, $operation['id'], $operation['token'], $result);
        $this->assertSame(hash('sha256', $archive), $relay->fresh()->sha256);
        $this->assertSame($archive, file_get_contents($this->directory.'/original/immutable.tar.gz'));
        $this->assertFileExists($path.'/export.tar.gz');
    }

    public function test_native_reader_rejects_oversize_and_symlinks_and_preserves_the_original(): void
    {
        File::ensureDirectoryExists($this->directory.'/original', 0700);
        $root = $this->directory.'/original';
        file_put_contents($root.'/backup.tar.gz', random_bytes(4097));
        $reader = new SecureLocalArchiveReader;
        $hash = hash_file('sha256', $root.'/backup.tar.gz');
        $target = $this->directory.'/copy.tar.gz';
        try {
            $reader->copy($root, 'backup.tar.gz', $target, stat($root), maxBytes: 4096);
            $this->fail('Oversized archive was copied.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('limit', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($target);
        $reader->copy($root, 'backup.tar.gz', $target, stat($root), maxBytes: 4097);
        $this->assertSame($hash, hash_file('sha256', $target));
        symlink($root.'/backup.tar.gz', $root.'/link.tar.gz');
        try {
            $reader->copy($root, 'link.tar.gz', $this->directory.'/linked.tar.gz', stat($root), maxBytes: 4097);
            $this->fail('Symlink archive was copied.');
        } catch (\RuntimeException) {
            $this->assertFileDoesNotExist($this->directory.'/linked.tar.gz');
        }
        $this->assertSame($hash, hash_file('sha256', $root.'/backup.tar.gz'));
    }

    public function test_expiry_never_reassigns_or_cleans_an_outstanding_source_operation(): void
    {
        [$run, $source] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $this->travel(2)->days();
        app(ArchiveRelays::class)->coordinate();
        $this->assertSame($operation, app(AgentOperationBroker::class)->pull($source));
        $this->assertNull($run->archiveRelay->fresh()->cleaned_at);
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_cleanup_receipt_is_required_even_when_upload_is_complete(): void
    {
        [$run, $source] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        app(ArchiveRelayStorage::class)->upload($run->archiveRelay, 0, 'abc', 3, hash('sha256', 'abc'));
        try {
            app(AgentOperationBroker::class)->complete($source, $operation['id'], $operation['token'], [...$this->receipt(), 'cleanup_complete' => false]);
            $this->fail('Unclean source helper was released.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('running', AgentOperation::findOrFail($operation['id'])->status);
        $this->assertFalse(app(DispatchQueuedRun::class)->handle($run));
    }

    public function test_transport_enforces_host_instance_assignment_and_body_bounds(): void
    {
        config(['volumevault.agents.enabled' => true, 'volumevault.agents.url' => 'https://orchestrator.test:8443']);
        $this->withServerVariables(['HTTPS' => 'on']);
        [$run, $source, $target] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $url = 'https://orchestrator.test:8443/agent/v1/operations/'.$operation['id'].'/relay';
        $data = ['protocol_version' => 1, 'instance_id' => $source->agent_instance_id,
            'token' => $operation['token'], 'action' => 'upload', 'offset' => 0,
            'chunk' => base64_encode('abc'), 'size_bytes' => 3, 'sha256' => hash('sha256', 'abc')];
        $authorization = ['Authorization' => 'Bearer '.$source->uuid.'.'.str_repeat('a', 64)];
        $this->postJson($url, $data, $authorization)->assertOk()->assertExactJson(['offset' => 3]);
        $this->postJson($url, [...$data, 'instance_id' => $target->agent_instance_id],
            ['Authorization' => 'Bearer '.$target->uuid.'.'.str_repeat('a', 64)])->assertNotFound();
        $this->postJson($url, [...$data, 'instance_id' => (string) Str::uuid()], $authorization)->assertUnauthorized();
        $this->postJson($url, [...$data, 'chunk' => str_repeat('A', 1398108)], $authorization)->assertUnprocessable();
        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_lost_upload_ack_is_retried_without_relaunching_the_source_helper(): void
    {
        [$run, $source] = $this->restore();
        $operation = app(AgentOperationBroker::class)->pull($source);
        $archive = random_bytes(ArchiveRelayStorage::CHUNK_BYTES + 7);
        $this->mock(ExportRelayArchive::class)->shouldReceive('handle')->once()->andReturnUsing(
            function (BackupDestination $destination, string $key, string $path) use ($archive): void {
                file_put_contents($path, $archive);
            });
        $this->mock(CleanupDestinationOperationHelper::class)->shouldReceive('handle')->andReturn(true);
        $offsets = [];
        $upload = function (array $data) use ($source, $operation, &$offsets): array {
            $offsets[] = $data['offset'];
            $response = app(ArchiveRelays::class)->transfer($source, $operation['id'], $operation['token'], $data);
            if (count($offsets) === 1) {
                throw new ArchiveRelayConnectionException('Lost acknowledgement.');
            }

            return $response;
        };
        $path = $this->directory.'/source-runtime';
        try {
            (new ArchiveRelayRuntime)->export($operation, $path, true, $upload);
            $this->fail('Simulated upload interruption did not occur.');
        } catch (ArchiveRelayConnectionException) {
            $this->assertSame(ArchiveRelayStorage::CHUNK_BYTES, $run->archiveRelay->uploaded_bytes);
        }
        $result = (new ArchiveRelayRuntime)->export($operation, $path, false, $upload);
        $this->assertSame('success', $result['status']);
        $this->assertSame([0, 0, ArchiveRelayStorage::CHUNK_BYTES], $offsets);
        $this->assertSame('success', (new ArchiveRelayRuntime)->export($operation, $path, false, $upload)['status']);
        $this->assertCount(3, $offsets);
        app(AgentOperationBroker::class)->complete($source, $operation['id'], $operation['token'], $result);
        $this->assertSame(hash('sha256', $archive), $run->archiveRelay->fresh()->sha256);
    }

    public function test_hybrid_local_source_export_uses_the_durable_source_operation(): void
    {
        [$run] = $this->restore(localSource: true);
        $bytes = gzencode('local-source-archive');
        $this->mock(ExportRelayArchive::class)->shouldReceive('handle')->once()->andReturnUsing(
            function (BackupDestination $destination, string $key, string $path) use ($bytes): void {
                $this->assertSame(1, $destination->docker_host_id);
                file_put_contents($path, $bytes);
            });
        $this->mock(CleanupDestinationOperationHelper::class)->shouldReceive('handle')->once()->andReturn(true);
        $job = new ExportLocalArchiveRelay($run->archiveRelay->id);
        $job->handle();
        $job->handle();
        $relay = $run->archiveRelay->fresh();
        $this->assertSame('ready', $relay->status);
        $this->assertSame('completed', $relay->sourceAgentOperation->status);
        $this->assertSame(hash('sha256', $bytes), $relay->sha256);
        $this->assertTrue(app(DispatchQueuedRun::class)->handle($run));
    }

    public function test_hybrid_local_target_download_keeps_original_source_identity(): void
    {
        [$run, $source] = $this->restore(localTarget: true);
        $export = app(AgentOperationBroker::class)->pull($source);
        $bytes = gzencode('remote-source-archive');
        app(ArchiveRelayStorage::class)->upload($run->archiveRelay, 0, $bytes, strlen($bytes), hash('sha256', $bytes));
        app(AgentOperationBroker::class)->complete($source, $export['id'], $export['token'], $this->receipt());
        app(LocalArchiveRelayTarget::class)->admit($run->archiveRelay->fresh());
        $path = app(ArchiveRelays::class)->downloadLocal($run->archiveRelay->fresh());
        $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $path));
        $this->assertSame($source->id, $run->source_docker_host_id);
        $this->assertSame(1, $run->target_docker_host_id);
        $this->assertSame('immutable.tar.gz', $run->selected_backup_key);
        $this->assertSame($source->id, $run->destination->docker_host_id);
        $serialized = $run->archiveRelay->fresh()->toArray();
        $this->assertSame(['id' => $source->id, 'name' => $source->name], $serialized['source_docker_host']);
        $this->assertSame(['id' => 1, 'name' => DockerHost::findOrFail(1)->name], $serialized['target_docker_host']);
        $this->assertArrayNotHasKey('destination_snapshot', $serialized);
    }

    public function test_quota_reservations_are_released_only_after_safe_cleanup(): void
    {
        [$first] = $this->restore();
        $this->restore();
        try {
            $this->restore();
            $this->fail('Relay disk reservation was overcommitted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('quota', $exception->getMessage());
        }
        $this->assertDatabaseCount('archive_relays', 2);
        $first->update(['status' => 'cancelled']);
        app(ArchiveRelays::class)->coordinate();
        $this->assertNotNull($first->archiveRelay->cleaned_at);
        $this->restore();
        $this->assertDatabaseCount('archive_relays', 3);
    }

    public function test_orphan_cleanup_does_not_discard_data_referenced_by_an_assigned_agent(): void
    {
        [$run, $source] = $this->restore();
        $export = app(AgentOperationBroker::class)->pull($source);
        $protected = (string) Str::uuid();
        $unowned = (string) Str::uuid();
        foreach ([$protected, $unowned] as $id) {
            $path = $this->directory.'/'.$id;
            File::ensureDirectoryExists($path, 0700);
            file_put_contents($path.'/0', 'private orphan');
            touch($path, time() - 172800);
        }
        $operation = AgentOperation::findOrFail($export['id']);
        $payload = $operation->payload;
        $payload['relay']['id'] = $protected;
        $operation->update(['payload' => $payload]);
        app(ArchiveRelays::class)->coordinate();
        $this->assertDirectoryExists($this->directory.'/'.$protected);
        $this->assertDirectoryDoesNotExist($this->directory.'/'.$unowned);
        $this->assertSame('running', $operation->fresh()->status);
        $this->assertNull($run->archiveRelay->cleaned_at);
    }

    public function test_source_export_and_unconfirmed_helper_cleanup_hold_local_maintenance(): void
    {
        [$run] = $this->restore(localSource: true);
        $this->mock(ExportRelayArchive::class)->shouldReceive('handle')->once()->andReturnUsing(
            fn (BackupDestination $destination, string $key, string $path) => file_put_contents($path, 'archive'));
        $this->mock(CleanupDestinationOperationHelper::class)->shouldReceive('handle')->twice()->andReturn(false, true);
        $job = new ExportLocalArchiveRelay($run->archiveRelay->id);
        $job->handle();
        $host = DockerHost::findOrFail(1);
        $lifecycle = app(AgentLifecycle::class);
        $state = $lifecycle->setMaintenance($host, true);
        $this->assertSame(1, $state['active_operations']);
        $this->assertFalse($state['maintenance_ready']);
        $job->handle();
        $this->assertTrue($lifecycle->state($host->fresh())['maintenance_ready']);
        $this->assertSame('ready', $run->archiveRelay->fresh()->status);
    }

    public function test_remote_source_assignment_counts_even_before_agent_active_count_catches_up(): void
    {
        [$run, $source] = $this->restore();
        app(AgentOperationBroker::class)->pull($source);
        $this->assertSame(0, $source->agent_active_operations);
        $this->assertSame(1, app(AgentLifecycle::class)->activeOperations($source));
        $this->assertSame('running', $run->archiveRelay->sourceAgentOperation->status);
    }

    public function test_local_download_is_admitted_before_io_and_drains_across_maintenance_and_ttl(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $storage = new ArchiveRelayStorage;
        $entered = false;
        $this->partialMock(ArchiveRelayStorage::class)->shouldReceive('read')->andReturnUsing(function (ArchiveRelay $current, int $offset) use ($storage, $run, $relay, &$entered): string {
            if (! $entered) {
                $entered = true;
                $this->assertSame('running', $run->fresh()->status);
                $this->assertSame('downloading', $relay->fresh()->status);
                $state = app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
                $this->assertFalse($state['maintenance_ready']);
                $this->assertSame(1, $state['active_operations']);
                $relay->update(['expires_at' => now()->subSecond()]);
                app(ArchiveRelays::class)->coordinate();
                $this->assertSame('running', $run->fresh()->status);
                $this->assertNull($relay->fresh()->cleaned_at);
                $this->assertDirectoryExists($storage->directory($relay->id));
            }

            return $storage->read($current, $offset);
        });
        $executed = 0;
        app(LocalArchiveRelayTarget::class)->handle($run, function (string $path) use ($run, &$executed): void {
            $executed++;
            $this->assertFileExists($path);
            $run->update(['status' => 'success', 'finished_at' => now()]);
            $this->assertSame(1, app(AgentLifecycle::class)->activeOperations(DockerHost::findOrFail(1)));
        });
        $this->assertTrue($entered);
        $this->assertSame(1, $executed);
        app(ArchiveRelays::class)->coordinate();
        $this->assertNotNull($relay->fresh()->cleaned_at);
        $this->assertTrue(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
    }

    public function test_expiry_rechecks_stale_queued_snapshot_after_atomic_local_admission(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $stale = $relay->fresh();
        $target = app(LocalArchiveRelayTarget::class);
        $first = $target->admit($relay);
        $started = $run->fresh()->started_at;
        $relay->update(['expires_at' => now()->subSecond()]);
        app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
        $this->assertFalse(app(ArchiveRelays::class)->expireUnassignedTarget($stale));
        $this->assertSame($first->id, $target->admit($stale)->id);
        $this->assertTrue($started->equalTo($run->fresh()->started_at));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($relay->fresh()->cleaned_at);
    }

    public function test_expired_ready_local_target_cannot_start_download_or_assignment(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $relay->update(['expires_at' => now()]);
        $this->mock(ArchiveRelayRuntime::class)->shouldNotReceive('download');
        $target = app(LocalArchiveRelayTarget::class);
        $this->assertNull($target->admit($relay));
        $target->handle($run, fn () => $this->fail('Expired target executed.'));
        $this->assertTrue(app(ArchiveRelays::class)->expireUnassignedTarget($relay));
        $this->assertNull($target->admit($relay));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertFalse(AgentOperation::where('restore_run_id', $run->id)->exists());
    }

    public function test_download_restart_reuses_assignment_and_volume_lock_and_cannot_double_execute(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $target = new LocalArchiveRelayTarget;
        $operation = $target->admit($relay);
        $key = VolumeJobLock::cacheKey($run->target_volume_name);
        $this->assertTrue(Cache::restoreLock($key, $operation->context['volume_lock_owner'])->isOwnedByCurrentProcess());
        $relay->update(['expires_at' => now()->subSecond()]);
        app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
        $executed = 0;
        (new LocalArchiveRelayTarget)->handle($run, function (string $path) use ($run, &$executed): void {
            $executed++;
            $run->update(['status' => 'success', 'finished_at' => now()]);
        });
        $target->handle($run->fresh(), fn () => $this->fail('Completed target was replayed.'));
        $this->assertSame(1, $executed);
        $this->assertSame($operation->id, AgentOperation::where('restore_run_id', $run->id)->sole()->id);
        $this->assertFalse(Cache::lock($key, 10)->get());
        app(ArchiveRelays::class)->coordinate();
        $this->assertTrue(Cache::lock($key, 10)->get());
        $this->assertSame('completed', $operation->fresh()->status);
    }

    public function test_execution_restart_waits_for_helper_cleanup_and_never_replays_restore(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $target = new LocalArchiveRelayTarget;
        $operation = $target->admit($relay);
        $operation->update(['context' => [...$operation->context, 'local_relay_phase' => 'executing']]);
        $run->update(['docker_container_id' => 'relay-target-helper']);
        $this->mock(ContainerIsAlive::class)->shouldReceive('handle')->times(3)->with('relay-target-helper')->andReturn(true, false, false);
        $remove = $this->mock(RemoveDockerContainer::class);
        $remove->shouldReceive('handle')->once()->with('relay-target-helper')->andThrow(new \RuntimeException('Cleanup unavailable.'));
        $remove->shouldReceive('handle')->once()->with('relay-target-helper');
        $target->handle($run->fresh(), fn () => $this->fail('Interrupted extraction was replayed.'));
        $state = app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
        $this->assertFalse($state['maintenance_ready']);
        $target->recover($relay);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($relay->fresh()->cleaned_at);
        try {
            $target->recover($relay);
            $this->fail('Simulated cleanup failure did not occur.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Cleanup unavailable.', $exception->getMessage());
        }
        $this->assertSame('running', $operation->fresh()->status);
        $this->assertFalse(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
        $target->recover($relay);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertNotNull($relay->fresh()->cleaned_at);
        $this->assertTrue(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
    }

    public function test_generic_stale_reconciliation_leaves_admitted_relay_recovery_to_worker_lock_owner(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        app(LocalArchiveRelayTarget::class)->admit($relay);
        $run->update(['last_heartbeat_at' => now()->subHours(2)]);
        $this->artisan('volumevault:reconcile-stale-runs', ['--minutes' => 1])->assertSuccessful();
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(1, app(AgentLifecycle::class)->activeOperations(DockerHost::findOrFail(1)));
    }

    public function test_run_restore_continues_an_admitted_local_target_without_a_second_claim(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        app(LocalArchiveRelayTarget::class)->admit($relay);
        $started = $run->fresh()->started_at;
        app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
        $this->mock(NewVolumeRestore::class)->shouldReceive('validate')->once()
            ->andThrow(new \RuntimeException('Deliberate pre-Docker validation failure.'));
        $this->mock(SendShoutrrrNotification::class)->shouldReceive('sendRestoreRun')->andReturnNull();
        app(RunRestore::class)->handle($run->fresh());
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertTrue($started->equalTo($run->fresh()->started_at));
        $this->assertStringContainsString('Deliberate pre-Docker validation failure.', $run->fresh()->error_message);
        $this->assertSame(1, app(AgentLifecycle::class)->activeOperations(DockerHost::findOrFail(1)));
        app(ArchiveRelays::class)->coordinate();
        $this->assertTrue(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
    }

    public function test_terminal_cleanup_waits_for_live_worker_and_removes_crash_leftover_runtime_archive(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $target = new LocalArchiveRelayTarget;
        $operation = $target->admit($relay);
        $worker = $target->workerLock($relay);
        $oldStorage = storage_path();
        app()->useStoragePath($this->directory.'/runtime');
        try {
            $runtimeArchive = storage_path('app/restore-runs/'.$run->id.'/backup.tar.gz');
            File::ensureDirectoryExists(dirname($runtimeArchive), 0700);
            file_put_contents($runtimeArchive, 'private crash leftover');
            $run->update(['status' => 'failed']);
            app(AgentLifecycle::class)->setMaintenance(DockerHost::findOrFail(1), true);
            $target->recover($relay);
            $this->assertFileExists($runtimeArchive);
            $this->assertNull($relay->fresh()->cleaned_at);
            $this->assertFalse(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
            fclose($worker);
            $worker = null;
            $target->recover($relay);
            $this->assertFileDoesNotExist($runtimeArchive);
            $this->assertSame('completed', $operation->fresh()->status);
            $this->assertNotNull($relay->fresh()->cleaned_at);
            $this->assertTrue(app(AgentLifecycle::class)->state(DockerHost::findOrFail(1))['maintenance_ready']);
        } finally {
            if (is_resource($worker)) {
                fclose($worker);
            }
            app()->useStoragePath($oldStorage);
        }
    }

    public function test_same_target_jobs_interleave_before_claim_but_only_owner_is_admitted_and_recovers_after_crash(): void
    {
        [$a, $relayA] = $this->readyLocalTarget();
        [$b, $relayB] = $this->readyLocalTarget();
        $this->assertSame($a->target_volume_name, $b->target_volume_name);
        $this->mockSuccessfulLocalExecution($a->id);
        $restore = app(RunRestore::class);
        $jobB = (new RunRestoreJob($b->id))->withFakeQueueInteractions();
        $interleaved = false;
        AgentOperation::creating(function (AgentOperation $operation) use ($a, $b, $jobB, $restore, &$interleaved): void {
            if ($operation->restore_run_id !== $a->id || $interleaved) {
                return;
            }
            $interleaved = true;
            // A owns the cache lock, but neither its operation nor running state is
            // published yet. B must not rely on a preflight read of that state.
            $this->assertSame('queued', $a->fresh()->status);
            $this->assertTrue(Cache::restoreLock(VolumeJobLock::cacheKey($a->target_volume_name), $operation->context['volume_lock_owner'])->isOwnedByCurrentProcess());
            $jobB->handle($restore);
            $jobB->assertReleased(60);
            $this->assertSame('queued', $b->fresh()->status);
            $this->assertFalse(AgentOperation::where('restore_run_id', $b->id)->exists());
        });
        $target = new class($a->id) extends LocalArchiveRelayTarget
        {
            public function __construct(private readonly int $crashingRunId) {}

            public function admit(ArchiveRelay $relay): ?AgentOperation
            {
                $operation = parent::admit($relay);
                if ($relay->restore_run_id === $this->crashingRunId && $operation !== null) {
                    throw new \RuntimeException('Simulated crash after committed admission.');
                }

                return $operation;
            }
        };
        app()->instance(LocalArchiveRelayTarget::class, $target);
        try {
            (new RunRestoreJob($a->id))->handle($restore);
            $this->fail('Worker interruption was not simulated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated crash after committed admission.', $exception->getMessage());
        }
        $this->assertTrue($interleaved);
        $owner = AgentOperation::where('restore_run_id', $a->id)->sole();
        $this->assertSame('running', $a->fresh()->status);
        $this->assertSame('queued', $b->fresh()->status);
        $this->assertNull($b->fresh()->started_at);
        $this->assertSame('ready', $relayB->fresh()->status);

        app()->instance(LocalArchiveRelayTarget::class, new LocalArchiveRelayTarget);
        (new RunRestoreJob($b->id))->handle($restore);
        app(ArchiveRelays::class)->coordinate();
        Queue::assertPushed(RunRestoreJob::class, fn (RunRestoreJob $job): bool => $job->restoreRunId === $a->id);
        (new RunRestoreJob($a->id))->handle($restore);
        $this->assertSame('success', $a->fresh()->status);
        $this->assertSame($owner->id, AgentOperation::where('restore_run_id', $a->id)->sole()->id);
        (new RunRestoreJob($b->id))->handle($restore);
        $this->assertSame('queued', $b->fresh()->status);
        $this->assertFalse(AgentOperation::where('restore_run_id', $b->id)->exists());
        $this->assertSame(0, $relayB->fresh()->downloaded_bytes);
        app(ArchiveRelays::class)->coordinate();
        $this->assertNotNull($relayA->fresh()->cleaned_at);
        $this->assertSame('completed', $owner->fresh()->status);
    }

    public function test_persisted_owner_recovers_through_queue_job_despite_legacy_admitted_nonowner_waiter(): void
    {
        [$a, $relayA] = $this->readyLocalTarget();
        [$b, $relayB] = $this->readyLocalTarget();
        $owner = app(LocalArchiveRelayTarget::class)->admit($relayA);
        $legacy = AgentOperation::create([
            'id' => (string) Str::uuid(), 'docker_host_id' => 1, 'restore_run_id' => $b->id,
            'kind' => 'restore', 'status' => 'running', 'claimed_at' => now(),
            'context' => ['archive_relay_id' => $relayB->id, 'local_relay_phase' => 'downloading', 'volume_lock_owner' => (string) Str::uuid()],
        ]);
        $b->update(['status' => 'running', 'started_at' => now(), 'last_heartbeat_at' => now()]);
        $relayB->update(['status' => 'downloading']);
        $this->mockSuccessfulLocalExecution($a->id);
        $restore = app(RunRestore::class);
        (new RunRestoreJob($b->id))->handle($restore);
        $this->assertSame(0, $relayB->fresh()->downloaded_bytes);
        (new RunRestoreJob($a->id))->handle($restore);
        $this->assertSame('success', $a->fresh()->status);
        $this->assertSame($owner->id, AgentOperation::where('restore_run_id', $a->id)->sole()->id);
        $this->assertSame('running', $legacy->fresh()->status);
        $this->assertSame(0, $relayB->fresh()->downloaded_bytes);
    }

    public function test_failed_admission_rolls_back_running_state_and_releases_only_its_new_volume_lock(): void
    {
        [$run, $relay] = $this->readyLocalTarget();
        $fail = true;
        AgentOperation::creating(function (AgentOperation $operation) use ($run, &$fail): void {
            if ($operation->restore_run_id === $run->id && $fail) {
                $fail = false;
                throw new \RuntimeException('Admission write failed.');
            }
        });
        try {
            app(LocalArchiveRelayTarget::class)->admit($relay);
            $this->fail('Admission did not fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Admission write failed.', $exception->getMessage());
        }
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame('ready', $relay->fresh()->status);
        $this->assertFalse(AgentOperation::where('restore_run_id', $run->id)->exists());
        $competing = Cache::lock(VolumeJobLock::cacheKey($run->target_volume_name), 60);
        $this->assertTrue($competing->get());
        $this->assertNull(app(LocalArchiveRelayTarget::class)->admit($relay));
        $this->assertTrue($competing->isOwnedByCurrentProcess());
        $this->assertSame('queued', $run->fresh()->status);
        $competing->release();
        $this->assertNotNull(app(LocalArchiveRelayTarget::class)->admit($relay));
    }

    public function test_queue_waiter_rechecks_active_target_under_host_lock_even_when_cache_lease_is_free(): void
    {
        [$a, $relayA] = $this->readyLocalTarget();
        [$b, $relayB] = $this->readyLocalTarget();
        $owner = app(LocalArchiveRelayTarget::class)->admit($relayA);
        $owner->update(['context' => [...$owner->context, 'local_relay_phase' => 'executing']]);
        $key = VolumeJobLock::cacheKey($a->target_volume_name);
        Cache::restoreLock($key, $owner->context['volume_lock_owner'])->release();
        $this->mock(ArchiveRelayRuntime::class)->shouldNotReceive('download');
        $waiter = (new RunRestoreJob($b->id))->withFakeQueueInteractions();
        $waiter->handle(app(RunRestore::class));
        $waiter->assertReleased(60);
        $this->assertSame('queued', $b->fresh()->status);
        $this->assertSame('ready', $relayB->fresh()->status);
        $this->assertFalse(AgentOperation::where('restore_run_id', $b->id)->exists());
        $unheld = Cache::lock($key, 60);
        $this->assertTrue($unheld->get());
        $unheld->release();
    }

    private function mockSuccessfulLocalExecution(int $runId): void
    {
        app()->useStoragePath($this->directory.'/runtime');
        $mode = $this->mock(NewVolumeRestore::class);
        $mode->shouldReceive('validate')->once()->withArgs(fn (RestoreRun $run): bool => $run->id === $runId);
        $mode->shouldReceive('prepareTarget')->once()->withArgs(fn (RestoreRun $run, callable $progress): bool => $run->id === $runId);
        $mode->shouldNotReceive('cleanupAfterFailure');
        $this->mock(VerifyRestoreArchive::class)->shouldReceive('handle')->once()->andReturn(new DockerProcessResult([], 0, '', ''));
        $this->mock(RunRestoreContainer::class)->shouldReceive('handle')->once()
            ->withArgs(fn (RestoreRun $run, string $archive, callable $progress): bool => $run->id === $runId)
            ->andReturn(new DockerProcessResult([], 0, 'Restored only the admitted target.', ''));
        $this->mock(SendShoutrrrNotification::class)->shouldReceive('sendRestoreRun')->andReturnNull();
    }

    private function readyLocalTarget(): array
    {
        [$run, $source] = $this->restore(localTarget: true);
        $export = app(AgentOperationBroker::class)->pull($source);
        $bytes = gzencode('local relay lifecycle archive');
        app(ArchiveRelayStorage::class)->upload($run->archiveRelay, 0, $bytes, strlen($bytes), hash('sha256', $bytes));
        app(AgentOperationBroker::class)->complete($source, $export['id'], $export['token'], $this->receipt());

        return [$run, $run->archiveRelay->fresh()];
    }

    private function restore(bool $localSource = false, bool $localTarget = false): array
    {
        if ($localSource || $localTarget) {
            config(['volumevault.mode' => 'hybrid']);
        }
        if ($localSource) {
            $this->mock(ListBackupObjects::class)->shouldReceive('contains')->once()->andReturn(true);
        }
        $source = $localSource ? DockerHost::findOrFail(1) : $this->host();
        $target = $localTarget ? DockerHost::findOrFail(1) : $this->host();
        $destination = BackupDestination::create(['name' => 'Source local archive', 'provider' => 'docker_volume', 'bucket' => '', 'access_key_id' => '', 'secret_access_key' => '',
            'docker_host_id' => $source->id, 'settings' => ['volume_name' => 'archives'], 'is_active' => true]);
        $job = BackupJob::create(['name' => 'Relay job', 'schedule_type' => 'daily', 'schedule_config' => ['time' => '00:00'], 'docker_host_id' => $source->id, 'backup_destination_id' => $destination->id,
            'volume_name' => 'historical-source', 'source_type' => 'docker_volume', 'status' => 'active']);
        $backup = app(CreateBackupRunRecord::class)->handle($job, ['status' => 'success', 'trigger' => 'manual']);
        $backup->update(['backup_key' => 'immutable.tar.gz']);
        $run = app(CreateRestoreRun::class)->handle($job, ['backup_run_id' => $backup->id,
            'target_docker_host_id' => $target->id, 'selected_backup_key' => $backup->backup_key, 'target_volume_name' => 'restored', 'mode' => 'new_volume']);

        return [$run, $source, $target];
    }

    private function host(): DockerHost
    {
        return DockerHost::factory()->create(['driver' => 'agent', 'agent_protocol_version' => 1,
            'agent_capabilities' => AgentCompatibility::CAPABILITIES, 'agent_registered_at' => now(),
            'agent_instance_id' => (string) Str::uuid(), 'agent_token_hash' => hash('sha256', str_repeat('a', 64)),
            'agent_active_operations' => 0]);
    }

    private function receipt(): array
    {
        return ['status' => 'success', 'cleanup_complete' => true, 'logs' => 'Archive handled.',
            'duration_seconds' => 1, 'finished_at' => now()->toIso8601String()];
    }
}

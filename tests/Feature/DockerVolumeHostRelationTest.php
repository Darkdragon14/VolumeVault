<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerHost;
use App\Models\DockerVolume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DockerVolumeHostRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_and_eager_loading_match_both_host_and_volume_name(): void
    {
        [$volumes, $expected] = $this->fixtures();

        foreach ($volumes as $volume) {
            $this->assertEqualsCanonicalizing($expected[$volume->id], $volume->backupJobs->modelKeys());
        }

        DB::enableQueryLog();
        $loaded = DockerVolume::with('backupJobs')->get();
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();

        foreach ($loaded as $volume) {
            $this->assertEqualsCanonicalizing($expected[$volume->id], $volume->backupJobs->modelKeys());
        }

        $loaded = DockerVolume::all()->load('backupJobs');
        foreach ($loaded as $volume) {
            $this->assertEqualsCanonicalizing($expected[$volume->id], $volume->backupJobs->modelKeys());
        }
    }

    public function test_counts_and_existence_queries_are_host_scoped_and_exclude_host_paths(): void
    {
        [$volumes, $expected] = $this->fixtures();

        foreach (DockerVolume::withCount('backupJobs')->withExists('backupJobs')->get() as $volume) {
            $this->assertSame(count($expected[$volume->id]), $volume->backup_jobs_count);
            $this->assertSame($expected[$volume->id] !== [], $volume->backup_jobs_exists);
        }

        $this->assertEqualsCanonicalizing(
            array_keys(array_filter($expected)),
            DockerVolume::whereHas('backupJobs')->get()->modelKeys(),
        );
        $this->assertSame([$volumes[1]->id], DockerVolume::whereHas('backupJobs', null, '>=', 2)->get()->modelKeys());
        $this->assertSame([$volumes[3]->id], DockerVolume::whereDoesntHave('backupJobs')->get()->modelKeys());
        $this->assertSame([$volumes[1]->id], DockerVolume::whereHas('backupJobs', function (Builder $query) use ($expected, $volumes): void {
            $query->whereKey($expected[$volumes[1]->id][0]);
        })->get()->modelKeys());
    }

    public function test_eager_query_only_fetches_requested_host_name_pairs(): void
    {
        [$volumes, $expected] = $this->fixtures();
        $relation = Relation::noConstraints(fn () => $volumes[0]->backupJobs());
        $relation->addEagerConstraints([$volumes[0], $volumes[3]]);

        $this->assertSame($expected[$volumes[0]->id], $relation->getEager()->modelKeys());
    }

    public function test_relation_writes_propagate_host_name_and_source_type(): void
    {
        $volume = DockerVolume::create(['docker_host_id' => DockerHost::factory()->create()->id, 'name' => 'remote-data']);
        $attributes = $this->jobAttributes() + [
            'docker_host_id' => DockerHost::LOCAL_ID,
            'volume_name' => 'wrong-name',
            'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
        ];

        $created = $volume->backupJobs()->create($attributes);
        $saved = $volume->backupJobs()->save(new BackupJob($attributes));
        $forced = $volume->backupJobs()->forceCreate($attributes);

        foreach ([$created, $saved, $forced] as $job) {
            $job->refresh();
            $this->assertSame($volume->docker_host_id, $job->docker_host_id);
            $this->assertSame($volume->name, $job->volume_name);
            $this->assertSame(BackupJob::SOURCE_TYPE_DOCKER_VOLUME, $job->source_type);
        }

        $this->assertEqualsCanonicalizing([$created->id, $saved->id, $forced->id], $volume->backupJobs->modelKeys());
    }

    public function test_composite_eager_loading_limits_are_explicitly_unsupported(): void
    {
        $this->fixtures();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composite-key partitioning');

        DockerVolume::with(['backupJobs' => fn ($query) => $query->limit(1)])->get();
    }

    public function test_single_key_relation_conversion_is_explicitly_unsupported(): void
    {
        $this->expectException(LogicException::class);

        (new DockerVolume)->backupJobs()->one();
    }

    public function test_single_key_upserts_are_explicitly_unsupported(): void
    {
        $this->expectException(LogicException::class);

        (new DockerVolume)->backupJobs()->upsert([], ['id']);
    }

    /**
     * @return array{0: list<DockerVolume>, 1: array<int, list<int>>}
     */
    private function fixtures(): array
    {
        $remoteHost = DockerHost::factory()->create();
        $volumes = [];
        $expected = [];
        $attributes = $this->jobAttributes();

        foreach ([[DockerHost::LOCAL_ID, 'shared', 1], [$remoteHost->id, 'shared', 2], [DockerHost::LOCAL_ID, 'other', 1], [$remoteHost->id, 'other', 0]] as [$hostId, $name, $count]) {
            $volume = DockerVolume::create(['docker_host_id' => $hostId, 'name' => $name]);
            $volumes[] = $volume;
            $expected[$volume->id] = [];

            for ($i = 0; $i < $count; $i++) {
                $expected[$volume->id][] = BackupJob::create($attributes + [
                    'docker_host_id' => $hostId,
                    'volume_name' => $name,
                    'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
                ])->id;
            }

            BackupJob::create($attributes + [
                'docker_host_id' => $hostId,
                'volume_name' => $name,
                'source_type' => BackupJob::SOURCE_TYPE_HOST_PATH,
                'host_path' => '/data',
            ]);
        }

        return [$volumes, $expected];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobAttributes(): array
    {
        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        return [
            'name' => 'Volume backup',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
        ];
    }
}

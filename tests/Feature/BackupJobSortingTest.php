<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackupJobSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_index_sorts_jobs_by_name_in_both_directions(): void
    {
        $user = User::factory()->user()->create();
        $this->createJob('Bravo');
        $this->createJob('Alpha');

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Alpha')
                ->where('jobs.data.1.name', 'Bravo'));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'name', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Bravo')
                ->where('jobs.data.1.name', 'Alpha'));
    }

    public function test_web_index_sorts_dates_with_nulls_last_and_names_as_tie_breakers(): void
    {
        $user = User::factory()->user()->create();
        $this->createJob('Never scheduled');
        $this->createJob('Bravo', ['next_run_at' => '2026-08-26 10:00:00', 'last_run_at' => '2026-08-24 10:00:00']);
        $this->createJob('Alpha', ['next_run_at' => '2026-08-26 10:00:00', 'last_run_at' => '2026-08-25 10:00:00']);
        $this->createJob('Charlie', ['next_run_at' => '2026-08-27 10:00:00', 'last_run_at' => '2026-08-23 10:00:00']);

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'next_run_at', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Alpha')
                ->where('jobs.data.1.name', 'Bravo')
                ->where('jobs.data.2.name', 'Charlie')
                ->where('jobs.data.3.name', 'Never scheduled'));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'next_run_at', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Charlie')
                ->where('jobs.data.1.name', 'Alpha')
                ->where('jobs.data.2.name', 'Bravo')
                ->where('jobs.data.3.name', 'Never scheduled'));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'last_run_at', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Alpha')
                ->where('jobs.data.1.name', 'Bravo')
                ->where('jobs.data.2.name', 'Charlie')
                ->where('jobs.data.3.name', 'Never scheduled'));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'last_run_at', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Charlie')
                ->where('jobs.data.1.name', 'Bravo')
                ->where('jobs.data.2.name', 'Alpha')
                ->where('jobs.data.3.name', 'Never scheduled'));
    }

    public function test_api_supports_sorting_and_documents_its_query_parameters(): void
    {
        $token = User::factory()->user()->create()->createToken('sorting', ['read'])->plainTextToken;
        $this->createJob('Alpha');
        $this->createJob('Bravo');

        $this->withToken($token)
            ->getJson('/api/v1/backup-jobs?sort=name&direction=desc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bravo')
            ->assertJsonPath('data.1.name', 'Alpha');

        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./backup-jobs.get.parameters.0.name', 'sort')
            ->assertJsonPath('paths./backup-jobs.get.parameters.1.name', 'direction');
    }

    public function test_created_at_sort_uses_date_rules_in_both_directions(): void
    {
        $user = User::factory()->user()->create();
        $bravo = $this->createJob('Bravo');
        $alpha = $this->createJob('Alpha');
        $charlie = $this->createJob('Charlie');
        $undated = $this->createJob('Undated');
        $bravo->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();
        $alpha->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();
        $charlie->forceFill(['created_at' => '2026-08-24 10:00:00'])->save();
        $undated->forceFill(['created_at' => null])->save();

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'created_at', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Alpha')
                ->where('jobs.data.1.name', 'Bravo')
                ->where('jobs.data.2.name', 'Charlie')
                ->where('jobs.data.3.name', 'Undated'));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'created_at', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Charlie')
                ->where('jobs.data.1.name', 'Alpha')
                ->where('jobs.data.2.name', 'Bravo')
                ->where('jobs.data.3.name', 'Undated'));
    }

    public function test_direct_invalid_url_falls_back_to_recently_created_first(): void
    {
        $user = User::factory()->user()->create();
        $older = $this->createJob('Older');
        $newer = $this->createJob('Newer');
        $older->forceFill(['created_at' => '2026-08-24 10:00:00'])->save();
        $newer->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'unknown', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Newer')
                ->where('jobs.data.1.name', 'Older'));
    }

    public function test_direction_only_requests_use_created_at_sort_in_web_and_api(): void
    {
        $user = User::factory()->user()->create();
        $token = $user->createToken('sorting', ['read'])->plainTextToken;
        $older = $this->createJob('Older');
        $newer = $this->createJob('Newer');
        $older->forceFill(['created_at' => '2026-08-24 10:00:00'])->save();
        $newer->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Older')
                ->where('jobs.data.1.name', 'Newer'));

        $this->withToken($token)
            ->getJson('/api/v1/backup-jobs?direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Older')
            ->assertJsonPath('data.1.name', 'Newer');
    }

    public function test_non_scalar_sort_falls_back_to_recently_created_first(): void
    {
        $user = User::factory()->user()->create();
        $alpha = $this->createJob('Alpha');
        $bravo = $this->createJob('Bravo');
        $alpha->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();
        $bravo->forceFill(['created_at' => '2026-08-24 10:00:00'])->save();

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => ['name'], 'direction' => ['asc']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Alpha')
                ->where('jobs.data.1.name', 'Bravo'));
    }

    public function test_non_scalar_direction_preserves_valid_name_sort(): void
    {
        $user = User::factory()->user()->create();
        $token = $user->createToken('sorting', ['read'])->plainTextToken;
        $alpha = $this->createJob('Alpha');
        $bravo = $this->createJob('Bravo');
        $alpha->forceFill(['created_at' => '2026-08-25 10:00:00'])->save();
        $bravo->forceFill(['created_at' => '2026-08-24 10:00:00'])->save();

        $this->withToken($token)
            ->getJson('/api/v1/backup-jobs?sort=name&direction%5B%5D=asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bravo')
            ->assertJsonPath('data.1.name', 'Alpha');
    }

    public function test_sort_order_is_stable_across_pages(): void
    {
        $user = User::factory()->user()->create();
        $jobIds = collect(range(1, 12))->map(fn (): int => $this->createJob('Same name')->id);

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'name', 'direction' => 'asc', 'per_page' => 10]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $jobIds[0])
                ->where('jobs.data.9.id', $jobIds[9]));

        $this->actingAs($user)
            ->get(route('backup-jobs.index', ['sort' => 'name', 'direction' => 'asc', 'per_page' => 10, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $jobIds[10])
                ->where('jobs.data.1.id', $jobIds[11]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createJob(string $name, array $attributes = []): BackupJob
    {
        $destination = BackupDestination::firstOrCreate(
            ['name' => 'Local'],
            [
                'provider' => BackupDestination::PROVIDER_LOCAL,
                'bucket' => 'local',
                'access_key_id' => '',
                'secret_access_key' => '',
                'is_active' => true,
            ],
        );

        return BackupJob::create(array_merge([
            'name' => $name,
            'source_type' => BackupJob::SOURCE_TYPE_DOCKER_VOLUME,
            'volume_name' => str($name)->slug()->toString(),
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'status' => BackupJob::STATUS_ACTIVE,
        ], $attributes));
    }
}

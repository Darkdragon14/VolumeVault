<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RestoreRunSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inertia_json_hides_ownership_nonce_without_removing_internal_access(): void
    {
        $run = $this->restoreRun();
        $nonce = $run->getAttribute('target_volume_ownership_token');
        $url = route('restore-runs.show', $run);
        $response = $this->actingAs(User::factory()->create())
            ->getJson($url, [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create($url)) ?? '',
            ])
            ->assertOk()
            ->assertJsonPath('component', 'RestoreRuns/Show')
            ->assertJsonPath('props.run.id', $run->id)
            ->assertJsonMissingPath('props.run.target_volume_ownership_token');

        $this->assertStringNotContainsString($nonce, $response->getContent());
        $this->assertSame($nonce, $run->fresh()->getAttribute('target_volume_ownership_token'));
    }

    public function test_api_detail_and_list_hide_ownership_nonce_without_removing_internal_access(): void
    {
        $run = $this->restoreRun();
        $nonce = $run->getAttribute('target_volume_ownership_token');
        $token = User::factory()->user()->create()->createToken('read-runs', ['read'])->plainTextToken;

        foreach (["/api/v1/restore-runs/{$run->id}" => 'data', '/api/v1/restore-runs' => 'data.0'] as $url => $path) {
            $response = $this->withToken($token)->getJson($url)
                ->assertOk()
                ->assertJsonPath($path.'.id', $run->id)
                ->assertJsonMissingPath($path.'.target_volume_ownership_token');

            $this->assertStringNotContainsString($nonce, $response->getContent());
        }

        $this->assertSame($nonce, $run->fresh()->getAttribute('target_volume_ownership_token'));
        $this->assertArrayNotHasKey('target_volume_ownership_token', $run->fresh()->toArray());
    }

    private function restoreRun(): RestoreRun
    {
        $destination = BackupDestination::create([
            'name' => 'Local', 'provider' => 'local', 'bucket' => 'local',
            'access_key_id' => '', 'secret_access_key' => '', 'settings' => ['archive_path' => sys_get_temp_dir()],
        ]);
        $job = BackupJob::create([
            'name' => 'Backup', 'volume_name' => 'source', 'backup_destination_id' => $destination->id,
            'schedule_type' => 'daily', 'schedule_config' => ['time' => '02:00'], 'cron_expression' => '0 2 * * *', 'status' => 'active',
        ]);
        $run = RestoreRun::create([
            'backup_job_id' => $job->id, 'backup_destination_id' => $destination->id,
            'selected_backup_key' => 'backup.tar.gz', 'source_volume_name' => 'source', 'target_volume_name' => 'restored',
            'mode' => RestoreRun::MODE_NEW_VOLUME, 'status' => RestoreRun::STATUS_SUCCESS,
        ]);
        $run->forceFill(['target_volume_ownership_token' => bin2hex(random_bytes(32))])->save();

        return $run;
    }
}

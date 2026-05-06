<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_homepage_redirects_to_onboarding_before_first_user(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('onboarding.create'));
    }

    public function test_the_homepage_redirects_to_dashboard_after_onboarding(): void
    {
        User::factory()->admin()->create();

        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_backup_job_show_route_binds_resource_parameter(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());

        $destination = BackupDestination::create([
            'name' => 'S3',
            'provider' => BackupDestination::PROVIDER_AWS_S3,
            'bucket' => 'backups',
            'access_key_id' => 'access',
            'secret_access_key' => 'secret',
        ]);

        $job = BackupJob::create([
            'name' => 'Nightly',
            'volume_name' => 'app_data',
            'backup_destination_id' => $destination->id,
            'schedule_type' => BackupJob::SCHEDULE_DAILY,
            'schedule_config' => ['time' => '02:00'],
            'cron_expression' => '0 2 * * *',
            'status' => BackupJob::STATUS_ACTIVE,
        ]);

        $this->get('/backup-jobs/'.$job->id)->assertOk();
    }

    public function test_backup_job_create_route_is_not_captured_by_show_route(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/backup-jobs/create')->assertOk();
    }
}

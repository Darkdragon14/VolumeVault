<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupFilenamePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_preview_utf8_truncation_without_creating_a_job(): void
    {
        $stem = str_repeat('é', 125);
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('backup-jobs.filename-preview'), ['stem' => $stem])
            ->assertOk()->assertExactJson(['filename' => str_repeat('é', 117).'-'.substr(hash('sha256', $stem), 0, 12).'.tar.gz']);
        $this->assertDatabaseCount('backup_jobs', 0);
    }

    public function test_preview_is_admin_only_and_input_is_bounded(): void
    {
        $this->postJson(route('backup-jobs.filename-preview'), ['stem' => 'backup'])->assertUnauthorized();
        $this->actingAs(User::factory()->user()->create())
            ->postJson(route('backup-jobs.filename-preview'), ['stem' => 'backup'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('backup-jobs.filename-preview'), ['stem' => str_repeat('a', 16385)])
            ->assertUnprocessable()->assertJsonValidationErrors('stem');
    }
}

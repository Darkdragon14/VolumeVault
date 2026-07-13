<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_normalized_preferences(): void
    {
        $user = User::factory()->create(['dashboard_preferences' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->has('dashboardPreferences.stats', 18)
                ->has('dashboardPreferences.sections', 5));
    }

    public function test_valid_preferences_are_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('user.dashboard-preferences.update'), [
                'stats' => [
                    ['key' => 'error_jobs', 'visible' => true],
                    ['key' => 'total_volumes', 'visible' => false],
                ],
                'sections' => [
                    ['key' => 'jobs_with_errors', 'visible' => false],
                ],
            ])
            ->assertRedirect();

        $stats = $user->fresh()->dashboard_preferences['stats'];

        // Stored order is preserved and re-normalized (all canonical stats present).
        $this->assertSame('error_jobs', $stats[0]['key']);
        $this->assertFalse($stats[1]['visible']);
        $this->assertCount(18, $stats);

        $sections = collect($user->fresh()->dashboard_preferences['sections'])->keyBy('key');
        $this->assertFalse($sections['jobs_with_errors']['visible']);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('user.dashboard-preferences.update'), [
                'stats' => [
                    ['key' => 'total_volumes'], // missing "visible"
                ],
                // missing "sections"
            ])
            ->assertSessionHasErrors(['stats.0.visible', 'sections']);

        $this->assertNull($user->fresh()->dashboard_preferences);
    }

    public function test_preferences_are_scoped_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice)->patch(route('user.dashboard-preferences.update'), [
            'stats' => [['key' => 'total_volumes', 'visible' => false]],
            'sections' => [],
        ])->assertRedirect();

        $aliceStats = collect($alice->fresh()->dashboard_preferences['stats'])->keyBy('key');
        $this->assertFalse($aliceStats['total_volumes']['visible']);
        $this->assertNull($bob->fresh()->dashboard_preferences);
    }

    public function test_hidden_section_skips_its_query(): void
    {
        $user = User::factory()->create([
            'dashboard_preferences' => [
                'stats' => [],
                'sections' => [
                    ['key' => 'recent_backups', 'visible' => false],
                    ['key' => 'recent_restores', 'visible' => false],
                    ['key' => 'jobs_with_errors', 'visible' => false],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recentBackupRuns', [])
                ->where('recentRestoreRuns', [])
                ->where('jobsWithErrors', []));
    }
}

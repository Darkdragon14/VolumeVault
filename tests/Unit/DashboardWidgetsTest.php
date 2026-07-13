<?php

namespace Tests\Unit;

use App\Support\DashboardWidgets;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    public function test_null_preferences_fall_back_to_canonical_defaults(): void
    {
        $normalized = DashboardWidgets::normalize(null);

        $this->assertSame(DashboardWidgets::STAT_KEYS, array_column($normalized['stats'], 'key'));
        $this->assertSame(DashboardWidgets::SECTION_KEYS, array_column($normalized['sections'], 'key'));
    }

    public function test_hidden_by_default_widgets_start_hidden(): void
    {
        $normalized = DashboardWidgets::normalize(null);

        $byKey = collect($normalized['stats'])->keyBy('key');

        $this->assertFalse($byKey['last_successful_backup_size']['visible']);
        $this->assertFalse($byKey['last_successful_group_backup_size']['visible']);
        $this->assertTrue($byKey['total_volumes']['visible']);
    }

    public function test_unknown_keys_are_dropped(): void
    {
        $normalized = DashboardWidgets::normalize([
            'stats' => [
                ['key' => 'total_volumes', 'visible' => false],
                ['key' => 'made_up_widget', 'visible' => true],
            ],
            'sections' => [],
        ]);

        $keys = array_column($normalized['stats'], 'key');

        $this->assertNotContains('made_up_widget', $keys);
        $this->assertContains('total_volumes', $keys);
    }

    public function test_missing_canonical_widgets_are_appended_and_visible(): void
    {
        // Only one stat stored: every other canonical stat must be re-added at the end.
        $normalized = DashboardWidgets::normalize([
            'stats' => [['key' => 'error_jobs', 'visible' => true]],
            'sections' => [],
        ]);

        $keys = array_column($normalized['stats'], 'key');

        $this->assertSame('error_jobs', $keys[0]);
        $this->assertCount(count(DashboardWidgets::STAT_KEYS), $keys);
        // A re-added canonical widget (not hidden-by-default) is visible.
        $byKey = collect($normalized['stats'])->keyBy('key');
        $this->assertTrue($byKey['total_volumes']['visible']);
    }

    public function test_stored_order_and_visibility_are_preserved(): void
    {
        $normalized = DashboardWidgets::normalize([
            'stats' => [
                ['key' => 'missing_volumes', 'visible' => false],
                ['key' => 'total_volumes', 'visible' => true],
            ],
            'sections' => [
                ['key' => 'jobs_with_errors', 'visible' => true],
                ['key' => 'recent_backups', 'visible' => false],
            ],
        ]);

        $this->assertSame('missing_volumes', $normalized['stats'][0]['key']);
        $this->assertFalse($normalized['stats'][0]['visible']);
        $this->assertSame('total_volumes', $normalized['stats'][1]['key']);

        $this->assertSame('jobs_with_errors', $normalized['sections'][0]['key']);
        $this->assertFalse($normalized['sections'][1]['visible']);
    }

    public function test_is_section_visible_reads_the_normalized_shape(): void
    {
        $prefs = DashboardWidgets::normalize([
            'stats' => [],
            'sections' => [
                ['key' => 'recent_backups', 'visible' => false],
                ['key' => 'recent_restores', 'visible' => true],
            ],
        ]);

        $this->assertFalse(DashboardWidgets::isSectionVisible($prefs, 'recent_backups'));
        $this->assertTrue(DashboardWidgets::isSectionVisible($prefs, 'recent_restores'));
    }
}

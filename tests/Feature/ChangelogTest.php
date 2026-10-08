<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Changelog\AvailableUpdateChecker;
use App\Services\Changelog\Changelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_seen_version_receives_only_current_release_summary(): void
    {
        config([
            'app.env' => 'production',
            'app.version' => 'v1.1.0',
            'changelog.unreleased' => [],
            'changelog.releases' => [
                'v1.1.0' => $this->release([$this->item('available_update_checks')]),
                'v1.0.0' => $this->release([$this->item('first_stable_release')]),
            ],
        ]);

        $summary = app(Changelog::class)->unreadForUser(User::factory()->create());

        $this->assertNotNull($summary);
        $this->assertSame('v1.1.0', $summary['changelog_id']);
        $this->assertCount(1, $summary['sections']);
        $this->assertSame('v1.1.0', $summary['sections'][0]['version']);
        $this->assertSame('Available update checks', $summary['sections'][0]['items'][0]['title']);
    }

    public function test_user_with_old_version_receives_releases_between_old_and_current(): void
    {
        config([
            'app.env' => 'production',
            'app.version' => 'v1.2.0',
            'changelog.unreleased' => [],
            'changelog.releases' => [
                'v1.2.0' => $this->release([$this->item('available_update_checks')]),
                'v1.1.0' => $this->release([$this->item('backup_job_detail_deletion')]),
                'v1.0.0' => $this->release([$this->item('first_stable_release')]),
            ],
        ]);
        $user = User::factory()->create(['last_seen_app_version' => 'v1.0.0']);

        $summary = app(Changelog::class)->unreadForUser($user);

        $this->assertNotNull($summary);
        $this->assertSame(['v1.2.0', 'v1.1.0'], array_column($summary['sections'], 'version'));
    }

    public function test_user_at_current_release_has_no_unread_summary(): void
    {
        config([
            'app.env' => 'production',
            'app.version' => 'v1.1.0',
            'changelog.unreleased' => [],
            'changelog.releases' => [
                'v1.1.0' => $this->release([$this->item('available_update_checks')]),
            ],
        ]);
        $user = User::factory()->create([
            'last_seen_app_version' => 'v1.1.0',
            'last_seen_changelog_id' => 'v1.1.0',
        ]);

        $this->assertNull(app(Changelog::class)->unreadForUser($user));
    }

    public function test_main_version_uses_unreleased_entries_and_content_hash(): void
    {
        config([
            'app.env' => 'production',
            'app.version' => 'main',
            'changelog.unreleased' => [$this->item('available_update_checks')],
            'changelog.releases' => [],
        ]);
        $user = User::factory()->create();

        $firstSummary = app(Changelog::class)->unreadForUser($user);
        $this->assertNotNull($firstSummary);
        $this->assertStringStartsWith('unreleased:', $firstSummary['changelog_id']);

        $user->forceFill(['last_seen_changelog_id' => $firstSummary['changelog_id']])->save();
        config(['changelog.unreleased' => [$this->item('backup_job_detail_deletion')]]);

        $secondSummary = app(Changelog::class)->unreadForUser($user->fresh());

        $this->assertNotNull($secondSummary);
        $this->assertNotSame($firstSummary['changelog_id'], $secondSummary['changelog_id']);
    }

    public function test_seen_route_stores_current_version_and_changelog_id(): void
    {
        config([
            'app.env' => 'production',
            'app.version' => 'main',
            'changelog.unreleased' => [$this->item('available_update_checks')],
            'changelog.releases' => [],
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/changelog/seen')
            ->assertRedirect();

        $user->refresh();

        $this->assertSame('main', $user->last_seen_app_version);
        $this->assertStringStartsWith('unreleased:', $user->last_seen_changelog_id);
    }

    public function test_changelog_page_requires_authentication_and_renders_for_users(): void
    {
        config([
            'app.version' => 'main',
            'changelog.unreleased' => [$this->item('available_update_checks')],
            'changelog.releases' => [],
        ]);
        User::factory()->create();

        $this->get('/changelog')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get('/changelog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Changelog/Index')
                ->has('changelog.sections', 1));
    }

    public function test_release_command_moves_unreleased_entries(): void
    {
        $path = $this->temporaryChangelogPath();
        File::put($path, $this->changelogFile([
            'unreleased' => [$this->item('available_update_checks')],
            'releases' => [],
        ]));

        $this->artisan('changelog:release', [
            'version' => 'v1.2.0',
            '--path' => $path,
        ])->assertExitCode(0);

        $data = require $path;

        $this->assertSame([], $data['unreleased']);
        $this->assertArrayHasKey('v1.2.0', $data['releases']);
        $this->assertSame('available_update_checks', $data['releases']['v1.2.0']['items'][0]['key']);
    }

    public function test_validate_command_blocks_release_without_changelog(): void
    {
        $path = $this->temporaryChangelogPath();
        File::put($path, $this->changelogFile([
            'unreleased' => [],
            'releases' => [],
        ]));

        $this->artisan("changelog:validate v1.2.0 --release --path={$path}")
            ->assertExitCode(1);
    }

    public function test_project_changelog_config_is_valid(): void
    {
        $errors = app(Changelog::class)->validateData(config('changelog'));

        $this->assertSame([], $errors);
    }

    public function test_changelog_translations_exist_for_all_supported_locales(): void
    {
        $errors = app(Changelog::class)->validateTranslationsForLocales(User::SUPPORTED_LOCALES);

        $this->assertSame([], $errors);
    }

    public function test_count_retention_has_a_release_note_in_every_locale(): void
    {
        $this->assertContains('count_based_backup_retention', array_column(config('changelog.releases')['v2.0.3']['items'], 'key'));

        foreach (User::SUPPORTED_LOCALES as $locale) {
            $translations = require resource_path('changelog/'.$locale.'.php');
            $this->assertNotEmpty($translations['count_based_backup_retention']['title']);
            $this->assertNotEmpty($translations['count_based_backup_retention']['description']);
        }
    }

    public function test_changelog_translation_validation_uses_supplied_data(): void
    {
        $errors = app(Changelog::class)->validateTranslationsForLocales([User::DEFAULT_LOCALE], [
            'unreleased' => [$this->item('missing_translation_for_test')],
            'releases' => [],
        ]);

        $this->assertSame([
            'Missing changelog translation for missing_translation_for_test in '.User::DEFAULT_LOCALE.'.',
        ], $errors);
    }

    public function test_available_update_checker_returns_newer_github_release(): void
    {
        Cache::flush();
        config([
            'app.version' => 'v1.4.0',
            'volumevault.update_check.enabled' => true,
        ]);
        Http::fake([
            '*' => Http::response($this->githubRelease('v1.5.0')),
        ]);

        $update = app(AvailableUpdateChecker::class)->forUser(User::factory()->create());

        $this->assertNotNull($update);
        $this->assertSame('v1.5.0', $update['version']);
        $this->assertSame('https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.5.0', $update['url']);
    }

    public function test_available_update_checker_ignores_main_and_dismissed_versions(): void
    {
        Cache::flush();
        config([
            'app.version' => 'main',
            'volumevault.update_check.enabled' => true,
        ]);
        Http::fake([
            '*' => Http::response($this->githubRelease('v1.5.0')),
        ]);

        $this->assertNull(app(AvailableUpdateChecker::class)->forUser(User::factory()->create()));

        Cache::flush();
        config(['app.version' => 'v1.4.0']);
        $user = User::factory()->create(['last_dismissed_available_version' => 'v1.5.0']);

        $this->assertNull(app(AvailableUpdateChecker::class)->forUser($user));
    }

    public function test_available_update_dismiss_route_stores_latest_release_version(): void
    {
        Cache::flush();
        config([
            'app.version' => 'v1.4.0',
            'volumevault.update_check.enabled' => true,
        ]);
        Http::fake([
            '*' => Http::response($this->githubRelease('v1.5.0')),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/updates/available/dismiss')
            ->assertRedirect();

        $this->assertSame('v1.5.0', $user->refresh()->last_dismissed_available_version);
    }

    public function test_changelog_page_receives_available_update_prop(): void
    {
        Cache::flush();
        config([
            'app.version' => 'v1.4.0',
            'volumevault.update_check.enabled' => true,
        ]);
        Http::fake([
            '*' => Http::response($this->githubRelease('v1.5.0')),
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/changelog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Changelog/Index')
                ->where('availableUpdate.version', 'v1.5.0'));
    }

    /**
     * @param  list<array<string, string>>  $items
     * @return array{date: string, url: string, items: list<array<string, string>>}
     */
    private function release(array $items): array
    {
        return [
            'date' => '2026-05-27',
            'url' => 'https://github.com/Darkdragon14/VolumeVault/releases/tag/v1.0.0',
            'items' => $items,
        ];
    }

    /**
     * @return array{type: string, key: string}
     */
    private function item(string $key): array
    {
        return [
            'type' => Changelog::TYPE_FEATURE,
            'key' => $key,
        ];
    }

    private function temporaryChangelogPath(): string
    {
        File::ensureDirectoryExists(storage_path('framework/testing'));

        return storage_path('framework/testing/changelog-'.Str::uuid().'.php');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function changelogFile(array $data): string
    {
        return '<?php return '.var_export($data, true).';';
    }

    /**
     * @return array<string, string>
     */
    private function githubRelease(string $version): array
    {
        return [
            'tag_name' => $version,
            'html_url' => "https://github.com/Darkdragon14/VolumeVault/releases/tag/{$version}",
            'published_at' => '2026-05-27T10:00:00Z',
            'body' => 'A newer release is available.',
        ];
    }
}

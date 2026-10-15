<?php

namespace Tests\Feature;

use App\Models\DockerHost;
use App\Models\DockerVolume;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EnvironmentSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_selection_returns_to_dashboard_and_survives_navigation_without_get_side_effects(): void
    {
        $user = User::factory()->user()->create();
        $host = DockerHost::factory()->create(['name' => 'Remote']);
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'remote-data', 'driver' => 'local', 'exists' => true]);
        DockerVolume::create(['docker_host_id' => 1, 'name' => 'local-data', 'driver' => 'local', 'exists' => true]);

        $this->actingAs($user)->post('/user/environment', ['docker_host_id' => $host->id])
            ->assertStatus(303)->assertRedirect('/dashboard')->assertSessionHas('environment.'.$user->id, $host->id);
        $this->get('/volumes')->assertInertia(fn (Assert $page) => $page
            ->where('filters.docker_host_id', $host->id)->where('environment.docker_host_id', $host->id)
            ->has('volumes', 1)->where('volumes.0.name', 'remote-data')->has('environment.hosts', 2)
            ->missing('environment.hosts.0.agent_token_hash'));
        $this->get('/profile')->assertInertia(fn (Assert $page) => $page->where('environment.docker_host_id', $host->id));
        $this->get('/stacks')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', $host->id));
        $this->get('/volumes?docker_host_id=1')->assertInertia(fn (Assert $page) => $page
            ->where('filters.docker_host_id', 1)->where('environment.docker_host_id', 1));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', $host->id));
        $this->get('/volumes?docker_host_id=')->assertInertia(fn (Assert $page) => $page
            ->where('filters.docker_host_id', null)->where('environment.docker_host_id', null)->has('volumes', 2));
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', $host->id))
            ->assertSessionHas('environment.'.$user->id, $host->id);
    }

    public function test_all_environments_can_be_selected_and_stale_preferences_fall_back_safely(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['environment.'.$user->id => 999999])
            ->get('/volumes')->assertInertia(fn (Assert $page) => $page
            ->where('filters.docker_host_id', null)->where('environment.docker_host_id', null));
        $this->withSession(['environment.'.$user->id => 'invalid'])->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->where('environment.docker_host_id', null));
        $this->post('/user/environment', ['docker_host_id' => 1])->assertRedirect('/dashboard');
        $this->post('/user/environment', ['docker_host_id' => ''])->assertRedirect('/dashboard');
        $this->get('/volumes')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', null));
    }

    public function test_api_does_not_inherit_or_change_the_web_environment(): void
    {
        $user = User::factory()->create();
        $host = DockerHost::factory()->create();
        DockerVolume::create(['docker_host_id' => $host->id, 'name' => 'remote-data', 'driver' => 'local', 'exists' => true]);
        DockerVolume::create(['docker_host_id' => 1, 'name' => 'local-data', 'driver' => 'local', 'exists' => true]);
        $this->actingAs($user)->withSession(['environment.'.$user->id => $host->id]);
        $this->getJson('/api/v1/volumes')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'local-data');
        $this->getJson('/api/v1/volumes?docker_host_id=1')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/volumes')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', $host->id));
    }

    public function test_selection_is_authenticated_validated_and_cannot_redirect_off_site(): void
    {
        $this->post('/user/environment', ['docker_host_id' => 1])->assertRedirect('/login');
        $user = User::factory()->user()->create();
        $this->actingAs($user)->withSession(['environment.'.$user->id => 1])
            ->post('/user/environment', [])->assertSessionHasErrors('docker_host_id')
            ->assertSessionHas('environment.'.$user->id, 1);
        $this->post('/user/environment', ['docker_host_id' => 999999])->assertSessionHasErrors('docker_host_id');
        foreach (['https://example.com', '//example.com', '/\\example.com', "/dash\nboard"] as $url) {
            $this->post('/user/environment', ['docker_host_id' => 1, 'return_to' => $url])
                ->assertStatus(303)->assertRedirect('/dashboard');
        }
        $this->get('/volumes?docker_host_id=999999')->assertSessionHasErrors('docker_host_id')
            ->assertSessionHas('environment.'.$user->id, 1);
    }

    public function test_execution_context_parameters_do_not_change_the_selected_environment(): void
    {
        $user = User::factory()->admin()->create();
        $host = DockerHost::factory()->create();
        $this->actingAs($user)->withSession(['environment.'.$user->id => $host->id]);
        $this->get('/settings/docker-label-backups?docker_host_id=1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('environment.docker_host_id', $host->id)->where('settings.docker_host_id', 1));
    }

    public function test_session_selections_are_scoped_to_the_authenticated_user(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $host = DockerHost::factory()->create();
        $this->actingAs($first)->post('/user/environment', ['docker_host_id' => $host->id])->assertRedirect('/dashboard');
        $this->actingAs($second)->get('/volumes')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', null));
        $this->actingAs($first)->get('/volumes')->assertInertia(fn (Assert $page) => $page->where('filters.docker_host_id', $host->id));
    }

    public function test_stateful_reads_and_writes_share_a_lock_with_server_side_file_sessions(): void
    {
        $this->assertTrue(config('session.block'));
        $directory = sys_get_temp_dir().'/volumevault-session-lock-'.Str::uuid();
        File::ensureDirectoryExists($directory);
        config(['session.driver' => 'file', 'session.files' => $directory]);
        app('session')->forgetDrivers();
        Route::match(['GET', 'PATCH'], '/environment-lock-probe', function (Request $request) {
            $lock = Cache::lock('session:'.$request->session()->getId(), 10);
            $acquired = $lock->get();
            if ($acquired) {
                $lock->release();
            }
            $request->session()->put('environment.probe', $request->method());

            return response()->json(['locked' => ! $acquired, 'session_id' => $request->session()->getId()]);
        })->middleware('web');

        try {
            $response = $this->get('/environment-lock-probe')->assertOk()->assertJsonPath('locked', true);
            $id = $response->json('session_id');
            $this->withCookie(config('session.cookie'), $id)->patch('/environment-lock-probe')
                ->assertOk()->assertJsonPath('locked', true)->assertJsonPath('session_id', $id);
            $lock = Cache::lock('session:'.$id, 10);
            $this->assertTrue($lock->get());
            $lock->release();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_a_competing_request_cannot_read_or_write_a_locked_session(): void
    {
        config(['session.block_wait_seconds' => 0]);
        $id = Str::random(40);
        $lock = Cache::lock('session:'.$id, 10);
        $this->assertTrue($lock->get());
        $entered = false;
        Route::get('/environment-lock-probe', function () use (&$entered) {
            $entered = true;

            return response('Unexpected access');
        })->middleware('web');
        $this->withoutExceptionHandling();

        try {
            $this->withCookie(config('session.cookie'), $id)->get('/environment-lock-probe');
            $this->fail('A competing request entered the locked session.');
        } catch (LockTimeoutException) {
            $this->assertFalse($entered);
        } finally {
            $lock->release();
        }
    }

    public function test_native_selection_requires_a_valid_csrf_token(): void
    {
        $user = User::factory()->create();
        $host = DockerHost::factory()->create();
        $this->app->instance('env', 'local');
        $this->actingAs($user)->withSession(['_token' => 'environment-csrf-token', 'environment.'.$user->id => 1]);
        $this->post('/user/environment', ['docker_host_id' => $host->id])->assertStatus(419);
        $this->post('/user/environment', ['docker_host_id' => $host->id, '_token' => 'invalid'])->assertStatus(419);
        $this->post('/user/environment', ['docker_host_id' => $host->id, '_token' => 'environment-csrf-token'])
            ->assertStatus(303)->assertRedirect('/dashboard')->assertSessionHas('environment.'.$user->id, $host->id);
    }

    public function test_scoped_inertia_reads_and_redirects_do_not_require_custom_headers(): void
    {
        $user = User::factory()->create();
        $host = DockerHost::factory()->create();
        $initial = $this->actingAs($user)->get('/profile')->assertOk();
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $initial->viewData('page')['version']];
        $this->get('/volumes?docker_host_id='.$host->id, $headers)->assertOk()
            ->assertJsonPath('props.environment.docker_host_id', $host->id)->assertJsonMissingPath('props.environment.revision');
        $this->get('/dashboard', $headers)->assertOk()
            ->assertJsonPath('props.environment.docker_host_id', null);
        Route::post('/environment-action-probe', fn () => back())->middleware('web');
        $this->from('/volumes?docker_host_id='.$host->id)->post('/environment-action-probe', [], $headers)
            ->assertRedirect('/volumes?docker_host_id='.$host->id);
        $this->get('/volumes?docker_host_id='.$host->id, $headers)->assertOk()
            ->assertJsonPath('props.environment.docker_host_id', $host->id);
    }

    public function test_background_json_requests_do_not_change_the_saved_environment(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['environment.'.$user->id => 1])->get('/profile')->assertOk();
        Route::get('/environment-json-probe', fn () => response()->json(['ok' => true]))->middleware('web');
        $this->getJson('/environment-json-probe')->assertOk()->assertJsonPath('ok', true)
            ->assertSessionHas('environment.'.$user->id, 1);
    }
}

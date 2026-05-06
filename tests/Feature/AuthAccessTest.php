<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_onboarding_user_becomes_admin_and_is_logged_in(): void
    {
        $this->post('/onboarding', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard'));

        $user = User::first();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->isAdmin());
        $this->assertSame(User::DEFAULT_LOCALE, $user->locale);
    }

    public function test_authenticated_user_can_update_own_locale(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)
            ->patch('/user/locale', ['locale' => 'fr'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'locale' => 'fr',
        ]);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch('/user/locale', ['locale' => 'cz'])
            ->assertSessionHasErrors('locale');
    }

    public function test_login_authenticates_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_is_redirected_to_login_for_dashboard_after_onboarding(): void
    {
        User::factory()->admin()->create();

        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_regular_user_cannot_access_sensitive_admin_routes(): void
    {
        $this->actingAs(User::factory()->user()->create());

        $this->get('/destinations')->assertForbidden();
        $this->get('/notifications')->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->post('/volumes/sync')->assertForbidden();
    }

    public function test_admin_can_create_admin_or_regular_users(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/users', [
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'role' => User::ROLE_USER,
            'locale' => 'de',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'operator@example.com',
            'role' => User::ROLE_USER,
            'locale' => 'de',
        ]);
    }

    public function test_last_admin_cannot_be_demoted_or_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->put('/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => User::ROLE_USER,
            'locale' => 'en',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors('role');

        $this->delete('/users/'.$admin->id)->assertSessionHasErrors('user');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor\TrustedDeviceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function google2fa(): Google2FA
    {
        return new Google2FA;
    }

    /**
     * A six-digit code guaranteed to differ from the current valid OTP, so the
     * "invalid code" assertions never collide with a genuinely valid one.
     */
    private function invalidCodeFor(string $secret): string
    {
        return $this->google2fa()->getCurrentOtp($secret) === '000000' ? '111111' : '000000';
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    private function userWithTwoFactor(string $secret, array $recoveryCodes = ['aaaaaaaaaa-bbbbbbbbbb']): User
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->fresh();
    }

    /**
     * Persist a trusted device for the user keyed on a known plaintext token.
     * Tests then send that token via withCookie(); the test harness encrypts it
     * exactly as a browser cookie would arrive, so the request decrypts back to
     * this token and matches the stored hash.
     */
    private function seedTrustedDevice(User $user, string $token = 'known-trusted-token', ?\DateTimeInterface $expiresAt = null): void
    {
        $user->twoFactorTrustedDevices()->create([
            'token' => hash('sha256', $token),
            'expires_at' => $expiresAt ?? now()->addDays(TrustedDeviceManager::DAYS),
        ]);
    }

    public function test_user_can_enable_and_confirm_two_factor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/profile/two-factor')->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertCount(8, $user->two_factor_recovery_codes);
        $this->assertFalse($user->hasTwoFactorEnabled());

        // Secret is stored encrypted at rest, not in plaintext.
        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($user->two_factor_secret, $raw);

        $code = $this->google2fa()->getCurrentOtp($user->two_factor_secret);

        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => $code])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirm_rejects_an_invalid_code(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/profile/two-factor');

        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => $this->invalidCodeFor($user->fresh()->two_factor_secret)])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_login_with_two_factor_redirects_to_challenge_without_authenticating(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $this->userWithTwoFactor($secret);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_valid_totp_code_completes_login(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $this->post('/two-factor-challenge', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_invalid_two_factor_code_is_rejected(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $this->userWithTwoFactor($secret);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $this->post('/two-factor-challenge', ['code' => $this->invalidCodeFor($secret)])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_works_once_then_is_consumed(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret, ['aaaaaaaaaa-bbbbbbbbbb', 'cccccccccc-dddddddddd']);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $this->post('/two-factor-challenge', ['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user->fresh());

        $this->assertEqualsCanonicalizing(['cccccccccc-dddddddddd'], $user->fresh()->two_factor_recovery_codes);

        // Log out and try the already-consumed code again.
        $this->post('/logout');
        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $this->post('/two-factor-challenge', ['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_without_two_factor_logs_in_without_challenge(): void
    {
        $user = User::factory()->create([
            'email' => 'plain@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $this->post('/login', [
            'email' => 'plain@example.com',
            'password' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_disable_requires_the_current_password(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->actingAs($user)->delete('/profile/two-factor', ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user)->delete('/profile/two-factor', ['password' => 'secret-password'])
            ->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_admin_can_reset_another_users_two_factor(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $target = $this->userWithTwoFactor($secret);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->delete('/users/'.$target->id.'/two-factor')
            ->assertRedirect(route('users.index'));

        $target->refresh();
        $this->assertFalse($target->hasTwoFactorEnabled());
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);

        $this->assertDatabaseHas('activity_logs', [
            'event_type' => 'user_two_factor_reset',
            'subject_id' => $target->id,
        ]);
    }

    public function test_admin_cannot_reset_their_own_two_factor(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $admin = User::factory()->admin()->create(['password' => Hash::make('secret-password')]);
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['aaaaaaaaaa-bbbbbbbbbb'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin->fresh())
            ->delete('/users/'.$admin->id.'/two-factor')
            ->assertSessionHasErrors('user');

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_enabling_two_factor_rotates_the_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);

        $this->actingAs($user)->post('/profile/two-factor');
        $user->refresh();

        $this->actingAs($user)->post('/profile/two-factor/confirm', [
            'code' => $this->google2fa()->getCurrentOtp($user->two_factor_secret),
        ]);

        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    public function test_regular_user_cannot_reset_two_factor(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $target = $this->userWithTwoFactor($secret);

        $this->actingAs(User::factory()->user()->create())
            ->delete('/users/'.$target->id.'/two-factor')
            ->assertForbidden();

        $this->assertTrue($target->fresh()->hasTwoFactorEnabled());
    }

    public function test_two_factor_challenge_is_rate_limited(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $this->userWithTwoFactor($secret);

        $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'secret-password',
        ]);

        $invalid = $this->invalidCodeFor($secret);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/two-factor-challenge', ['code' => $invalid]);
        }

        $this->post('/two-factor-challenge', ['code' => $invalid])->assertStatus(429);
    }

    public function test_trusting_a_device_records_it_and_sets_a_cookie(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'secret-password']);
        $response = $this->post('/two-factor-challenge', [
            'code' => $this->google2fa()->getCurrentOtp($secret),
            'trust_device' => true,
        ]);

        $response->assertRedirect(route('dashboard'))->assertCookie(TrustedDeviceManager::COOKIE);
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseCount('two_factor_trusted_devices', 1);
    }

    public function test_trusted_device_lets_the_next_login_skip_the_challenge(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');

        $this->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'secret-password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_trusted_device_cookie_does_not_bypass_the_password(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');

        $this->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_expired_trusted_device_still_requires_the_challenge(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token', now()->subDay());

        $this->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->post('/login', ['email' => 'user@example.com', 'password' => 'secret-password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_profile_password_change_clears_trusted_devices_and_current_cookie(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');
        $this->assertDatabaseCount('two_factor_trusted_devices', 1);

        $this->actingAs($user)
            ->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->put('/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'locale' => $user->locale,
                'date_locale' => $user->date_locale,
                'default_per_page' => 10,
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertCookieExpired(TrustedDeviceManager::COOKIE);

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
        $this->assertTrue(Hash::check('new-secret-password', $user->fresh()->password));
    }

    public function test_admin_password_change_clears_target_users_trusted_devices(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $target = $this->userWithTwoFactor($secret);
        $admin = User::factory()->admin()->create();
        $this->seedTrustedDevice($target, 'known-token');
        $this->assertDatabaseCount('two_factor_trusted_devices', 1);

        $this->actingAs($admin)
            ->put('/users/'.$target->id, [
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'locale' => $target->locale,
                'password' => 'new-target-password',
                'password_confirmation' => 'new-target-password',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
        $this->assertTrue(Hash::check('new-target-password', $target->fresh()->password));
    }

    public function test_disabling_two_factor_clears_trusted_devices(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');
        $this->assertDatabaseCount('two_factor_trusted_devices', 1);

        $this->actingAs($user)
            ->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->delete('/profile/two-factor', ['password' => 'secret-password'])
            ->assertRedirect(route('profile.edit'))
            ->assertCookieExpired(TrustedDeviceManager::COOKIE);

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
    }

    public function test_admin_reset_clears_a_users_trusted_devices(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $target = $this->userWithTwoFactor($secret);
        $target->twoFactorTrustedDevices()->create([
            'token' => hash('sha256', 'seed-token'),
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete('/users/'.$target->id.'/two-factor')
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
    }

    public function test_profile_lists_trusted_devices(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user);

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Edit')
                ->has('twoFactorDevices', 1)
                ->where('twoFactorDevices.0.is_current', false));
    }

    public function test_listing_flags_the_current_device(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');

        $this->actingAs($user)
            ->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->has('twoFactorDevices', 1)
                ->where('twoFactorDevices.0.is_current', true));
    }

    public function test_revoking_the_current_device_forgets_the_cookie(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'known-token');
        $device = $user->twoFactorTrustedDevices()->first();

        $this->actingAs($user)
            ->withCookie(TrustedDeviceManager::COOKIE, 'known-token')
            ->delete('/profile/two-factor/devices/'.$device->id)
            ->assertRedirect(route('profile.edit'))
            ->assertCookieExpired(TrustedDeviceManager::COOKIE);
    }

    public function test_expired_trusted_devices_are_not_listed(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'expired-token', now()->subDay());

        $this->actingAs($user)
            ->get('/profile')
            ->assertInertia(fn (Assert $page) => $page->has('twoFactorDevices', 0));
    }

    public function test_user_can_revoke_a_trusted_device(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user);
        $device = $user->twoFactorTrustedDevices()->first();

        $this->actingAs($user)
            ->delete('/profile/two-factor/devices/'.$device->id)
            ->assertRedirect(route('profile.edit'));

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
    }

    public function test_user_cannot_revoke_another_users_trusted_device(): void
    {
        $owner = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());
        $this->seedTrustedDevice($owner);
        $device = $owner->twoFactorTrustedDevices()->first();

        $this->actingAs(User::factory()->create())
            ->delete('/profile/two-factor/devices/'.$device->id)
            ->assertForbidden();

        $this->assertDatabaseCount('two_factor_trusted_devices', 1);
    }

    public function test_user_can_revoke_all_trusted_devices(): void
    {
        $secret = $this->google2fa()->generateSecretKey();
        $user = $this->userWithTwoFactor($secret);
        $this->seedTrustedDevice($user, 'token-one');
        $this->seedTrustedDevice($user, 'token-two');

        $this->actingAs($user)
            ->withCookie(TrustedDeviceManager::COOKIE, 'token-one')
            ->delete('/profile/two-factor/devices')
            ->assertRedirect(route('profile.edit'))
            ->assertCookieExpired(TrustedDeviceManager::COOKIE);

        $this->assertDatabaseCount('two_factor_trusted_devices', 0);
    }

    public function test_trusted_device_user_id_is_cast_to_integer(): void
    {
        $user = $this->userWithTwoFactor($this->google2fa()->generateSecretKey());
        $this->seedTrustedDevice($user);
        $device = $user->twoFactorTrustedDevices()->first();

        // Some drivers hydrate bigint columns as strings; the cast keeps the
        // ownership check (===) type-safe regardless of the connection.
        $this->assertIsInt($device->user_id);

        $device->user_id = (string) $user->id;
        $this->assertSame($user->id, $device->user_id);
    }
}

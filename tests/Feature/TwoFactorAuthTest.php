<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_link_request_sends_notification_without_leaking_missing_users(): void
    {
        Config::set('mail.default', 'smtp');
        Notification::fake();
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->post('/forgot-password', ['email' => 'admin@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success', 'If this email matches an account, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class);

        $this->post('/forgot-password', ['email' => 'missing@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success', 'If this email matches an account, a password reset link has been sent.');
    }

    public function test_password_can_be_reset_with_valid_token_and_sessions_are_invalidated(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $token = Password::createToken($user);
        DB::table('sessions')->insert([
            'id' => 'session-to-delete',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'admin@example.com',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-delete']);
    }

    public function test_invalid_password_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => 'admin@example.com',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasErrors('email');
    }

    public function test_cli_command_resets_password_and_invalidates_sessions(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        DB::table('sessions')->insert([
            'id' => 'cli-session-to-delete',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->artisan('volumevault:reset-password', ['email' => 'admin@example.com'])
            ->expectsQuestion('New password', 'cli-secret-password')
            ->expectsQuestion('Confirm new password', 'cli-secret-password')
            ->expectsOutput('Password reset. Existing browser sessions for this user were invalidated.')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertTrue(Hash::check('cli-secret-password', $user->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'cli-session-to-delete']);
    }
}

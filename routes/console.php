<?php

use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('volumevault:reset-password {email : The account email address}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error('No VolumeVault user was found for this email address.');

        return 1;
    }

    $password = $this->secret('New password');
    $confirmation = $this->secret('Confirm new password');

    $validator = Validator::make([
        'password' => $password,
        'password_confirmation' => $confirmation,
    ], [
        'password' => ['required', 'confirmed', Password::defaults()],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    $user->forceFill([
        'password' => Hash::make($password),
        'remember_token' => Str::random(60),
    ])->save();

    DB::table('sessions')->where('user_id', $user->id)->delete();
    DB::table('password_reset_tokens')->where('email', $user->email)->delete();

    $this->info('Password reset. Existing browser sessions for this user were invalidated.');

    return 0;
})->purpose('Reset a VolumeVault user password from the container CLI');

Schedule::job(new DispatchDueBackupJobsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new SyncDockerVolumesJob)->everyFiveMinutes()->withoutOverlapping();

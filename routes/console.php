<?php

use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RunAlertChecksJob;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

Artisan::command('volumevault:agent {--once : Lease at most one command and exit}', function (ListDockerVolumes $listDockerVolumes) {
    if (! config('volumevault.agent.enabled')) {
        $this->info('VolumeVault agent is disabled.');

        return 0;
    }

    $centralUrl = rtrim((string) config('volumevault.agent.central_url'), '/');
    $token = (string) config('volumevault.agent.token');

    if ($centralUrl === '' || $token === '') {
        $this->error('VOLUMEVAULT_CENTRAL_URL and VOLUMEVAULT_AGENT_TOKEN are required.');

        return 1;
    }

    $client = Http::baseUrl($centralUrl.'/api/v1/agent')
        ->withToken($token)
        ->acceptJson()
        ->timeout(30)
        ->retry(2, 500);

    $metadata = [
        'agent_version' => config('app.version'),
        'docker_version' => trim((string) shell_exec('docker version --format "{{.Server.Version}}" 2>/dev/null')),
        'capabilities' => ['sync_volumes' => true],
    ];

    $client->post('/heartbeat', $metadata)->throw();

    do {
        $lease = $client->post('/commands/lease')->throw()->json('data');

        if (! $lease) {
            if ($this->option('once')) {
                return 0;
            }

            sleep(max(1, (int) config('volumevault.agent.poll_seconds', 10)));

            continue;
        }

        try {
            if ($lease['type'] === 'sync_volumes') {
                $client->post('/commands/'.$lease['id'].'/complete', [
                    'status' => 'completed',
                    'volumes' => $listDockerVolumes->handle(),
                ])->throw();
            } else {
                $client->post('/commands/'.$lease['id'].'/complete', [
                    'status' => 'failed',
                    'error' => 'This agent runtime currently supports sync_volumes commands only.',
                ])->throw();
            }
        } catch (Throwable $exception) {
            $client->post('/commands/'.$lease['id'].'/complete', [
                'status' => 'failed',
                'error' => str($exception->getMessage())->limit(1000)->toString(),
            ])->throw();
        }

        if ($this->option('once')) {
            return 0;
        }
    } while (true);
})->purpose('Run the VolumeVault agent command loop');

Schedule::job(new DispatchDueBackupJobsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new DispatchDueBackupGroupsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new SyncDockerVolumesJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new RunAlertChecksJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::command('volumevault:reconcile-stale-runs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('volumevault:host-path-allowlist:audit')->hourly()->withoutOverlapping();

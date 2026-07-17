<?php

use App\Actions\Docker\ListDockerVolumes;
use App\Jobs\DispatchDueBackupGroupsJob;
use App\Jobs\DispatchDueBackupJobsJob;
use App\Jobs\RunAlertChecksJob;
use App\Jobs\SyncDockerVolumesJob;
use App\Models\AgentCommand;
use App\Models\Host;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
    $bootstrapToken = (string) config('volumevault.agent.token');
    $credentialPath = (string) config('volumevault.agent.credential_path');
    $enrollmentStatePath = $credentialPath.'.enrollment';
    $leaseStatePath = $credentialPath.'.lease';
    $agentToken = File::isFile($credentialPath) ? trim(File::get($credentialPath)) : '';

    if ($centralUrl === '' || ($agentToken === '' && $bootstrapToken === '')) {
        $this->error('VOLUMEVAULT_CENTRAL_URL and an agent bootstrap or persisted credential are required.');

        return 1;
    }

    $metadata = [
        'agent_version' => config('app.version'),
        'docker_version' => trim((string) shell_exec('docker version --format "{{.Server.Version}}" 2>/dev/null')),
        'capabilities' => ['sync_volumes' => true],
    ];
    File::ensureDirectoryExists(dirname($credentialPath));

    if (! is_writable(dirname($credentialPath))) {
        throw new RuntimeException('The agent credential directory is not writable: '.dirname($credentialPath));
    }

    $runtimeLock = fopen($credentialPath.'.runtime.lock', 'c');

    if ($runtimeLock === false || ! flock($runtimeLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another VolumeVault agent process is already using this credential.');
    }

    $persistFile = function (string $path, string $contents): void {
        $temporaryPath = $path.'.'.Str::random(12).'.tmp';

        if (File::put($temporaryPath, $contents, true) === false || ! chmod($temporaryPath, 0600) || ! rename($temporaryPath, $path)) {
            File::delete($temporaryPath);

            throw new RuntimeException('Unable to persist agent state atomically.');
        }
    };
    $clientFor = fn (string $token) => Http::baseUrl($centralUrl.'/api/v1/agent')
        ->withToken($token)
        ->acceptJson()
        ->connectTimeout(10)
        ->timeout(30);
    $enroll = function () use ($clientFor, $bootstrapToken, $metadata, $persistFile, $credentialPath, $enrollmentStatePath): string {
        $enrollmentState = File::isFile($enrollmentStatePath)
            ? json_decode(File::get($enrollmentStatePath), true)
            : null;

        if (! is_array($enrollmentState) || ! isset($enrollmentState['enrollment_request_id'], $enrollmentState['agent_secret'])) {
            $enrollmentState = [
                'enrollment_request_id' => (string) Str::uuid(),
                'agent_secret' => Str::random(64),
            ];
            $persistFile($enrollmentStatePath, json_encode($enrollmentState, JSON_THROW_ON_ERROR));
        }

        $token = (string) $clientFor($bootstrapToken)
            ->retry(2, 500, null, false)
            ->post('/enroll', [...$metadata, ...$enrollmentState])
            ->throw()
            ->json('agent_token');

        if ($token === '') {
            throw new RuntimeException('The central server did not return an agent credential.');
        }

        $persistFile($credentialPath, $token);
        File::delete($enrollmentStatePath);

        return $token;
    };

    if ($agentToken === '') {
        $agentToken = $enroll();
    }

    $client = $clientFor($agentToken);
    $sendHeartbeat = function () use (&$agentToken, &$client, $clientFor, $bootstrapToken, $enroll, $metadata): void {
        $heartbeat = $clientFor($agentToken)
            ->retry(2, 500, null, false)
            ->post('/heartbeat', $metadata);

        if ($heartbeat->unauthorized() && $bootstrapToken !== '') {
            $agentToken = $enroll();
            $client = $clientFor($agentToken);
            $heartbeat = $clientFor($agentToken)
                ->retry(2, 500, null, false)
                ->post('/heartbeat', $metadata);
        }

        $heartbeat->throw();
    };

    do {
        $sendHeartbeat();

        $leaseState = File::isFile($leaseStatePath)
            ? json_decode(File::get($leaseStatePath), true)
            : null;

        if (! is_array($leaseState) || ! isset($leaseState['lease_request_id'], $leaseState['lease_token'])) {
            $leaseState = [
                'lease_request_id' => (string) Str::uuid(),
                'lease_token' => Str::random(64),
            ];
            $persistFile($leaseStatePath, json_encode($leaseState, JSON_THROW_ON_ERROR));
        }

        $lease = $client->retry(2, 500, null, false)->post('/commands/lease', [
            ...$leaseState,
            'lease_minutes' => 60,
        ])->throw()->json('data');

        if (! $lease) {
            File::delete($leaseStatePath);

            if ($this->option('once')) {
                return 0;
            }

            sleep(max(1, (int) config('volumevault.agent.poll_seconds', 10)));

            continue;
        }

        if (in_array($lease['status'], [AgentCommand::STATUS_COMPLETED, AgentCommand::STATUS_FAILED], true)) {
            File::delete($leaseStatePath);

            if ($this->option('once')) {
                return 0;
            }

            continue;
        }

        if ($lease['type'] === AgentCommand::TYPE_SYNC_VOLUMES) {
            try {
                $completion = [
                    'status' => 'completed',
                    'lease_request_id' => $leaseState['lease_request_id'],
                    'lease_token' => $lease['lease_token'],
                    'volumes' => $listDockerVolumes->handle(),
                ];
            } catch (Throwable $exception) {
                $completion = [
                    'status' => 'failed',
                    'lease_request_id' => $leaseState['lease_request_id'],
                    'lease_token' => $lease['lease_token'],
                    'error' => str($exception->getMessage())->limit(1000)->toString(),
                ];
            }
        } else {
            $completion = [
                'status' => 'failed',
                'lease_request_id' => $leaseState['lease_request_id'],
                'lease_token' => $lease['lease_token'],
                'error' => 'This agent runtime currently supports sync_volumes commands only.',
            ];
        }

        $client->retry(2, 500, null, false)->post('/commands/'.$lease['id'].'/complete', $completion)->throw();
        File::delete($leaseStatePath);

        if ($this->option('once')) {
            return 0;
        }
    } while (true);
})->purpose('Run the VolumeVault agent command loop');

Artisan::command('volumevault:agents:expire-offline', function () {
    $expiresBefore = now()->subSeconds(max(60, (int) config('volumevault.agent.offline_after_seconds', 120)));

    $expired = Host::query()
        ->agents()
        ->active()
        ->whereIn('status', [Host::STATUS_ONLINE, Host::STATUS_ERROR])
        ->where(function (Builder $query) use ($expiresBefore): void {
            $query->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', $expiresBefore);
        })
        ->update([
            'status' => Host::STATUS_OFFLINE,
            'updated_at' => now(),
        ]);

    $this->info("Marked {$expired} expired agents offline.");

    return 0;
})->purpose('Mark agents offline when their heartbeat has expired');

Schedule::job(new DispatchDueBackupJobsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new DispatchDueBackupGroupsJob)->everyMinute()->withoutOverlapping();
Schedule::job(new SyncDockerVolumesJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new RunAlertChecksJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::command('volumevault:agents:expire-offline')->everyMinute()->withoutOverlapping();
Schedule::command('volumevault:reconcile-stale-runs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('volumevault:host-path-allowlist:audit')->hourly()->withoutOverlapping();

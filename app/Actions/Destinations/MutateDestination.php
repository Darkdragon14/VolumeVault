<?php

namespace App\Actions\Destinations;

use App\Actions\Backup\WithDockerLabelMutationLocks;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\DockerLabelBackupSetting;
use App\Support\SshHostKey;
use Illuminate\Validation\ValidationException;

class MutateDestination
{
    public function __construct(
        private readonly WithDockerLabelMutationLocks $withLocks,
        private readonly NormalizeDestinationData $normalizeDestinationData,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): BackupDestination
    {
        return $this->withLocks->handleForJobs([], [], function () use ($data): BackupDestination {
            $this->validateHostKeyApproval($data);

            $destination = BackupDestination::query()->create($this->normalizeDestinationData->handle($data));

            if (isset($data['host_key_approval'])) {
                $this->recordHostKeyApproval($destination, '');
            }

            return $destination;
        });
    }

    public function update(BackupDestination $destination, array $data): void
    {
        $this->withLocks->handleForJobs([], [$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings) use ($destination, $data): void {
            $locked = $destinations->get($destination->id);

            $this->ensureNoRunNeedsConfiguration($locked);

            if (! $locked) {
                return;
            }

            $this->validateHostKeyApproval($data, $locked);
            $previousHostKey = (string) $locked->setting('host_key', '');
            $normalizedData = $this->normalizeDestinationData->handle($data, $locked);

            $this->ensureCanDeactivate($locked, (bool) ($normalizedData['is_active'] ?? false), $settings);
            $locked->update($normalizedData);

            if (isset($data['host_key_approval'])) {
                $this->recordHostKeyApproval($locked, $previousHostKey);
            }
        });
    }

    public function setActive(BackupDestination $destination, bool $isActive): void
    {
        $this->withLocks->handleForJobs([], [$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings) use ($destination, $isActive): void {
            $locked = $destinations->get($destination->id);

            if (! $locked) {
                return;
            }

            $this->ensureNoRunNeedsConfiguration($locked);
            $this->ensureCanDeactivate($locked, $isActive, $settings);
            $locked->forceFill(['is_active' => $isActive])->save();
        });
    }

    /** @param array{host: string, port: int, key: string, fingerprint: string, previous_fingerprint: ?string} $data */
    public function approveSftpHostKey(BackupDestination $destination, array $data): void
    {
        $key = SshHostKey::publicKey($data['key']);

        if ($key === '' || ! SshHostKey::isFingerprint($data['fingerprint']) || ! SshHostKey::matches($data['fingerprint'], $key)) {
            throw ValidationException::withMessages(['fingerprint' => 'The discovered key does not match the fingerprint being approved. Fetch the server key again.']);
        }

        $this->withLocks->handleForJobs([], [$destination->id], function ($destinations) use ($destination, $data, $key): void {
            $locked = $destinations->get($destination->id);
            abort_unless($locked, 404);
            $this->ensureNoRunNeedsConfiguration($locked);

            if ($locked->provider !== BackupDestination::PROVIDER_SSH
                || $locked->setting('host') !== $data['host']
                || (int) ($locked->setting('port') ?: 22) !== (int) $data['port']) {
                throw ValidationException::withMessages(['host' => 'The destination endpoint has changed. Fetch its server key again before approving.']);
            }

            $previous = trim((string) $locked->setting('host_key', ''));
            $expectedPrevious = $data['previous_fingerprint'];

            if (($previous === '' && $expectedPrevious !== null)
                || ($previous !== '' && ($expectedPrevious === null || ! SshHostKey::isFingerprint($expectedPrevious)
                    || ! hash_equals(SshHostKey::fingerprint($previous), SshHostKey::fingerprint($expectedPrevious))))) {
                throw ValidationException::withMessages(['previous_fingerprint' => 'The pinned server identity has changed. Reload the destination and explicitly approve the new key.']);
            }

            $locked->forceFill(['settings' => [...($locked->settings ?? []), 'host_key' => $key]])->save();
            $this->recordHostKeyApproval($locked, $previous);
        });
    }

    public function delete(BackupDestination $destination): void
    {
        $this->withLocks->handleAcrossHosts([$destination->id], function ($destinations, ?DockerLabelBackupSetting $settings, $managedJobs) use ($destination): void {
            $locked = $destinations->get($destination->id);
            $message = match (true) {
                $this->isEnabledDefault($destination, $settings) => 'This destination is the active default for Docker label backups. Change or disable that setting before deleting it.',
                $destination->jobs()->where('configuration_source', BackupJob::CONFIGURATION_SOURCE_DOCKER_LABEL)->exists() => 'This destination is used by Docker label managed jobs. Disable or reconfigure label backups before deleting it.',
                $locked?->hasRunInProgress(includeAllFinalizations: true) => 'A backup or restore using this destination is in progress. Wait for it to finish before deleting it.',
                default => null,
            };

            if ($message !== null) {
                throw new DestinationMutationBlocked($message);
            }

            $locked?->delete();
        });
    }

    private function ensureCanDeactivate(BackupDestination $destination, bool $isActive, ?DockerLabelBackupSetting $settings): void
    {
        if (! $isActive && $this->isEnabledDefault($destination, $settings)) {
            throw new DestinationMutationBlocked('This destination is the active default for Docker label backups. Change or disable that setting first.');
        }
    }

    /** @param array<string, mixed> $data */
    private function validateHostKeyApproval(array $data, ?BackupDestination $current = null): void
    {
        $approval = $data['host_key_approval'] ?? null;

        if ($approval === null) {
            return;
        }

        $key = SshHostKey::publicKey((string) ($data['settings']['host_key'] ?? ''));

        if (($data['provider'] ?? null) !== BackupDestination::PROVIDER_SSH || $key === ''
            || ! SshHostKey::isFingerprint($approval['fingerprint']) || ! SshHostKey::matches($approval['fingerprint'], $key)) {
            throw ValidationException::withMessages(['settings.host_key' => 'The discovered key does not match the fingerprint being approved. Fetch the server key again.']);
        }

        if (($current === null && ($approval['previous_host'] !== null || $approval['previous_port'] !== null || $approval['previous_host_key'] !== null))
            || ($current !== null && ($current->setting('host') !== $approval['previous_host']
                || (int) ($current->setting('port') ?: 22) !== (int) $approval['previous_port']
                || (string) $current->setting('host_key', '') !== (string) $approval['previous_host_key']))) {
            throw ValidationException::withMessages(['settings.host_key' => 'The saved server identity has changed. Reload the destination before approving a key.']);
        }
    }

    private function ensureNoRunNeedsConfiguration(?BackupDestination $destination): void
    {
        if ($destination?->hasConfigurationInUse()) {
            throw new DestinationMutationBlocked('A backup or restore still needs this destination configuration. Wait for it to finish before changing it.');
        }
    }

    private function recordHostKeyApproval(BackupDestination $destination, string $previous): void
    {
        ActivityLog::record('sftp_host_key_approved', 'SFTP server host key explicitly approved.', $destination, [
            'fingerprint' => SshHostKey::fingerprint((string) $destination->setting('host_key')),
            'previous_fingerprint' => $previous === '' ? null : SshHostKey::fingerprint($previous),
        ]);
    }

    private function isEnabledDefault(BackupDestination $destination, ?DockerLabelBackupSetting $settings): bool
    {
        return DockerLabelBackupSetting::usesEnabledDestination($destination);
    }
}

<?php

namespace App\Http\Requests;

use App\Actions\Restore\CreateRestoreRun;
use App\Models\BackupDestination;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\RestoreRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'backup_run_id' => ['nullable', 'integer'],
            'selected_backup_key' => ['required', 'string', 'max:2048'],
            'mode' => ['required', 'string', Rule::in([
                RestoreRun::MODE_NEW_VOLUME,
                RestoreRun::MODE_INPLACE,
                RestoreRun::MODE_SAFE_INPLACE,
            ])],
            'target_volume_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'backup_before_overwrite' => ['nullable', 'boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateInPlaceMode($validator);
        });
    }

    /**
     * Guard the destructive in-place modes.
     *
     * They overwrite the source volume itself, so they are restricted to
     * Docker-volume sources and require the user to retype the exact target
     * (= source) volume name — the same "type the name to arm it" pattern used
     * for dangerous actions elsewhere. Both in-place modes are equally
     * destructive to the data, so both demand the confirmation.
     */
    private function validateInPlaceMode(Validator $validator): void
    {
        $mode = (string) $this->input('mode', '');

        if (! in_array($mode, [RestoreRun::MODE_INPLACE, RestoreRun::MODE_SAFE_INPLACE], true)) {
            return;
        }

        $backupJob = $this->route('backupJob');

        if (! $backupJob instanceof BackupJob) {
            $validator->errors()->add('mode', 'Unable to resolve the backup job for this restore.');

            return;
        }

        if ($this->boolean('backup_before_overwrite') && $backupJob->destination?->provider === BackupDestination::PROVIDER_DROPBOX) {
            $validator->errors()->add('backup_before_overwrite', CreateRestoreRun::DROPBOX_SAFETY_BACKUP_MESSAGE);
        }

        $backupRun = BackupRun::query()
            ->whereKey($this->integer('backup_run_id'))
            ->where('backup_job_id', $backupJob->id)
            ->where('status', BackupRun::STATUS_SUCCESS)
            ->whereNotNull('backup_key')
            ->first();
        $backupRun?->setRelation('job', $backupJob);

        if ($backupRun !== null && $backupRun->source_type_snapshot === null) {
            $validator->errors()->add('mode', 'This historical backup does not contain a source snapshot and cannot be restored in place.');

            return;
        }

        $sourceType = $backupRun?->sourceType() ?? $backupJob->sourceType();
        $sourceVolumeName = $backupRun?->sourceVolumeName() ?? $backupJob->volume_name;

        if ($sourceType !== BackupJob::SOURCE_TYPE_DOCKER_VOLUME) {
            $validator->errors()->add('mode', 'In-place restore is only available for Docker volume sources.');

            return;
        }

        if ((string) $this->input('confirmation_text', '') !== (string) $sourceVolumeName) {
            $validator->errors()->add('confirmation_text', 'Type the exact volume name to confirm this in-place restore.');
        }
    }
}

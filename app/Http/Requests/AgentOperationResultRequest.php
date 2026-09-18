<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentOperationResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'result' => ['required', 'array:status,logs,error_message,backup_key,backup_size_bytes,target_volume_name,cleanup_complete,finished_at,duration_seconds,safety_backup'],
            'result.status' => ['required', 'in:success,failed'],
            'result.logs' => ['present', 'nullable', 'string', 'max:262144'],
            'result.error_message' => ['nullable', 'string', 'max:1000'],
            'result.backup_key' => ['nullable', 'string', 'max:4096'],
            'result.backup_size_bytes' => ['nullable', 'integer', 'min:0'],
            'result.target_volume_name' => ['nullable', 'string', 'max:255'],
            'result.cleanup_complete' => ['required', 'boolean:strict', 'accepted'],
            'result.finished_at' => ['required', 'date'],
            'result.duration_seconds' => ['required', 'integer', 'min:0'],
            'result.safety_backup' => ['sometimes', 'required', 'array:status,backup_filename,backup_key,backup_size_bytes,source_volume_name,duration_seconds,error_message'],
            'result.safety_backup.status' => ['required_with:result.safety_backup', 'in:success,failed'],
            'result.safety_backup.backup_filename' => ['required_with:result.safety_backup', 'string', 'max:255'],
            'result.safety_backup.backup_key' => ['nullable', 'string', 'max:4096'],
            'result.safety_backup.backup_size_bytes' => ['nullable', 'integer', 'min:0'],
            'result.safety_backup.source_volume_name' => ['sometimes', 'string', 'max:4096'],
            'result.safety_backup.duration_seconds' => ['required_with:result.safety_backup', 'integer', 'min:0'],
            'result.safety_backup.error_message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

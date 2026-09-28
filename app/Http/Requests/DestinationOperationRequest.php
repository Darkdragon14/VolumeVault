<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DestinationOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:test,list,stats'],
            'backup_run_id' => ['nullable', 'integer', 'prohibited_unless:action,list'],
            'selected_backup_key' => ['prohibited'],
            'selected_backup' => ['prohibited'],
            'docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id'],
            'cursor' => ['nullable', 'string', 'max:32768', 'prohibited_unless:action,list'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ];
    }
}

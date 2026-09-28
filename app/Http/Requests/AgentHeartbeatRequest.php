<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'instance_id' => ['required', 'uuid'],
            'protocol_version' => ['required', 'integer', 'in:1'],
            'version' => ['required', 'string', 'max:100'],
            'docker_status' => ['required', 'in:ready,unavailable'],
            'capabilities' => ['required', 'array', 'list', 'max:32'],
            'capabilities.*' => ['required', 'string', 'max:64', 'distinct:strict', 'regex:/\A[a-z0-9-]+\z/'],
            'maintenance_token' => ['nullable', 'uuid'],
            'active_operations' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}

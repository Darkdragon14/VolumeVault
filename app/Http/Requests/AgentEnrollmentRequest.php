<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentEnrollmentRequest extends FormRequest
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
            'credential' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'protocol_version' => ['required', 'integer', 'in:1'],
            'version' => ['required', 'string', 'max:100'],
        ];
    }
}

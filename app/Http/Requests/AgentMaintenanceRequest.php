<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentMaintenanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }
}

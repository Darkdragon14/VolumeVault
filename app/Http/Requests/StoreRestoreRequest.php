<?php

namespace App\Http\Requests;

use App\Models\RestoreRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'selected_backup_key' => ['required', 'string', 'max:2048'],
            'mode' => ['required', 'string', Rule::in([
                RestoreRun::MODE_NEW_VOLUME,
                RestoreRun::MODE_INPLACE,
                RestoreRun::MODE_SAFE_INPLACE,
            ])],
            'target_volume_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'confirmation_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}

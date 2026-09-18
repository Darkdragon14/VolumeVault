<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AgentInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string|Closure>> */
    public function rules(): array
    {
        $metadata = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (strlen((string) $key) > 255 || ($item !== null && (! is_string($item) || strlen($item) > 16384))) {
                    $fail('Invalid Docker metadata.');

                    return;
                }
            }
        };

        return [
            'instance_id' => ['required', 'uuid'],
            'protocol_version' => ['required', 'integer', 'in:1'],
            'sequence' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'docker_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            'volumes' => ['present', 'array', 'list', 'max:5000'],
            'volumes.*' => ['required', 'array:name,driver,mountpoint,labels,options'],
            'volumes.*.name' => ['required', 'string', 'max:255', 'distinct:strict', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
            'volumes.*.driver' => ['nullable', 'string', 'max:255'],
            'volumes.*.mountpoint' => ['nullable', 'string', 'max:4096'],
            'volumes.*.labels' => ['sometimes', 'array', 'max:200', $metadata],
            'volumes.*.options' => ['sometimes', 'array', 'max:200', $metadata],
            'containers' => ['present', 'array', 'list', 'max:5000'],
            'containers.*' => ['required', 'array:id,names,image,state,status'],
            'containers.*.id' => ['required', 'string', 'distinct:strict', 'regex:/\A[a-f0-9]{12,64}\z/'],
            'containers.*.names' => ['nullable', 'string', 'max:1024'],
            'containers.*.image' => ['nullable', 'string', 'max:1024'],
            'containers.*.state' => ['nullable', 'string', 'max:100'],
            'containers.*.status' => ['nullable', 'string', 'max:1024'],
            'host_path_allowlist' => ['present', 'array', 'list', 'max:100'],
            'host_path_allowlist.*' => ['required', 'string', 'max:4096', 'starts_with:/'],
        ];
    }
}

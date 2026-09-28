<?php

namespace App\Services\Agents;

use Closure;
use Illuminate\Support\Facades\Validator;

class AgentLabelInventory
{
    public const INCOMPLETE = ['complete' => false, 'containers' => []];

    /** @return array<string, array<int, string|Closure>> */
    public static function rules(): array
    {
        return [
            'label_inventory' => ['sometimes', 'array:complete,containers'],
            'label_inventory.complete' => ['required_with:label_inventory', 'boolean:strict'],
            'label_inventory.containers' => ['present_with:label_inventory', 'array', 'list', 'max:5000'],
            'label_inventory.containers.*' => ['array:id,name,created,running,labels,mounts'],
            'label_inventory.containers.*.id' => ['required', 'string', 'distinct:strict', 'regex:/\A[a-f0-9]{12,64}\z/'],
            'label_inventory.containers.*.name' => ['required', 'string', 'max:255', 'distinct:strict', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
            'label_inventory.containers.*.created' => ['required', 'date', 'max:100'],
            'label_inventory.containers.*.running' => ['required', 'boolean:strict'],
            'label_inventory.containers.*.labels' => ['present', 'array', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_array($value)) {
                    return;
                }
                $encoded = json_encode($value);
                if ($encoded === false || strlen($encoded) > 32768) {
                    $fail('Docker backup labels exceed the inventory limit.');
                }
                foreach ($value as $key => $item) {
                    if (strlen((string) $key) > 255 || ($item !== null && (! is_string($item) || strlen($item) > 16384))) {
                        $fail('Invalid Docker metadata.');
                    }
                    if (! self::allowsLabel((string) $key)) {
                        $fail('Only Docker backup and Compose identity labels are accepted.');
                    }
                }
            }],
            'label_inventory.containers.*.mounts' => ['present', 'array', 'list', 'max:500'],
            'label_inventory.containers.*.mounts.*' => ['array:name,destination'],
            'label_inventory.containers.*.mounts.*.name' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
            'label_inventory.containers.*.mounts.*.destination' => ['required', 'string', 'max:4096', 'starts_with:/'],
        ];
    }

    public static function allowsLabel(string $key): bool
    {
        return str_starts_with($key, 'dev.darkdragon14.volumevault.') || in_array($key, ['com.docker.compose.project', 'com.docker.compose.service', 'com.docker.compose.container-number', 'com.docker.compose.oneoff', 'com.docker.compose.config-hash'], true);
    }

    public static function matchesContainers(array $containers, array $inspected): bool
    {
        if (count($containers) !== count($inspected)) {
            return false;
        }

        $matched = [];
        foreach ($containers as $container) {
            $matches = array_keys(array_filter($inspected, fn (array $item): bool => str_starts_with($item['id'], $container['id']) || str_starts_with($container['id'], $item['id'])));
            if (count($matches) !== 1 || isset($matched[$matches[0]])) {
                return false;
            }
            $matched[$matches[0]] = true;
        }

        return true;
    }

    /** Keep ordinary inventory intact if optional labels cannot be sent authoritatively. */
    public static function bounded(array $body): array
    {
        if (! isset($body['label_inventory'])) {
            return $body;
        }

        $encoded = json_encode($body);
        if ($encoded === false || strlen($encoded) > 2 * 1024 * 1024 - 8192
            || Validator::make($body, self::rules())->fails()
            || ! self::matchesContainers($body['containers'], $body['label_inventory']['containers'] ?? [])) {
            $body['label_inventory'] = self::INCOMPLETE;
        }

        return $body;
    }
}

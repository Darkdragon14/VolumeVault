<?php

namespace App\Services\Agents;

class AgentOperationRedactor
{
    private array $values = [];

    public function __construct(array $operation)
    {
        foreach (array_filter([$operation['spec']['destination'], $operation['spec']['safety_destination'] ?? null]) as $destination) {
            $this->collect($destination['secrets'] ?? []);
            foreach (explode(';', $destination['secrets']['connection_string'] ?? '') as $part) {
                if (str_contains($part, '=')) {
                    $this->collect(explode('=', $part, 2)[1]);
                }
            }
            $this->collect([$destination['access_key_id'] ?? '', $destination['secret_access_key'] ?? '', $operation['token']]);
            foreach ([$destination['endpoint'] ?? '', ...array_values($destination['settings'] ?? [])] as $value) {
                if (is_string($value) && str_contains($value, '://')) {
                    $parts = parse_url($value);
                    $this->collect([$parts['user'] ?? '', $parts['pass'] ?? '']);
                }
            }
        }
        usort($this->values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    }

    private function collect(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collect($item);
            }
        } elseif (is_string($value) && $value !== '') {
            $this->values = [...$this->values, $value, rawurlencode($value), urlencode($value), base64_encode($value), trim(json_encode($value), '"')];
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $this->collect($decoded);
            }
            foreach (preg_split('/[\r\n]+/', $value) ?: [] as $line) {
                if ($line !== '') {
                    $this->values[] = $line;
                }
            }
        }
    }

    public function clean(?string $text, int $limit = 262144): string
    {
        $text = str_replace($this->values, '[redacted]', (string) $text);

        return mb_strcut(mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 0, $limit, 'UTF-8');
    }
}

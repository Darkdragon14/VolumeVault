<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AgentClient
{
    private ?string $maintenanceToken = null;

    private bool $operationsSupported = false;

    public function __construct(private readonly AgentState $state) {}

    public function enroll(): void
    {
        $identity = $this->state->identity();
        if ($identity['enrolled']) {
            return;
        }
        $response = $this->post('enroll', $identity['enrollment_token'], [
            'credential' => $identity['credential'], 'version' => config('app.version'),
        ]);
        if (($response['host_uuid'] ?? null) !== $identity['host_uuid'] || ($response['protocol_version'] ?? null) !== 1 || ($response['heartbeat_interval'] ?? null) !== 30 || ($response['inventory_interval'] ?? null) !== 300) {
            throw new RuntimeException('Invalid agent enrollment response.');
        }
        $this->state->markEnrolled();
    }

    public function heartbeat(bool $dockerAvailable, int $activeOperations = 0): void
    {
        $response = $this->post('heartbeat', $this->credential(), [
            'version' => config('app.version'), 'docker_status' => $dockerAvailable ? 'ready' : 'unavailable',
            'capabilities' => AgentCompatibility::CAPABILITIES,
            'maintenance_token' => $this->maintenanceToken,
            'active_operations' => $activeOperations,
        ]);
        $token = $response['maintenance_token'] ?? null;
        if ($token !== null && (! is_string($token) || ! Str::isUuid($token))) {
            throw new RuntimeException('Invalid maintenance response.');
        }
        $this->maintenanceToken = $token;
        $this->operationsSupported = ($response['operations_supported'] ?? false) === true;
    }

    public function pullOperation(): ?array
    {
        if (! $this->operationsSupported) {
            return null;
        }

        $response = $this->post('operations/pull', $this->credential(), []);

        return is_array($response['operation'] ?? null)
            ? (new AgentOperationEnvelope)->open($response['operation'], $this->credential()) : null;
    }

    public function operationProgress(string $id, string $token): void
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('Invalid operation identity.');
        }
        $this->post('operations/'.$id.'/progress', $this->credential(), ['token' => $token]);
    }

    public function completeOperation(array $receipt): void
    {
        if (! Str::isUuid($receipt['id'] ?? '')) {
            throw new RuntimeException('Invalid operation identity.');
        }
        $response = $this->post('operations/'.$receipt['id'].'/complete', $this->credential(), [
            'token' => $receipt['token'], 'result' => $receipt['result'],
        ]);
        if (($response['acknowledged'] ?? false) !== true) {
            throw new RuntimeException('Operation completion was not acknowledged.');
        }
    }

    /**
     * @param  list<array{name: string, driver: ?string, mountpoint: ?string, labels: array, options: array}>  $volumes
     * @param  list<array{id: string, names: ?string, image: ?string, state: ?string, status: ?string}>  $containers
     * @param  list<string>  $hostPathAllowlist
     */
    public function inventory(array $volumes, array $containers, array $hostPathAllowlist, ?string $dockerVersion = null, ?array $labelInventory = null): void
    {
        $this->post('inventory', $this->credential(), AgentLabelInventory::bounded([
            'sequence' => $this->state->nextSequence(), 'volumes' => $volumes, 'containers' => $containers,
            'host_path_allowlist' => $hostPathAllowlist,
            'docker_version' => $dockerVersion,
            ...($labelInventory === null ? [] : ['label_inventory' => $labelInventory]),
        ]));
    }

    private function credential(): string
    {
        $identity = $this->state->identity();
        if (! $identity['enrolled']) {
            throw new RuntimeException('Agent is not enrolled.');
        }

        return $identity['host_uuid'].'.'.$identity['credential'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $endpoint, string $token, array $body): array
    {
        $identity = $this->state->identity();
        $options = [
            'verify' => $this->state->caPath(), 'allow_redirects' => false, 'proxy' => '',
            'stream_context' => ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]],
        ];
        if (defined('CURLOPT_SSLVERSION')) {
            $options['curl'] = [CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => ''];
        }
        try {
            $response = Http::asJson()->acceptJson()->withToken($token)->connectTimeout(5)->timeout(20)
                ->withOptions($options)->post($identity['origin'].'/agent/v1/'.$endpoint, array_merge($body, [
                    'instance_id' => $identity['instance_id'], 'protocol_version' => 1,
                ]));
        } catch (\Throwable) {
            throw new RuntimeException('Agent connection failed.');
        }
        if ($response->status() === 409 && (isset($response->json()['supported_protocols']) || $endpoint === 'enroll')) {
            throw new AgentProtocolException('Agent protocol is incompatible with the orchestrator.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Agent request failed (HTTP '.$response->status().').');
        }

        return is_array($response->json()) ? $response->json() : [];
    }
}

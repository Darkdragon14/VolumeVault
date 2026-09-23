<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgentMaintenanceRequest;
use App\Http\Requests\StoreDockerHostRequest;
use App\Models\DockerHost;
use App\Services\Agents\AgentCompatibility;
use App\Services\Agents\AgentLifecycle;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\OperationalHostScope;
use App\Support\DeploymentMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DockerHostController extends Controller
{
    public function index(AgentLifecycle $lifecycle, AgentCompatibility $compatibility, OperationalHostScope $scope): Response
    {
        return Inertia::render('DockerHosts/Index', [
            'hosts' => DockerHost::withCount(['volumes' => fn ($query) => $query->where('exists', true)])
                ->orderBy('id')->get()->map(fn (DockerHost $host): array => [
                    ...$scope->summary($host->id),
                    ...$host->only(['id', 'uuid', 'name', 'driver', 'last_seen_at', 'last_inventory_at', 'agent_version', 'docker_status', 'docker_version']),
                    ...$lifecycle->state($host),
                    'status' => $host->agentStatus(),
                    'role' => $host->isLocal() ? DeploymentMode::mode() : 'agent',
                    'local_execution_enabled' => $host->isLocal() && DeploymentMode::localExecutionEnabled(),
                    'volume_count' => $host->volumes_count,
                    'container_count' => $host->isLocal() ? (DeploymentMode::localExecutionEnabled() ? $host->docker_container_count : null) : count($host->agent_containers ?? []),
                    'agent_version' => $host->isLocal() ? config('app.version') : $host->agent_version,
                    'protocol_version' => $host->agent_protocol_version,
                    'capabilities' => $host->agent_capabilities ?? [],
                    'compatibility' => $host->isLocal() ? 'compatible' : $compatibility->status($host),
                    'update_status' => $host->isLocal() ? 'current' : $compatibility->updateStatus($host->agent_version),
                    'target_version' => $compatibility->targetVersion(),
                ]),
            'agentsEnabled' => (bool) config('volumevault.agents.enabled'),
            'agentUrl' => (string) config('volumevault.agents.url'),
            'deploymentMode' => DeploymentMode::mode(),
            'targetAgentImage' => (string) config('volumevault.agents.image'),
            'orchestratorVersion' => (string) config('app.version'),
        ]);
    }

    public function store(StoreDockerHostRequest $request, AgentRegistry $registry): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $registry): array {
            $host = DockerHost::create($request->validated());

            return ['host' => $host->only(['id', 'uuid', 'name']), 'installation' => $registry->issueEnrollment($host)];
        });

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    public function enrollment(DockerHost $dockerHost, AgentRegistry $registry): JsonResponse
    {
        return response()->json(['installation' => $registry->issueEnrollment($dockerHost)])->header('Cache-Control', 'no-store');
    }

    public function revoke(DockerHost $dockerHost, AgentRegistry $registry): JsonResponse
    {
        $registry->revoke($dockerHost);

        return response()->json(['revoked' => true])->header('Cache-Control', 'no-store');
    }

    public function maintenance(AgentMaintenanceRequest $request, DockerHost $dockerHost, AgentLifecycle $lifecycle): JsonResponse
    {
        return response()->json($lifecycle->setMaintenance($dockerHost, $request->boolean('enabled')))->header('Cache-Control', 'no-store');
    }

    public function updateGuide(DockerHost $dockerHost, AgentLifecycle $lifecycle): JsonResponse
    {
        return response()->json($lifecycle->updateGuide($dockerHost))->header('Cache-Control', 'no-store');
    }
}

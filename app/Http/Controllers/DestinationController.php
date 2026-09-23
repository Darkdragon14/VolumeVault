<?php

namespace App\Http\Controllers;

use App\Actions\Destinations\DestinationMutationBlocked;
use App\Actions\Destinations\MutateDestination;
use App\Concerns\PaginateWithPreference;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Models\DockerHost;
use App\Services\Agents\AgentExecution;
use App\Services\BackupDestinations\DestinationStorage;
use App\Services\BackupDestinations\TestBackupDestination;
use App\Support\DeploymentMode;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    use PaginateWithPreference;

    public function index(Request $request): Response
    {
        $perPage = $this->perPageForRequest($request);
        $query = BackupDestination::query();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $query->latest();

        return Inertia::render('Destinations/Index', [
            'destinationOperationHosts' => app(\App\Services\BackupDestinations\DestinationOperations::class)->hostOptions(),
            'destinations' => $this->paginateForInertia($query, $perPage, fn (BackupDestination $d): array => [...$d->safeForFrontend(), 'docker_host_id' => $d->docker_host_id]),
            'defaultPerPage' => $request->user()->default_per_page ?? 10,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Destinations/Form', [
            'destination' => null,
            'destinationOperationHosts' => app(\App\Services\BackupDestinations\DestinationOperations::class)->hostOptions(),
            'hosts' => DockerHost::query()->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('id', '!=', DockerHost::LOCAL_ID))->orderBy('name')->get()->map(fn (DockerHost $host): array => app(AgentExecution::class)->summary($host, includePaths: true)),
            'providers' => $this->providerOptions(),
        ]);
    }

    public function store(StoreDestinationRequest $request, MutateDestination $mutateDestination)
    {
        $destination = $mutateDestination->create([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        ActivityLog::record('backup_destination_created', 'Backup destination created.', $destination);

        return redirect()->route('destinations.index')->with('success', 'Destination created.');
    }

    public function edit(BackupDestination $destination): Response
    {
        return Inertia::render('Destinations/Form', [
            'destination' => [...$destination->safeForFrontend(), 'docker_host_id' => $destination->docker_host_id],
            'destinationOperationHosts' => app(\App\Services\BackupDestinations\DestinationOperations::class)->hostOptions(),
            'hosts' => DockerHost::query()->when(DeploymentMode::isOrchestrator(), fn ($query) => $query->where('id', '!=', DockerHost::LOCAL_ID))->orderBy('name')->get()->map(fn (DockerHost $host): array => app(AgentExecution::class)->summary($host, includePaths: true)),
            'providers' => $this->providerOptions(),
        ]);
    }

    public function update(UpdateDestinationRequest $request, BackupDestination $destination, MutateDestination $mutateDestination)
    {
        $data = [
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active'),
        ];

        try {
            $mutateDestination->update($destination, $data);
        } catch (DestinationMutationBlocked $exception) {
            throw ValidationException::withMessages(['is_active' => $exception->getMessage()]);
        }

        return redirect()->route('destinations.index')->with('success', 'Destination updated.');
    }

    public function destroy(BackupDestination $destination, MutateDestination $mutateDestination)
    {
        try {
            $mutateDestination->delete($destination);
        } catch (DestinationMutationBlocked $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('destinations.index')->with('success', 'Destination deleted.');
    }

    public function updateActive(Request $request, BackupDestination $destination, MutateDestination $mutateDestination)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');

        try {
            $mutateDestination->setActive($destination, $isActive);
        } catch (DestinationMutationBlocked $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $isActive ? 'Destination enabled.' : 'Destination disabled.');
    }

    public function test(Request $request, BackupDestination $destination, TestBackupDestination $testBackupDestination)
    {
        $data = $request->validate(['docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id']]);
        $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);
        $hostId = $operations->hostId($destination, isset($data['docker_host_id']) ? (int) $data['docker_host_id'] : null);
        if ($hostId !== DockerHost::LOCAL_ID) {
            return response()->json(['data' => $operations->safe($operations->create($destination, 'test', $hostId))], 202);
        }
        $result = $testBackupDestination->handle($destination);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function hostKey(Request $request, DestinationStorage $storage)
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'docker_host_id' => ['nullable', 'integer', 'exists:docker_hosts,id'],
        ]);

        if (isset($data['docker_host_id']) && (int) $data['docker_host_id'] !== DockerHost::LOCAL_ID) {
            $operations = app(\App\Services\BackupDestinations\DestinationOperations::class);

            return response()->json(['data' => $operations->safe($operations->createHostKey($data['host'], (int) ($data['port'] ?? 22), (int) $data['docker_host_id']))], 202);
        }

        try {
            return response()->json($storage->probeHostKey($data['host'], (int) ($data['port'] ?? 22)));
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => str(trim($exception->getMessage()) ?: 'Unable to reach the SSH server.')->limit(300)->toString(),
            ], 422);
        }
    }

    public function hostKeyOperation(\App\Models\AgentOperation $operation): \Illuminate\Http\JsonResponse
    {
        abort_unless($operation->kind === 'destination' && $operation->destination_action === 'host_key' && $operation->backup_destination_id === null, 404);

        return response()->json(['data' => app(\App\Services\BackupDestinations\DestinationOperations::class)->safe($operation)]);
    }

    private function providerOptions(): array
    {
        return BackupDestination::providerOptions();
    }
}

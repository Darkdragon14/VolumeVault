<?php

namespace App\Http\Controllers;

use App\Actions\Destinations\NormalizeDestinationData;
use App\Http\Requests\StoreDestinationRequest;
use App\Http\Requests\UpdateDestinationRequest;
use App\Models\ActivityLog;
use App\Models\BackupDestination;
use App\Services\BackupDestinations\TestBackupDestination;
use Inertia\Inertia;
use Inertia\Response;

class DestinationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Destinations/Index', [
            'destinations' => BackupDestination::latest()->get()->map->safeForFrontend(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Destinations/Form', [
            'destination' => null,
            'providers' => BackupDestination::providerOptions(),
        ]);
    }

    public function store(StoreDestinationRequest $request, NormalizeDestinationData $normalizeDestinationData)
    {
        $data = $normalizeDestinationData->handle([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $destination = BackupDestination::create($data);

        ActivityLog::record('backup_destination_created', 'Backup destination created.', $destination);

        return redirect()->route('destinations.index')->with('success', 'Destination created.');
    }

    public function edit(BackupDestination $destination): Response
    {
        return Inertia::render('Destinations/Form', [
            'destination' => $destination->safeForFrontend(),
            'providers' => BackupDestination::providerOptions(),
        ]);
    }

    public function update(UpdateDestinationRequest $request, BackupDestination $destination, NormalizeDestinationData $normalizeDestinationData)
    {
        $data = $normalizeDestinationData->handle([
            ...$request->validated(),
            'use_path_style_endpoint' => $request->boolean('use_path_style_endpoint'),
            'is_active' => $request->boolean('is_active'),
        ], $destination);

        $destination->update($data);

        return redirect()->route('destinations.index')->with('success', 'Destination updated.');
    }

    public function destroy(BackupDestination $destination)
    {
        $destination->delete();

        return redirect()->route('destinations.index')->with('success', 'Destination deleted.');
    }

    public function test(BackupDestination $destination, TestBackupDestination $testBackupDestination)
    {
        $result = $testBackupDestination->handle($destination);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }
}

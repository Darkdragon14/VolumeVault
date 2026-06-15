<?php

namespace App\Http\Controllers;

use App\Actions\Backup\BackupStack;
use App\Http\Requests\StackBackupRequest;
use App\Models\BackupDestination;
use App\Models\DockerVolume;
use App\Services\Volumes\VolumeBackupSummaries;
use Inertia\Inertia;
use Inertia\Response;

class StackController extends Controller
{
    public function index(VolumeBackupSummaries $volumeBackupSummaries): Response
    {
        $volumes = DockerVolume::query()
            ->orderByDesc('exists')
            ->orderBy('name')
            ->get();

        return Inertia::render('Stacks/Index', [
            'stacks' => $volumeBackupSummaries->forStacks($volumes),
            'destinations' => BackupDestination::where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map->safeForFrontend(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'appTimezone' => config('app.timezone'),
        ]);
    }

    public function backup(StackBackupRequest $request, BackupStack $backupStack)
    {
        $stackName = $request->stackName();
        $summary = $backupStack->handle($stackName, $request->validated());

        return back()->with('success', $this->summaryMessage($stackName, $summary));
    }

    /**
     * @param  array{created: int, queued: int, skipped: int}  $summary
     */
    private function summaryMessage(?string $stackName, array $summary): string
    {
        $label = $stackName ?? 'volumes without a stack';
        $message = "Stack backup started for {$label}: created {$summary['created']} backup job(s), queued {$summary['queued']} run(s).";

        if ($summary['skipped'] > 0) {
            $message .= " {$summary['skipped']} job(s) skipped (inactive, already running, or unavailable).";
        }

        return $message;
    }
}

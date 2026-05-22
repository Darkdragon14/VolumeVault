<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Restore\CreateRestoreRun;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRestoreRequest;
use App\Jobs\RunRestoreJob;
use App\Models\BackupJob;
use App\Models\RestoreRun;
use Illuminate\Http\JsonResponse;

class RestoreController extends Controller
{
    use ResolvesApiHosts;

    public function store(StoreRestoreRequest $request, BackupJob $backupJob, CreateRestoreRun $createRestoreRun): JsonResponse
    {
        $run = $createRestoreRun->handle($backupJob, $request->validated(), $request->user());
        RunRestoreJob::dispatch($run->id);

        return response()->json(['data' => $this->serializeRun($run->load('host'))], 202);
    }

    private function serializeRun(RestoreRun $run): array
    {
        $data = $run->toArray();
        unset($data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($run->host),
        ];
    }
}

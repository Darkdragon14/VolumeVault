<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupRunController extends Controller
{
    use ResolvesApiHosts;

    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveHostScope($request);

        return response()->json([
            'data' => $this->applyHostScope(BackupRun::with('job.destination', 'host')->latest(), $scope)
                ->limit(100)
                ->get()
                ->map(fn (BackupRun $run) => $this->serializeRun($run)),
        ]);
    }

    public function show(Request $request, BackupRun $backupRun): JsonResponse
    {
        $this->authorizeHostAccess($request, $backupRun->host_id);

        return response()->json(['data' => $this->serializeRun($backupRun->load('job.destination', 'host'))]);
    }

    private function serializeRun(BackupRun $run): array
    {
        $data = $run->toArray();
        unset($data['host']);

        return [
            ...$data,
            'host' => $this->safeHost($run->host),
        ];
    }
}

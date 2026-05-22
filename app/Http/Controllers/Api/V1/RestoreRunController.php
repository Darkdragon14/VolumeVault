<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Models\RestoreRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestoreRunController extends Controller
{
    use ResolvesApiHosts;

    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveHostScope($request);

        return response()->json([
            'data' => $this->applyHostScope(RestoreRun::with('job.destination', 'destination', 'host')->latest(), $scope)
                ->limit(100)
                ->get()
                ->map(fn (RestoreRun $run) => $this->serializeRun($run)),
        ]);
    }

    public function show(RestoreRun $restoreRun): JsonResponse
    {
        return response()->json(['data' => $this->serializeRun($restoreRun->load('job.destination', 'destination', 'host'))]);
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

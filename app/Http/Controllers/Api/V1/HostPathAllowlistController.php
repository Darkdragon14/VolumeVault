<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DockerHost;
use App\Services\BackupSources\HostPathAllowlistAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HostPathAllowlistController extends Controller
{
    public function __invoke(Request $request, HostPathAllowlistAudit $audit): JsonResponse
    {
        $validated = $request->validate([
            'docker_host_id' => ['sometimes', 'integer', 'min:1', 'exists:docker_hosts,id'],
        ]);

        return response()->json([
            'data' => $audit->inspect((int) ($validated['docker_host_id'] ?? DockerHost::LOCAL_ID)),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiHosts;
use App\Http\Controllers\Controller;
use App\Models\Host;
use App\Services\Hosts\HostEnrollmentTokens;
use App\Services\Hosts\HostLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HostController extends Controller
{
    use ResolvesApiHosts;

    public function index(Request $request): JsonResponse
    {
        $hostIds = $request->user()?->accessibleHostIds() ?? [];

        return response()->json([
            'data' => Host::query()
                ->whereIn('id', $hostIds)
                ->orderByRaw("case when type = 'local' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map(fn (Host $host) => $this->safeHost($host)),
        ]);
    }

    public function show(Request $request, Host $host): JsonResponse
    {
        if (! $request->user()?->canAccessHostId($host->id)) {
            abort(403);
        }

        return response()->json(['data' => $this->safeHost($host)]);
    }

    public function store(Request $request, HostLimitService $limits, HostEnrollmentTokens $tokens): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        [$host, $enrollmentToken] = $limits->withActivationSlot(function () use ($data, $tokens): array {
            $host = Host::create([
                'name' => $data['name'],
                'type' => Host::TYPE_AGENT,
                'status' => Host::STATUS_OFFLINE,
                'is_active' => true,
                'capabilities' => [],
                'metadata' => [],
            ]);

            return [$host, $tokens->issue($host)];
        });

        return response()->json([
            'data' => $this->safeHost($host),
            'enrollment_token' => $enrollmentToken,
        ], 201);
    }

    public function update(Request $request, Host $host): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(Host::STATUSES)],
        ]);

        if ($host->type === Host::TYPE_LOCAL && $data['status'] !== Host::STATUS_ONLINE) {
            throw ValidationException::withMessages([
                'status' => 'The local host must remain online.',
            ]);
        }

        $host->update($data);

        return response()->json(['data' => $this->safeHost($host->refresh())]);
    }

    public function activate(Host $host, HostLimitService $limits): JsonResponse
    {
        $limits->withActivationSlot(fn () => $host->forceFill(['is_active' => true])->save(), $host);

        return response()->json(['data' => $this->safeHost($host->refresh())]);
    }

    public function deactivate(Host $host): JsonResponse
    {
        if ($host->type === Host::TYPE_LOCAL) {
            throw ValidationException::withMessages([
                'host' => 'The local host cannot be deactivated.',
            ]);
        }

        $host->forceFill(['is_active' => false])->save();

        return response()->json(['data' => $this->safeHost($host->refresh())]);
    }

    public function enrollmentToken(Host $host, HostEnrollmentTokens $tokens): JsonResponse
    {
        if ($host->type !== Host::TYPE_AGENT) {
            throw ValidationException::withMessages([
                'host' => 'Only agent hosts use enrollment tokens.',
            ]);
        }

        return response()->json([
            'data' => $this->safeHost($host),
            'enrollment_token' => $tokens->issue($host),
        ]);
    }
}

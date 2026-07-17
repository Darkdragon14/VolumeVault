<?php

namespace App\Http\Controllers;

use App\Models\Host;
use App\Services\Hosts\HostEnrollmentTokens;
use App\Services\Hosts\HostLimitService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HostController extends Controller
{
    public function index(Request $request, HostLimitService $limits): Response
    {
        Inertia::encryptHistory();
        $encryptedToken = $request->session()->pull('host_enrollment_token');

        return Inertia::render('Hosts/Index', [
            'hosts' => Host::query()
                ->orderByRaw("case when type = 'local' then 0 else 1 end")
                ->orderBy('name')
                ->get()
                ->map->safeForFrontend(),
            'limits' => [
                'active' => $limits->activeCount(),
                'active_limit' => $limits->activeLimit(),
                'can_create_active_host' => $limits->canActivate(),
            ],
            'enrollmentToken' => is_string($encryptedToken) ? Crypt::decryptString($encryptedToken) : null,
        ]);
    }

    public function store(Request $request, HostLimitService $limits, HostEnrollmentTokens $tokens)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        [$host, $token] = $limits->withActivationSlot(function () use ($data, $tokens): array {
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

        return redirect()
            ->route('hosts.index')
            ->with('success', 'Host created.')
            ->with('host_enrollment_token', Crypt::encryptString($token));
    }

    public function update(Request $request, Host $host)
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

        return redirect()->route('hosts.index')->with('success', 'Host updated.');
    }

    public function activate(Host $host, HostLimitService $limits)
    {
        $limits->withActivationSlot(fn () => $host->forceFill(['is_active' => true])->save(), $host);

        return redirect()->route('hosts.index')->with('success', 'Host activated.');
    }

    public function deactivate(Host $host)
    {
        if ($host->type === Host::TYPE_LOCAL) {
            throw ValidationException::withMessages([
                'host' => 'The local host cannot be deactivated.',
            ]);
        }

        $host->forceFill(['is_active' => false])->save();

        return redirect()->route('hosts.index')->with('success', 'Host deactivated.');
    }

    public function enrollmentToken(Host $host, HostEnrollmentTokens $tokens)
    {
        if ($host->type !== Host::TYPE_AGENT) {
            throw ValidationException::withMessages([
                'host' => 'Only agent hosts use enrollment tokens.',
            ]);
        }

        $token = $tokens->issue($host);

        return redirect()
            ->route('hosts.index')
            ->with('success', 'Enrollment token regenerated.')
            ->with('host_enrollment_token', Crypt::encryptString($token));
    }
}

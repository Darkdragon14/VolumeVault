<?php

namespace App\Http\Controllers;

use App\Concerns\PaginateWithPreference;
use App\Models\ActivityLog;
use App\Models\Host;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use PaginateWithPreference;

    public function index(Request $request): Response
    {
        $perPage = $this->perPageForRequest($request);

        $query = User::query()
            ->latest()
            ->select(['id', 'name', 'email', 'role', 'locale', 'two_factor_confirmed_at', 'created_at', 'updated_at']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Users/Index', [
            'users' => $this->paginateForInertia($query, $perPage),
            'defaultPerPage' => $perPage,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'managedUser' => null,
            'roles' => User::ROLES,
            'hostAccessModes' => User::HOST_ACCESS_MODES,
            'hosts' => $this->hosts(),
            'locales' => User::SUPPORTED_LOCALES,
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'host_access_mode' => $request->input('host_access_mode', User::HOST_ACCESS_ALL),
            'host_ids' => $request->input('host_ids', []),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
            'host_access_mode' => ['required', 'string', Rule::in(User::HOST_ACCESS_MODES)],
            'host_ids' => ['array'],
            'host_ids.*' => ['integer', Rule::exists('hosts', 'id')],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->validateHostAccessSelection($data);

        $user = User::create(Arr::except($data, ['host_ids']));
        $this->syncHostAccess($user, $data);

        ActivityLog::record('user_created', 'User created.', $user, [
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        $user->load('hosts');

        return Inertia::render('Users/Form', [
            'managedUser' => $this->serializeUser($user),
            'roles' => User::ROLES,
            'hostAccessModes' => User::HOST_ACCESS_MODES,
            'hosts' => $this->hosts(),
            'locales' => User::SUPPORTED_LOCALES,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $request->merge([
            'host_access_mode' => $request->input('host_access_mode', $user->host_access_mode ?: User::HOST_ACCESS_ALL),
            'host_ids' => $request->input('host_ids', $user->hosts()->pluck('hosts.id')->all()),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
            'host_access_mode' => ['required', 'string', Rule::in(User::HOST_ACCESS_MODES)],
            'host_ids' => ['array'],
            'host_ids.*' => ['integer', Rule::exists('hosts', 'id')],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        if ($user->isAdmin() && $data['role'] !== User::ROLE_ADMIN && $this->isLastAdmin($user)) {
            throw ValidationException::withMessages(['role' => 'You cannot demote the last administrator.']);
        }

        $this->validateHostAccessSelection($data);

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update(Arr::except($data, ['host_ids']));
        $this->syncHostAccess($user, $data);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }

        if ($user->isAdmin() && $this->isLastAdmin($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot delete the last administrator.']);
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted.');
    }

    /**
     * Disable a user's second factor on their behalf — the recovery path when
     * someone loses their authenticator app and their recovery codes.
     */
    public function resetTwoFactor(Request $request, User $user)
    {
        // Admins recover *other* people here; resetting your own second factor
        // must go through the profile flow, which re-checks the password.
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'Reset your own two-factor authentication from your profile.',
            ]);
        }

        if (is_null($user->two_factor_secret) && ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('users.index');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $user->twoFactorTrustedDevices()->delete();

        ActivityLog::record('user_two_factor_reset', 'Two-factor authentication reset.', $user, [
            'reset_by' => $request->user()->id,
        ]);

        return redirect()->route('users.index')->with('success', 'Two-factor authentication reset.');
    }

    private function isLastAdmin(User $user): bool
    {
        return User::where('role', User::ROLE_ADMIN)->whereKeyNot($user->id)->doesntExist();
    }

    /**
     * @param  array{role: string, host_access_mode: string, host_ids?: array<int, int|string>}  $data
     */
    private function validateHostAccessSelection(array $data): void
    {
        if ($data['role'] === User::ROLE_ADMIN || $data['host_access_mode'] === User::HOST_ACCESS_ALL) {
            return;
        }

        if (empty($data['host_ids'] ?? [])) {
            throw ValidationException::withMessages([
                'host_ids' => 'Select at least one host or allow access to all hosts.',
            ]);
        }
    }

    /**
     * @param  array{role: string, host_access_mode: string, host_ids?: array<int, int|string>}  $data
     */
    private function syncHostAccess(User $user, array $data): void
    {
        if ($data['role'] === User::ROLE_ADMIN || $data['host_access_mode'] === User::HOST_ACCESS_ALL) {
            $user->hosts()->sync([]);

            return;
        }

        $user->hosts()->sync(array_map('intval', $data['host_ids'] ?? []));
    }

    /**
     * @return list<array{id: int, name: string, type: string, status: string, is_active: bool}>
     */
    private function hosts(): array
    {
        return Host::query()
            ->orderByRaw("case when type = 'local' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->map(fn (Host $host) => [
                'id' => $host->id,
                'name' => $host->name,
                'type' => $host->type,
                'status' => $host->status,
                'is_active' => $host->is_active,
            ])
            ->all();
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, host_access_mode: string, locale: string, created_at: mixed, updated_at: mixed, hosts: list<array{id: int, name: string, type: string}>}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'host_access_mode' => $user->host_access_mode,
            'locale' => $user->locale,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'hosts' => $user->hosts
                ->map(fn (Host $host) => [
                    'id' => $host->id,
                    'name' => $host->name,
                    'type' => $host->type,
                ])
                ->values()
                ->all(),
        ];
    }
}

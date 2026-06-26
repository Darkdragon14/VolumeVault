<?php

namespace App\Http\Controllers;

use App\Concerns\PaginateWithPreference;
use App\Models\ActivityLog;
use App\Models\User;
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
            'locales' => User::SUPPORTED_LOCALES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($data);

        ActivityLog::record('user_created', 'User created.', $user, [
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Form', [
            'managedUser' => $user->only(['id', 'name', 'email', 'role', 'locale']),
            'roles' => User::ROLES,
            'locales' => User::SUPPORTED_LOCALES,
        ]);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        if ($user->isAdmin() && $data['role'] !== User::ROLE_ADMIN && $this->isLastAdmin($user)) {
            throw ValidationException::withMessages(['role' => 'You cannot demote the last administrator.']);
        }

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

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

        ActivityLog::record('user_two_factor_reset', 'Two-factor authentication reset.', $user, [
            'reset_by' => $request->user()->id,
        ]);

        return redirect()->route('users.index')->with('success', 'Two-factor authentication reset.');
    }

    private function isLastAdmin(User $user): bool
    {
        return User::where('role', User::ROLE_ADMIN)->whereKeyNot($user->id)->doesntExist();
    }
}

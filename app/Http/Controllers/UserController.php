<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::latest()->get(['id', 'name', 'email', 'role', 'locale', 'created_at', 'updated_at']),
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

    private function isLastAdmin(User $user): bool
    {
        return User::where('role', User::ROLE_ADMIN)->whereKeyNot($user->id)->doesntExist();
    }
}

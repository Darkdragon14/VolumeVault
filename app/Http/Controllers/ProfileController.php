<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'profileUser' => $request->user()->only(['id', 'name', 'email', 'locale']),
            'locales' => User::SUPPORTED_LOCALES,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('profile.edit')->with('success', 'Profile updated.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\InstallationSaves\ImportSecureInstallationSave;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OnboardingController extends Controller
{
    public function create(): Response
    {
        abort_if(User::exists(), 404);

        return Inertia::render('Auth/Onboarding');
    }

    public function store(Request $request)
    {
        abort_if(User::exists(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            ...$data,
            'role' => User::ROLE_ADMIN,
        ]);

        ActivityLog::record('first_admin_created', 'First administrator created.', $user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Admin account created.');
    }

    public function import(Request $request, ImportSecureInstallationSave $importSecureInstallationSave)
    {
        abort_if(User::exists(), 404);

        $data = $request->validate([
            'save' => ['required', 'file'],
            'previous_app_key' => ['required', 'string'],
        ]);

        try {
            $importSecureInstallationSave->handle($request->file('save')->getPathname(), $data['previous_app_key']);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'save' => str($exception->getMessage())->limit(500)->toString(),
            ]);
        }

        return redirect()->route('login')->with('success', 'Installation imported. Sign in with an imported account.');
    }
}

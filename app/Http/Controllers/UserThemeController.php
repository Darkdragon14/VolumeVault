<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserThemeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['required', 'string', Rule::in(User::SUPPORTED_THEMES)],
        ]);

        $request->user()->forceFill([
            'theme' => $data['theme'],
        ])->save();

        return back();
    }
}

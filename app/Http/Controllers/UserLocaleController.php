<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserLocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(User::SUPPORTED_LOCALES)],
        ]);

        $request->user()->forceFill([
            'locale' => $data['locale'],
        ])->save();

        app()->setLocale($data['locale']);

        return back();
    }
}

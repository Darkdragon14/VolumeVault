<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEnvironmentRequest;
use App\Services\Agents\EnvironmentSelection;
use Illuminate\Http\RedirectResponse;

class UserEnvironmentController extends Controller
{
    public function update(UpdateEnvironmentRequest $request, EnvironmentSelection $selection): RedirectResponse
    {
        $data = $request->validated();
        $selection->select($request, isset($data['docker_host_id']) ? (int) $data['docker_host_id'] : null);

        return redirect()->route('dashboard', [], 303);
    }
}

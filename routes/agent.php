<?php

use App\Http\Controllers\AgentTransportController;
use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\RequireAgentProtocol;
use Illuminate\Support\Facades\Route;

Route::post('enroll', [AgentTransportController::class, 'enroll'])->middleware(['throttle:agent-enrollment', RequireAgentProtocol::class])->name('agents.enroll');
Route::middleware([AuthenticateAgent::class, 'throttle:agent-traffic', RequireAgentProtocol::class])->group(function (): void {
    Route::post('heartbeat', [AgentTransportController::class, 'heartbeat'])->name('agents.heartbeat');
    Route::post('inventory', [AgentTransportController::class, 'inventory'])->name('agents.inventory');
    Route::post('operations/pull', [AgentTransportController::class, 'pull'])->name('agents.operations.pull');
    Route::post('operations/{operation}/progress', [AgentTransportController::class, 'progress'])->whereUuid('operation')->name('agents.operations.progress');
    Route::post('operations/{operation}/complete', [AgentTransportController::class, 'complete'])->whereUuid('operation')->name('agents.operations.complete');
    Route::post('operations/{operation}/relay', [AgentTransportController::class, 'relay'])->whereUuid('operation')->name('agents.operations.relay');
});

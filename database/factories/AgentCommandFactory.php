<?php

namespace Database\Factories;

use App\Models\AgentCommand;
use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentCommand>
 */
class AgentCommandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'host_id' => Host::factory()->agent(),
            'type' => AgentCommand::TYPE_SYNC_VOLUMES,
            'status' => AgentCommand::STATUS_PENDING,
            'payload' => [],
            'secret_payload' => null,
            'lease_until' => null,
            'attempts' => 0,
            'last_error' => null,
        ];
    }
}

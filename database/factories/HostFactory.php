<?php

namespace Database\Factories;

use App\Models\Host;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Host>
 */
class HostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type' => Host::TYPE_AGENT,
            'status' => Host::STATUS_OFFLINE,
            'is_active' => true,
            'last_seen_at' => null,
            'agent_version' => null,
            'docker_version' => null,
            'capabilities' => [],
            'metadata' => [],
            'enrollment_token_hash' => null,
            'enrollment_token_expires_at' => null,
            'enrolled_at' => null,
            'last_error' => null,
        ];
    }

    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local Docker Host',
            'type' => Host::TYPE_LOCAL,
            'status' => Host::STATUS_ONLINE,
        ]);
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Host::TYPE_AGENT,
            'status' => Host::STATUS_OFFLINE,
        ]);
    }
}

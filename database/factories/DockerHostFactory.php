<?php

namespace Database\Factories;

use App\Models\DockerHost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DockerHost>
 */
class DockerHostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
        ];
    }
}

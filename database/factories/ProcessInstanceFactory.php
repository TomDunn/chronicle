<?php

namespace Database\Factories;

use Chronicle\Process\Models\ProcessInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessInstance>
 */
class ProcessInstanceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<ProcessInstance>
     */
    protected $model = ProcessInstance::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            //
        ];
    }
}

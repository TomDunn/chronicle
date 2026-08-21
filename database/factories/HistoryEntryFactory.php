<?php

declare(strict_types=1);

namespace Chronicle\Process\Database\Factories;

use Chronicle\Process\Models\HistoryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HistoryEntry>
 */
class HistoryEntryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<HistoryEntry>
     */
    protected $model = HistoryEntry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => $this->faker->unique()->slug(2),
            'payload' => ['value' => $this->faker->word()],
            'out_of_band' => false,
            'recorded_at' => now(),
        ];
    }
}

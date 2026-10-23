<?php

namespace Database\Factories;

use App\Models\InstallationOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallationOffer>
 */
class InstallationOfferFactory extends Factory
{
    protected $model = InstallationOffer::class;

    /**
     * An active, always-valid offer with no free months yet — attach
     * per-service months with serviceMonths()->create().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Offer '.fake()->unique()->numberBetween(1, 999999),
            'fee' => 10000,
            'active' => true,
            'is_default' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PrepaymentOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrepaymentOffer>
 */
class PrepaymentOfferFactory extends Factory
{
    protected $model = PrepaymentOffer::class;

    /**
     * Default: "4 months for 10,000".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => '4 MONTHS PROMO',
            'months' => 4,
            'price' => 10000,
            'valid_from' => null,
            'valid_to' => null,
            'active' => true,
        ];
    }
}

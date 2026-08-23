<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'SWECOM PLC',
            'location' => '3/CORNERS',
            'email' => 'shalomtech@gmail.com',
            'phone' => fake()->numerify('6########').'/'.fake()->numerify('6########'),
            'tech_number' => fake()->numerify('6########'),
            'momo_number' => fake()->numerify('6########').'/'.fake()->numerify('6########'),
            // Fixed rather than fake()->name() — a "Prefix Firstname
            // Lastname Suffix/Prefix Firstname Lastname Suffix" combination
            // can exceed the momo_name column's varchar(50) limit and fail
            // the insert intermittently depending on the faker seed.
            'momo_name' => 'MUNGWAN HANS/KELVIN MEKUME',
            'logo' => null,
        ];
    }
}

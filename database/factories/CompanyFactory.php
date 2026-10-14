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
        ];
    }

    /**
     * The tenant's company with $attributes applied. The app always reads
     * the FIRST company row (Company::cached(), the settings controllers),
     * so on a database that already holds one — any seeded tenant — a
     * freshly created second row would never be seen. Update that row
     * instead; create one only when there is none.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function current(array $attributes = []): Company
    {
        $company = Company::query()->first();

        if ($company === null) {
            $company = $this->create($attributes);
        } else {
            $company->update($attributes);
        }

        Company::forgetCache();

        return $company;
    }
}

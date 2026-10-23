<?php

namespace Database\Factories;

use App\Models\CustomerCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCharge>
 */
class CustomerChargeFactory extends Factory
{
    protected $model = CustomerCharge::class;

    /**
     * An open installation fee.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerFactory::new(),
            'kind' => CustomerCharge::KIND_INSTALLATION,
            'amount' => 15000,
            'status' => CustomerCharge::STATUS_OPEN,
            'reason' => null,
        ];
    }

    /** An open relocation fee (the customer moved house). */
    public function relocation(): static
    {
        return $this->state(['kind' => CustomerCharge::KIND_RELOCATION, 'amount' => 5000]);
    }

    /** An open re-installation fee (same address). */
    public function reinstallation(): static
    {
        return $this->state(['kind' => CustomerCharge::KIND_REINSTALLATION, 'amount' => 7500]);
    }
}

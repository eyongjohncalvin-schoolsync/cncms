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
}

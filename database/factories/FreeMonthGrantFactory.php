<?php

namespace Database\Factories;

use App\Models\FreeMonthGrant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FreeMonthGrant>
 */
class FreeMonthGrantFactory extends Factory
{
    protected $model = FreeMonthGrant::class;

    /**
     * An approved compensation grant covering every service for two months.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_uuid' => (string) Str::uuid(),
            'customer_id' => CustomerFactory::new(),
            'service_id' => null,
            'reason' => FreeMonthGrant::REASON_COMPENSATION,
            'note' => 'Poor signal',
            'months' => 2,
            'status' => FreeMonthGrant::STATUS_APPROVED,
            'start_period' => '2026-11',
        ];
    }
}

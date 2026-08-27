<?php

namespace Database\Factories;

use App\Models\Complaint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    /**
     * Define the model's default state.
     *
     * submitted_by is a cross-schema FK into the central public.users
     * table, deliberately absent from Complaint's #[Fillable] list (see
     * that model's class doc) — so it is NOT part of this factory's
     * definition array (a plain `->create(['submitted_by' => $id])` would
     * silently drop it, same trap App\Repositories\Eloquent\ComplaintRepository
     * ::create() works around). Callers must use the submittedBy() state
     * below instead, e.g. ComplaintFactory::new()->submittedBy($userId)->create().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => 'operational',
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'urgent' => false,
            'status' => 'open',
            'customer_id' => null,
            'zone_id' => null,
        ];
    }

    /**
     * Sets submitted_by via direct attribute assignment in an afterMaking
     * hook (which runs before the model is saved), bypassing mass
     * assignment entirely so it isn't silently dropped by
     * Complaint's #[Fillable] guard — see this class's definition() doc.
     */
    public function submittedBy(int $userId): static
    {
        return $this->afterMaking(function (Complaint $complaint) use ($userId): void {
            $complaint->submitted_by = $userId;
        });
    }

    public function customerComplaint(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => 'customer',
            'customer_id' => CustomerFactory::new(),
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'urgent' => true,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'escalated_at' => now(),
        ]);
    }
}

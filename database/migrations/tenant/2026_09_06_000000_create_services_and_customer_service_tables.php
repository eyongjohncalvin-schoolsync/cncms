<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Services — the company service catalogue + the per-customer
     * subscription pivot (see .claude/skills/cncms-context/references/
     * services.md).
     *
     * A `services` row is something the operator sells (TV Service — the
     * default — Internet, VOD, Satellite Hosting…). A `customer_service`
     * row is one customer subscribing to one service at the price actually
     * charged them (seeded from the catalogue price, overridable).
     * `customers.bill` STAYS as a cached projection — always rewritten to
     * `sum(customer_service.price)` in the same transaction that touches
     * the pivot (App\Services\CustomerSubscriptionService). The manuscript
     * engine, bill printing, arrears, and every CustomerResource consumer
     * keep reading `customers.bill` exactly as before.
     *
     * Idempotent seed (firstOrCreate by slug) of the four services the
     * owner named, at 0.00 — the real price is operator-specific and set in
     * Settings -> Services. Same seed-via-migration pattern as
     * DefaultRolesSeeder / the seed-scheduled-task migration: reference data
     * with no settings-UI origin belongs in a migration, runs for every
     * tenant on `tenants:migrate` and on provisioning.
     *
     * `service_variants` + `customer_service.service_variant_id` (services.md
     * section 4) were added the same day, before this ever ran or was
     * committed — a service can offer priced sub-options one level deep
     * (the named case: a specific TV channel broadcast, its own price on
     * top of the base TV subscription). No variants are seeded; the
     * operator adds them through Settings -> Services once the catalogue
     * screen exists.
     */
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->string('description', 255)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('uuid', 'idx_services_uuid');
            $table->index('active', 'idx_services_active');
        });

        // Case-insensitive uniqueness on name — "TV Service" and "tv service"
        // must not become two rows. Postgres partial/expression index, no
        // Blueprint-fluent equivalent.
        DB::statement('CREATE UNIQUE INDEX uq_services_name_ci ON services (lower(name))');

        // Exactly one default service — the pre-ticked row on the add form.
        // Partial unique index over the single value `true` (many `false`
        // rows are fine), mirroring uq_roles_single_super.
        DB::statement('CREATE UNIQUE INDEX uq_services_single_default ON services (is_default) WHERE is_default');

        // Priced sub-options under a service, one level deep (services.md
        // section 4) — e.g. specific TV channel broadcasts under the `tv`
        // service. A variant's `price` is independent of its parent
        // service's `price`: subscribing to one adds its own charge, it
        // does not replace the base.
        Schema::create('service_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('service_id', 'idx_service_variants_service');
        });

        DB::statement('CREATE UNIQUE INDEX uq_service_variants_name_ci ON service_variants (service_id, lower(name))');

        Schema::create('customer_service', function (Blueprint $table): void {
            // Own surrogate key: the pivot is Auditable
            // (App\Models\CustomerSubscription) and needs a stable row
            // identity, not just the (customer, service, variant) natural
            // key.
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // restrictOnDelete: a service that any customer holds cannot be
            // deleted — deactivate it instead (SettingsServiceController::
            // destroy() surfaces this as a friendly 422, mirroring
            // CustomerService::delete()'s history guard).
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            // NULL = this row is the plain base service; set = this row is
            // one specific variant of service_id (services.md section 4 —
            // a variant row requires a sibling base row for the same
            // service, enforced in CustomerSubscriptionService, not here).
            $table->foreignId('service_variant_id')->nullable()->constrained('service_variants')->restrictOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index('service_id', 'idx_customer_service_service');
            $table->index('service_variant_id', 'idx_customer_service_variant');
        });

        // NULLS NOT DISTINCT (Postgres 15+, CNCMS runs PG18): without it,
        // plain UNIQUE treats every NULL as distinct from every other NULL,
        // so a customer could hold the SAME service's base subscription
        // (service_variant_id IS NULL) more than once. This makes the two
        // NULLs collide, giving: at most one base row per (customer,
        // service), and still one row per distinct variant (each a
        // different non-null value, never colliding with each other).
        DB::statement(
            'ALTER TABLE customer_service ADD CONSTRAINT uq_customer_service_variant '
            .'UNIQUE NULLS NOT DISTINCT (customer_id, service_id, service_variant_id)'
        );

        $this->seedDefaultServices();
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service');
        Schema::dropIfExists('service_variants');
        Schema::dropIfExists('services');
    }

    /**
     * The four services the owner named (services.md section 3). Seed price
     * is 0.00 on purpose — a real price is operator-specific and set in the
     * catalogue screen; the add form warns on a ticked 0.00 service.
     * firstOrCreate by slug so re-running never duplicates and never
     * overwrites a price the operator has since set.
     */
    private function seedDefaultServices(): void
    {
        $rows = [
            ['slug' => 'tv', 'name' => 'TV Service', 'is_default' => true, 'sort_order' => 1],
            ['slug' => 'internet', 'name' => 'Internet', 'is_default' => false, 'sort_order' => 2],
            ['slug' => 'vod', 'name' => 'Video on Demand', 'is_default' => false, 'sort_order' => 3],
            ['slug' => 'satellite-hosting', 'name' => 'Satellite Hosting', 'is_default' => false, 'sort_order' => 4],
        ];

        $now = now();

        foreach ($rows as $row) {
            $exists = DB::table('services')->where('slug', $row['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('services')->insert([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'price' => 0,
                'is_default' => $row['is_default'],
                'active' => true,
                'sort_order' => $row['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

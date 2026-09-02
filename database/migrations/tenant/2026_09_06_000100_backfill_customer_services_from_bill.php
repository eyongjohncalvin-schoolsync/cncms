<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Backfill (services.md section 3): every existing customer — archived
     * ones included (the SoftDeletes global scope is a Model concern, a raw
     * query builder ignores it) — gets one `customer_service` row pointing
     * at the `tv` (default) service, priced at their current
     * `customers.bill`. `SUM` of that one row equals the row's price, so
     * every customer's `bill` is preserved exactly and nothing downstream
     * moves.
     *
     * Idempotent: a customer who already has ANY `customer_service` row is
     * skipped, so a re-run (or a tenant provisioned after this migration,
     * whose customers were created through the new subscription path
     * already) is a no-op.
     */
    public function up(): void
    {
        $tvServiceId = DB::table('services')->where('slug', 'tv')->value('id');

        if ($tvServiceId === null) {
            // The create+seed migration always runs first; guard anyway so a
            // partially-migrated schema doesn't fatal.
            return;
        }

        $now = now();

        DB::table('customers')
            ->whereNotIn('id', fn ($query) => $query->select('customer_id')->from('customer_service'))
            ->orderBy('id')
            ->select('id', 'bill')
            ->chunkById(500, function ($customers) use ($tvServiceId, $now): void {
                $rows = $customers->map(fn ($customer): array => [
                    'uuid' => (string) Str::uuid7(),
                    'customer_id' => $customer->id,
                    'service_id' => $tvServiceId,
                    'price' => $customer->bill,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('customer_service')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // The pivot rows are the source of truth for `customers.bill` now —
        // dropping them without also rebuilding bill from somewhere would
        // strand the projection. A rollback of this data migration in
        // isolation is not a real scenario; leave the rows in place.
    }
};

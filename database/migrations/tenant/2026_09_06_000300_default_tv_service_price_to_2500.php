<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tops up the `tv` service's catalogue price to 2,500 FCFA (SWECOM's
     * real base rate) on schemas that already ran the create+seed
     * migration before this default existed — that one seeded `tv` at
     * 0.00, per its own now-updated comment.
     *
     * Only touches a row still sitting at the untouched 0.00 seed value —
     * an operator who has already set their own real TV price (swecom
     * already has, independently, before this migration was written) is
     * left completely alone. This is a floor, not an override: it can
     * never clobber a deliberate customization, by construction.
     *
     * Changing the catalogue price does NOT retroactively change any
     * existing customer's bill (services.md section 6 — `customer_service.
     * price` is an independent snapshot); this only changes what a NEW
     * customer's TV subscription defaults to, so nothing to a real
     * customer moves as a result of this migration.
     */
    public function up(): void
    {
        DB::table('services')
            ->where('slug', 'tv')
            ->where('price', 0)
            ->update(['price' => 2500, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible in a meaningful way — we don't know whether a row
        // this touched was genuinely untouched-0.00 or had already been
        // set to exactly 2500 by an operator independently; reverting to
        // 0.00 unconditionally could undo a real admin's deliberate choice.
        // A no-op down() (same reasoning as the sibling permission top-up
        // migrations in this same folder).
    }
};

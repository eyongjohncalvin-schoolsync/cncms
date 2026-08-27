<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `location` (existing) stays a short area/town label — it's what's
     * already shown compactly on the bill's "From:" line and collected at
     * self-service registration (e.g. "Downtown", "3/CORNERS"). `head_office`
     * is new and distinct: the full, formal postal address of the company's
     * head office, meant for letterheads/official documents rather than a
     * one-line locality tag. See
     * .ai/skills/cncms/cncms-context/references/company-settings.md for the
     * reasoning.
     *
     * `rccm_number` / `niu` are Cameroon's two standard business-identity
     * numbers — RCCM (Registre du Commerce et du Crédit Mobilier, OHADA
     * commercial registration, e.g. "RC/DLA/2019/PM/127651") and NIU
     * (Numéro d'Identifiant Unique, the DGI tax ID, e.g. "M012345678901A").
     * Both are commonly printed on Cameroonian invoices/receipts, so both
     * are modeled rather than a single generic "registration number" —
     * see the reference doc for the research behind this.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('head_office', 191)->nullable()->after('location');
            $table->string('rccm_number', 40)->nullable()->after('reconnection_fine');
            $table->string('niu', 20)->nullable()->after('rccm_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['head_office', 'rccm_number', 'niu']);
        });
    }
};

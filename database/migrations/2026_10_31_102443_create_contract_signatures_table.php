<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) proof that a company signed one version of the
 * OneTech ↔ operator service contract online (App\Services\
 * ContractService): who typed which name, with the exact text they signed
 * (blanks filled in), when and from where, and the stored PDF. Append-only.
 *
 * Also adds version 1 of the contract template as a DRAFT, for the landlord
 * to review and publish (Landlord → Legal documents) — only when no
 * contract version exists yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions')->restrictOnDelete();
            $table->string('tenant_id', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name', 120);
            $table->string('signer_title', 120)->nullable();
            $table->string('company_name', 160);
            $table->string('locale', 5);
            $table->text('body');
            $table->char('body_sha256', 64);
            $table->string('pdf_path', 255);
            $table->timestampTz('signed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['tenant_id', 'legal_document_version_id']);
        });

        if (! DB::table('legal_document_versions')->where('type', 'contract')->exists()) {
            DB::table('legal_document_versions')->insert([
                'type' => 'contract',
                'version' => 1,
                'status' => 'draft',
                'body_en' => (string) file_get_contents(resource_path('legal/en/contract.md')),
                'body_fr' => (string) file_get_contents(resource_path('legal/fr/contract.md')),
                'change_note' => 'First version.',
                'requires_acceptance' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_signatures');
        DB::table('legal_document_versions')->where('type', 'contract')->where('status', 'draft')->delete();
    }
};

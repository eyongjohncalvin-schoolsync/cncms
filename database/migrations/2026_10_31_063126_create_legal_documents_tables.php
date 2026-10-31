<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central (public schema) legal documents the landlord edits and publishes
 * (App\Services\LegalDocumentService): Terms, Privacy and the OneTech ↔
 * operator contract template. One row per version holds both languages;
 * at most one draft and one published version per document. Old versions
 * are kept as `retired`, never deleted, because acceptances point at them.
 *
 * `legal_acceptances` is the proof: which person accepted which version,
 * for which company, when and from where. Append-only.
 *
 * Version 1 of Terms and Privacy is copied in from resources/legal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedInteger('version');
            $table->string('status', 12);
            $table->text('body_en');
            $table->text('body_fr')->nullable();
            $table->string('change_note', 255)->nullable();
            $table->boolean('requires_acceptance')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['type', 'version']);
        });

        DB::statement("CREATE UNIQUE INDEX uq_legal_one_draft ON legal_document_versions (type) WHERE status = 'draft'");
        DB::statement("CREATE UNIQUE INDEX uq_legal_one_published ON legal_document_versions (type) WHERE status = 'published'");

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_version_id')->constrained('legal_document_versions')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tenant_id', 100)->nullable();
            $table->timestampTz('accepted_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->index(['tenant_id', 'legal_document_version_id']);
        });

        foreach (['terms' => true, 'privacy' => false] as $type => $requiresAcceptance) {
            DB::table('legal_document_versions')->insert([
                'type' => $type,
                'version' => 1,
                'status' => 'published',
                'body_en' => (string) file_get_contents(resource_path("legal/en/{$type}.md")),
                'body_fr' => (string) file_get_contents(resource_path("legal/fr/{$type}.md")),
                'change_note' => 'First version.',
                'requires_acceptance' => $requiresAcceptance,
                'published_at' => '2026-11-01 00:00:00+01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_document_versions');
    }
};

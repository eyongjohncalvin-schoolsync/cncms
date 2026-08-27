<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill Printing settings — see App\Models\Company::BILL_TEMPLATES and
 * resources/views/pdf/bills/*.blade.php. `bill_template` selects which of
 * the three bill card partials (classic/compact/modern) the tenant's
 * single-bill print flow (CustomerController::printBill() / Api\
 * BillController::print()) and the future bulk N-up grid
 * (resources/views/pdf/bills/_grid.blade.php) render. `bills_per_page`
 * selects the N-up density (1/2/4) for the bulk grid mechanism — a plain
 * unsignedTinyInteger rather than an enum/lookup table, matching this
 * codebase's "plain const array + Rule::in() validation" convention (see
 * App\Models\Company::BILL_TEMPLATES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bill_template', 32)->default('classic')->after('default_locale');
            $table->unsignedTinyInteger('bills_per_page')->default(1)->after('bill_template');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['bill_template', 'bills_per_page']);
        });
    }
};

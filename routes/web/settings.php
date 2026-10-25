<?php

declare(strict_types=1);

use App\Http\Controllers\SalaryController;
use App\Http\Controllers\SettingsBillPrintingController;
use App\Http\Controllers\SettingsCommandRunController;
use App\Http\Controllers\SettingsCompanyController;
use App\Http\Controllers\SettingsInstallationOfferController;
use App\Http\Controllers\SettingsLocaleController;
use App\Http\Controllers\SettingsNotificationController;
use App\Http\Controllers\SettingsPrepaymentOfferController;
use App\Http\Controllers\SettingsServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings routes
|--------------------------------------------------------------------------
|
| Company Info, Users & Roles, Command Runs — all admin-only per
| web-admin-spec.md's nav spec ("SETTINGS [admin only]"). Policy checks in
| each controller enforce this server-side; the sidebar link is also
| client-side gated (see resources/tsx/layouts/AppLayout.tsx) but that's
| a UX nicety, not the source of truth.
|
*/
Route::get('settings/company', [SettingsCompanyController::class, 'edit'])->name('settings.company.edit');
// Carries the bon limits and the monthly spending cap — a money setting,
// refused while a landlord impersonates (E9).
Route::patch('settings/company', [SettingsCompanyController::class, 'update'])->middleware('not-impersonating:money')->name('settings.company.update');

// Users & Roles moved to the top-level Users Control Center (/users) in
// RBAC v2 Wave 3 — detached from Settings per the plan doc. This redirect
// keeps the old bookmark/muscle-memory URL alive (GET only; the old
// mutation routes are gone — nothing but the retired page ever posted to
// them).
Route::redirect('settings/users', '/users')->name('settings.users.index');

Route::get('settings/command-runs', [SettingsCommandRunController::class, 'index'])->name('settings.command-runs.index');
Route::patch('settings/command-runs/schedule', [SettingsCommandRunController::class, 'updateSchedule'])->name('settings.command-runs.schedule.update');
Route::post('settings/command-runs/{run}/publish', [SettingsCommandRunController::class, 'publish'])->name('settings.command-runs.publish');

// The manual "unstick a permanently-queued run" action — see
// SettingsCommandRunController::cancel()'s doc comment for the full
// 2026-08-27 security-review rationale.
Route::post('settings/command-runs/{run}/cancel', [SettingsCommandRunController::class, 'cancel'])->name('settings.command-runs.cancel');

// Delete/rollback a run against the current, still-mutable period — see
// SettingsCommandRunController::rollback()'s doc comment for the full
// 2026-08-28 manuscript-run-management rationale.
Route::post('settings/command-runs/{run}/rollback', [SettingsCommandRunController::class, 'rollback'])->name('settings.command-runs.rollback');

// Unpublish a published run — deletes its manuscript rows AND restores the
// payment/adjustment idempotency stamps so the period can be fixed and
// re-generated with no --force. See SettingsCommandRunController::unpublish().
Route::post('settings/command-runs/{run}/unpublish', [SettingsCommandRunController::class, 'unpublish'])->name('settings.command-runs.unpublish');

// Language switcher (resources/tsx/layouts/AppLayout.tsx) — updates the
// caller's own `users.locale`, available to every authenticated tenant
// member regardless of role (unlike the routes above, which are
// admin-only).
Route::patch('settings/locale', [SettingsLocaleController::class, 'update'])->name('settings.locale.update');

Route::get('settings/notifications', [SettingsNotificationController::class, 'edit'])->name('settings.notifications.edit');
Route::patch('settings/notifications', [SettingsNotificationController::class, 'update'])->name('settings.notifications.update');

// Bill Printing — template + N-up density settings and the live PDF
// preview (this cycle's design review). The {template} preview route is
// placed above the plain edit/update pair since it's the more specific
// path; Laravel route matching order doesn't actually require this here
// (no overlapping segments), but it groups the three related routes
// together for readability.
Route::get('settings/bill-printing', [SettingsBillPrintingController::class, 'edit'])->name('settings.bill-printing.edit');
Route::patch('settings/bill-printing', [SettingsBillPrintingController::class, 'update'])->name('settings.bill-printing.update');
Route::get('settings/bill-printing/preview/{template}', [SettingsBillPrintingController::class, 'preview'])->name('settings.bill-printing.preview');

// The company's service catalogue (services.md sections 6-7) — TV/Internet/
// VOD/Satellite Hosting and, one level under each, its priced "options"
// (variants — e.g. a specific TV channel broadcast). `services.manage`
// gates the whole surface via ServicePolicy, options included.
Route::get('settings/services', [SettingsServiceController::class, 'index'])->name('settings.services.index');
Route::post('settings/services', [SettingsServiceController::class, 'store'])->name('settings.services.store');
Route::patch('settings/services/{service}', [SettingsServiceController::class, 'update'])->name('settings.services.update');
Route::delete('settings/services/{service}', [SettingsServiceController::class, 'destroy'])->name('settings.services.destroy');
Route::post('settings/services/{service}/apply-price', [SettingsServiceController::class, 'applyPrice'])->name('settings.services.apply-price');

Route::post('settings/services/{service}/variants', [SettingsServiceController::class, 'storeVariant'])->name('settings.services.variants.store');
Route::patch('settings/services/{service}/variants/{variant}', [SettingsServiceController::class, 'updateVariant'])->name('settings.services.variants.update');
Route::delete('settings/services/{service}/variants/{variant}', [SettingsServiceController::class, 'destroyVariant'])->name('settings.services.variants.destroy');
Route::post('settings/services/{service}/variants/{variant}/apply-price', [SettingsServiceController::class, 'applyVariantPrice'])->name('settings.services.variants.apply-price');

// Installation offers (docs/plans/installation-and-promotions.md, phase 2):
// fee + free months per service, and the cap on free months per grant.
// `installations.offers` via InstallationOfferPolicy.
Route::get('settings/installation-offers', [SettingsInstallationOfferController::class, 'index'])->name('settings.installation-offers.index');
Route::post('settings/installation-offers', [SettingsInstallationOfferController::class, 'store'])->name('settings.installation-offers.store');
Route::patch('settings/installation-offers/cap', [SettingsInstallationOfferController::class, 'updateCap'])->name('settings.installation-offers.cap');
Route::patch('settings/installation-offers/one-off-fees', [SettingsInstallationOfferController::class, 'updateOneOffFees'])->name('settings.installation-offers.one-off-fees');
Route::patch('settings/installation-offers/{installationOffer}', [SettingsInstallationOfferController::class, 'update'])->name('settings.installation-offers.update');
Route::delete('settings/installation-offers/{installationOffer}', [SettingsInstallationOfferController::class, 'destroy'])->name('settings.installation-offers.destroy');

// Prepayment offers ("4 months for 10,000") — `payments.offers` via PrepaymentOfferPolicy.
Route::get('settings/prepayment-offers', [SettingsPrepaymentOfferController::class, 'index'])->name('settings.prepayment-offers.index');
Route::post('settings/prepayment-offers', [SettingsPrepaymentOfferController::class, 'store'])->name('settings.prepayment-offers.store');
Route::patch('settings/prepayment-offers/{prepaymentOffer}', [SettingsPrepaymentOfferController::class, 'update'])->name('settings.prepayment-offers.update');
Route::delete('settings/prepayment-offers/{prepaymentOffer}', [SettingsPrepaymentOfferController::class, 'destroy'])->name('settings.prepayment-offers.destroy');

// Salaries (branches v2 D8): headquarters' grades and approvals; a person's
// salary is set from the Users Control Center.
Route::get('settings/salaries', [SalaryController::class, 'index'])->name('settings.salaries.index');
Route::middleware('not-impersonating:money')->group(function () {
    Route::post('settings/salaries/grades', [SalaryController::class, 'storeGrade'])->name('settings.salaries.grades.store');
    Route::patch('settings/salaries/grades/{salaryGrade}', [SalaryController::class, 'updateGrade'])->name('settings.salaries.grades.update');
    Route::post('settings/salaries/{staffSalary}/approve', [SalaryController::class, 'approve'])->name('settings.salaries.approve');
    Route::post('settings/salaries/{staffSalary}/reject', [SalaryController::class, 'reject'])->name('settings.salaries.reject');
    Route::post('users/{tenantUser}/salary', [SalaryController::class, 'set'])->name('users.salary.set');
});

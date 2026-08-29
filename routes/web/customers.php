<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
Route::post('customers/import', [CustomerController::class, 'import'])->name('customers.import');
// Bulk bill-rate adjustment tool (App\Services\CustomerService::
// bulkUpdateBill()/previewBulkBillUpdate()) — the annual price-adjustment
// workflow on Customers/Index.tsx, distinct from and additive to the
// single-customer edit form's bill field above. The preview route MUST be
// registered before the plain bulk-update-bill route only insofar as both
// are declared explicitly (no {customer} wildcard is involved here, so
// there's no ordering ambiguity with show()'s customers/{customer} route
// below either way — kept together for readability).
Route::post('customers/bulk-update-bill/preview', [CustomerController::class, 'previewBulkUpdateBill'])->name('customers.bulk-update-bill.preview');
Route::post('customers/bulk-update-bill', [CustomerController::class, 'bulkUpdateBill'])->name('customers.bulk-update-bill');
Route::get('customers/import/template', [CustomerController::class, 'importTemplate'])->name('customers.import.template');
Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
// Archive / restore (customer-deletion deliberation, 2026-08-29). A
// customer with billing history is archived (soft-deleted), never
// hard-deleted — destroy() above stays the zero-history junk-row path.
// restore() must resolve an already-archived customer, so its binding
// (and show()'s, further down) opts into trashed rows via ->withTrashed().
Route::patch('customers/{customer}/archive', [CustomerController::class, 'archive'])->name('customers.archive');
Route::patch('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore')->withTrashed();
// Dedicated status actions (App\Services\CustomerStatusService) — a fast
// alternative to the generic update() route above, distinct the same way
// payments/{payment}/verify is distinct from a generic payment edit.
Route::patch('customers/{customer}/disconnect', [CustomerController::class, 'disconnect'])->name('customers.disconnect');
Route::patch('customers/{customer}/suspend', [CustomerController::class, 'suspend'])->name('customers.suspend');
Route::patch('customers/{customer}/reconnect', [CustomerController::class, 'reconnect'])->name('customers.reconnect');
// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top
// of the group-level 'throttle:web' (300/min) applied in routes/web.php —
// the tighter export limit always trips first, so this is the effective
// ceiling.
Route::get('customers/{customer}/bill/print', [CustomerController::class, 'printBill'])
    ->name('customers.bill.print')
    ->middleware('throttle:exports');
// Lightweight JSON lookup (not an Inertia page) for the Record Payment
// form's info panel — see CustomerController::lastPayment()'s doc comment.
Route::get('customers/{customer}/last-payment', [CustomerController::class, 'lastPayment'])->name('customers.last-payment');
Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show')->withTrashed();

<?php

declare(strict_types=1);

use App\Http\Controllers\DisconnectionsController;
use Illuminate\Support\Facades\Route;

// The bulk customer-status workboard — see DisconnectionsController's class
// doc. Distinct from routes/web/customers.php's single-customer
// disconnect/suspend/reconnect routes, which remain in place for the
// single-row quick actions on Customers/Show.tsx and Customers/Index.tsx.
Route::get('disconnections', [DisconnectionsController::class, 'index'])->name('disconnections.index');
Route::post('disconnections/bulk-disconnect', [DisconnectionsController::class, 'bulkDisconnect'])->name('disconnections.bulk-disconnect');
Route::post('disconnections/bulk-suspend', [DisconnectionsController::class, 'bulkSuspend'])->name('disconnections.bulk-suspend');
Route::post('disconnections/bulk-reconnect', [DisconnectionsController::class, 'bulkReconnect'])->name('disconnections.bulk-reconnect');

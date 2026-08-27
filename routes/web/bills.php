<?php

declare(strict_types=1);

use App\Http\Controllers\BillBatchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Asynchronous (queued) bill generation
|--------------------------------------------------------------------------
|
| Owner's 2026-08-30 ask — see App\Services\BillBatchService. Replaces the
| synchronous GET /manuscripts/bills that rendered every bill inside the web
| request. The batch LIST + status is folded into the Manuscripts index
| props (ManuscriptController::index()'s `billBatches`), so there is no list
| route here — only "start a run" and "download one artifact".
|
| Both layer 'throttle:exports' (10/min/user) on top of the group-level
| 'throttle:web', matching the register export and the old bills download.
|
*/
Route::post('manuscripts/bills/generate', [BillBatchController::class, 'generate'])
    ->name('manuscripts.bills.generate')
    ->middleware('throttle:exports');

// {billBatch} / {billBatchFile} route-model-bind by uuid (#[RouteKey('uuid')]).
Route::get('manuscripts/bills/batches/{billBatch}/files/{billBatchFile}', [BillBatchController::class, 'download'])
    ->name('manuscripts.bills.download')
    ->middleware('throttle:exports');

// Cancel an in-flight run; clear (delete) a run's artifacts to regenerate.
Route::post('manuscripts/bills/batches/{billBatch}/cancel', [BillBatchController::class, 'cancel'])
    ->name('manuscripts.bills.cancel')
    ->middleware('throttle:exports');

Route::delete('manuscripts/bills/batches/{billBatch}', [BillBatchController::class, 'destroy'])
    ->name('manuscripts.bills.destroy')
    ->middleware('throttle:exports');

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ComplaintController;
use Illuminate\Support\Facades\Route;

// Named with an 'api.' prefix so these don't collide with the web panel's
// identically-shaped route names — see routes/web/complaints.php and the
// same convention in routes/api/payments.php.
//
// 'duplicates' must be registered before the apiResource's {complaint}
// route-model-bound show route below it, or 'duplicates' would itself be
// interpreted as a uuid — see routes/web/complaints.php's identical
// ordering note.
Route::get('complaints/duplicates', [ComplaintController::class, 'duplicates']);

Route::apiResource('complaints', ComplaintController::class)
    ->only(['index', 'store', 'show'])
    ->names('api.complaints');

Route::post('complaints/{complaint}/resolve', [ComplaintController::class, 'resolve']);
Route::post('complaints/{complaint}/reopen', [ComplaintController::class, 'reopen']);
Route::post('complaints/{complaint}/link-duplicate', [ComplaintController::class, 'linkDuplicate']);
Route::post('complaints/{complaint}/assign', [ComplaintController::class, 'assign']);

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

// The customer add/edit form's tick-list (services.md sections 6-8) — read
// only, open to any authenticated tenant member (mirrors routes/api/
// zones.php's reasoning: this list itself isn't sensitive, the real gate
// is on the customer write endpoints).
Route::get('services', [ServiceController::class, 'index']);

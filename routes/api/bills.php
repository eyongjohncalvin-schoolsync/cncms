<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BillController;
use Illuminate\Support\Facades\Route;

Route::get('bills/{customer}/print', [BillController::class, 'print']);

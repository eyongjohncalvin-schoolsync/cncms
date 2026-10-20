<?php

declare(strict_types=1);

use App\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

// Vouchers / bons de caisse (docs/plans/cash-controls.md, wave 5) — VoucherPolicy.
Route::get('vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
Route::post('vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
Route::get('vouchers/{voucher}', [VoucherController::class, 'show'])->name('vouchers.show');
Route::get('vouchers/{voucher}/pdf', [VoucherController::class, 'pdf'])->name('vouchers.pdf');
Route::get('vouchers/{voucher}/files/{which}', [VoucherController::class, 'file'])->whereIn('which', ['attachment', 'signed-copy'])->name('vouchers.file');
Route::post('vouchers/{voucher}/approve', [VoucherController::class, 'approve'])->name('vouchers.approve');
Route::post('vouchers/{voucher}/reject', [VoucherController::class, 'reject'])->name('vouchers.reject');
Route::post('vouchers/{voucher}/cancel', [VoucherController::class, 'cancel'])->name('vouchers.cancel');
Route::post('vouchers/{voucher}/pay', [VoucherController::class, 'pay'])->name('vouchers.pay');
Route::post('vouchers/{voucher}/settle', [VoucherController::class, 'settle'])->name('vouchers.settle');
Route::post('vouchers/{voucher}/signed-copy', [VoucherController::class, 'uploadSignedCopy'])->name('vouchers.signed-copy');

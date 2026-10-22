<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerStatementController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\ReceiptCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public signed receipt-PDF link
|--------------------------------------------------------------------------
|
| Distinct from the tenant-scoped pages grouped under ['auth', 'tenant.web']
| in routes/web.php: this ONE route is the WhatsApp-shareable receipt PDF
| link (payment-receipts-and-whatsapp.md). It must work for a recipient who
| has no account and no session — so it runs through neither `auth` nor
| `tenant.web`. Instead:
|
|   - `signed`  — Laravel's ValidateSignature middleware. The link is minted
|     with URL::temporarySignedRoute() (App\Support\PaymentReceiptLink) with
|     a ~7-day expiry; any tamper with the receipt uuid, the tenant key, or
|     the expiry breaks the signature and 403s here.
|   - `throttle:receipt-share` — IP-keyed, low ceiling (config/rate-limits.php).
|
| The controller action re-initialises tenancy from the signed `tenant`
| query param before touching any tenant-schema row — see its doc comment.
| {receiptUuid} is a plain string, NOT a route-model binding, on purpose.
|
*/
Route::get('payment-receipts/{receiptUuid}/pdf/shared', [PaymentReceiptController::class, 'sharedPdf'])
    ->middleware(['signed', 'throttle:receipt-share'])
    ->name('payment-receipts.pdf.shared');

// The page a receipt's QR code opens — "is this receipt genuine?". Signed,
// never expiring (see App\Support\PaymentReceiptLink::verify()).
Route::get('payment-receipts/{receiptUuid}/verify', [PaymentReceiptController::class, 'verify'])
    ->middleware(['signed', 'throttle:receipt-share'])
    ->name('payment-receipts.verify');

// A customer's statement of account, opened from the mobile app in the
// device browser (App\Support\PaymentReceiptLink::statement()). Signed,
// ~7-day expiry; tenant and period are covered by the signature.
Route::get('customers/{customerUuid}/statement/shared', [CustomerStatementController::class, 'sharedPdf'])
    ->middleware(['signed', 'throttle:receipt-share'])
    ->name('customers.statement.shared');

// The short check link printed as the QR on every receipt from cash
// controls onwards (docs/plans/cash-controls.md): /r/{tenant}/{number}?c=CODE.
// Deliberately NOT signed — a phone builds this link offline — the
// receipt's security code is what proves it genuine (ReceiptCheckController).
Route::get('r/{tenant}/{number}', [ReceiptCheckController::class, 'show'])
    ->middleware('throttle:receipt-share')
    ->where('number', '[A-Za-z0-9-]+')
    ->name('receipts.check');

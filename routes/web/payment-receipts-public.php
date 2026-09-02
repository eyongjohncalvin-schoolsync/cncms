<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentReceiptController;
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

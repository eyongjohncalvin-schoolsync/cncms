<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Services\BillNotificationService;
use App\Services\ManuscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly BillNotificationService $billNotifications,
    ) {}

    /**
     * Single-customer bill slip PDF. See business-rules.md section 3 and
     * api-spec.md section 9.1: GET /api/v1/bills/{customer_uuid}/print?period=...
     * Renders whichever of the three bill templates (resources/views/pdf/
     * bills/{classic,compact,modern}.blade.php) the tenant has configured
     * via Settings > Bill Printing (Company::bill_template) — see
     * App\Http\Controllers\CustomerController::printBill(), this endpoint's
     * web-session twin, for the same fallback-to-'classic' logic.
     */
    public function print(Request $request, Customer $customer): Response
    {
        $this->authorize('printBill', $customer);

        $period = $request->string('period')->value() ?: null;

        $data = $this->manuscripts->billData($customer, $period);
        $company = $data['company'] ?? null;
        $template = in_array($company?->bill_template, Company::BILL_TEMPLATES, true)
            ? $company->bill_template
            : 'classic';

        // dompdf's font/rendering overhead routinely exceeds PHP's default
        // 128M memory_limit even for a single record (confirmed repeatedly
        // in this environment) — raise it just for this request rather
        // than globally, since ini_set('memory_limit', ...) is respected
        // at runtime on effectively every hosting setup, including ones
        // that don't allow editing php.ini directly.
        ini_set('memory_limit', '512M');

        return Pdf::loadView('pdf.bills.show', [...$data, 'template' => $template])->stream("bill-{$customer->uuid}.pdf");
    }

    /**
     * GET /api/v1/bills/{customer}/whatsapp-message — mobile counterpart of
     * the manual (free, no-Twilio) WhatsApp "Send Bill" flow already shipped
     * on the web Manuscripts page (App\Http\Controllers\ManuscriptController
     * ::index()'s `wa_link` column / ::sendBill()). The mobile app has no
     * Inertia props to read that from, so this exposes the same
     * BillNotificationService data as plain JSON. Gated by the same
     * 'printBill' ability CustomerPolicy already uses for bill-print access
     * (super/admin/manager/agent) — bill-notification content is exactly
     * the same "can this role see this customer's bill" data.
     *
     * Deliberately does NOT hand back a ready-made wa.me link: the mobile
     * client builds that itself from `message` + `phone` (see
     * mobile/src/utils/whatsapp.ts), so this only needs to return the two
     * raw ingredients plus an honest, non-error `reason` for why they might
     * be missing:
     *  - 'no_phone': the customer has no phone on file, or it doesn't
     *    normalize to a plausible Cameroon mobile number (~78% of legacy
     *    customers — see database-schema.md's known data-quality issues).
     *    The mobile UI must not show a broken/dead button for this case.
     *  - 'no_manuscript': the customer has no manuscript yet, so there is
     *    no real bill figure to send.
     */
    public function whatsappMessage(Customer $customer): JsonResponse
    {
        $this->authorize('printBill', $customer);

        $phone = $this->billNotifications->normalizedPhone($customer);
        $message = $this->billNotifications->composeMessage($customer);

        return response()->json([
            'data' => [
                'has_phone' => $phone !== null,
                'available' => $phone !== null && $message !== null,
                'reason' => match (true) {
                    $phone === null => 'no_phone',
                    $message === null => 'no_manuscript',
                    default => null,
                },
                'phone' => $phone,
                'message' => $message,
            ],
        ]);
    }
}

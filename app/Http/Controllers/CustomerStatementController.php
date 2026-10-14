<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /customers/{customer}/statement?months=12 (or ?from=YYYY-MM&to=YYYY-MM)
 * — the customer's statement of account as a PDF: opening balance, every
 * bill with its paid status, every payment with its receipt number, closing
 * balance. Same gate as printing a bill (CustomerPolicy::printBill).
 */
class CustomerStatementController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function pdf(Request $request, Customer $customer): Response
    {
        $this->authorize('printBill', $customer);

        $customer->loadMissing('zone');

        $to = $this->period($request->query('to')) ?? Carbon::now()->format('Y-m');
        $months = max(1, min(60, (int) $request->query('months', 12)));
        $from = $this->period($request->query('from'))
            ?? Carbon::createFromFormat('!Y-m', $to)->subMonthsNoOverflow($months - 1)->format('Y-m');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return $this->render($customer, $from, $to);
    }

    /**
     * Signed, public statement link (App\Support\PaymentReceiptLink::statement())
     * the mobile app opens in the device browser. Outside ['auth',
     * 'tenant.web'] like the shared receipt PDF: the tenant and the period
     * travel in the signed URL, so none of them can be tampered with.
     */
    public function sharedPdf(Request $request, string $customerUuid): Response
    {
        $tenant = Tenant::find((string) $request->query('tenant', ''));
        abort_if($tenant === null, 404);

        tenancy()->initialize($tenant);

        $customer = Customer::withTrashed()->with('zone')->where('uuid', $customerUuid)->first();
        $from = $this->period($request->query('from'));
        $to = $this->period($request->query('to'));
        abort_if($customer === null || $from === null || $to === null, 404);

        return $this->render($customer, min($from, $to), max($from, $to));
    }

    private function render(Customer $customer, string $from, string $to): Response
    {
        $company = Company::cached();

        return Pdf::loadView('pdf.statement', [
            ...$this->invoices->statement($customer, $from, $to),
            'company' => $company,
            'logo' => $company?->logoDataUri(),
            'accountCode' => $customer->accountCode(),
        ])->setPaper('a4', 'portrait')->stream("statement-{$customer->accountCode()}-{$from}-to-{$to}.pdf");
    }

    private function period(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : null;
    }
}

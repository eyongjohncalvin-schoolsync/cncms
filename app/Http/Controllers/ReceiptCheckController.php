<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PaymentReceipt;
use App\Models\ReceiptLeaf;
use App\Models\Tenant;
use App\Services\ReceiptAlertService;
use App\Support\ReceiptSecurityCode;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public "is this receipt genuine?" page — what the QR on every receipt
 * opens: /r/{tenant}/{number}?c={code} (docs/plans/cash-controls.md).
 * No login: the customer is the auditor. Throttled per IP.
 *
 * Outcomes, most reassuring first:
 *   genuine      — recorded, and the code (when given) matches;
 *   check_amount — recorded, no code to compare (a handwritten book leaf, or
 *                  the customer typed only the number): the page shows the
 *                  recorded amount so the customer compares it to the paper;
 *   mismatch     — recorded, but the code given doesn't match: the paper
 *                  was altered (amount, customer or number);
 *   void         — the receipt was cancelled;
 *   unreported   — a real number the system issued, but no payment is
 *                  recorded on it yet: money may have been taken and not
 *                  handed in. The office is alerted immediately;
 *   cancelled    — a number cancelled before use (misprint / torn leaf);
 *   unknown      — not a number this company ever issued: a forgery.
 */
class ReceiptCheckController extends Controller
{
    public function __construct(
        private readonly ReceiptAlertService $alerts,
    ) {}

    public function show(Request $request, string $tenant, string $number): View
    {
        $tenantModel = Tenant::find($tenant);
        abort_if($tenantModel === null, 404);

        tenancy()->initialize($tenantModel);

        $number = strtoupper(trim($number));
        $code = $request->query('c');
        $code = is_string($code) && $code !== '' ? $code : null;

        $view = [
            'tenant' => $tenant,
            'number' => $number,
            'code' => $code,
            'companyName' => Company::cached()?->name,
            'companyPhone' => Company::cached()?->phone,
            'receipt' => null,
        ];

        $receipt = PaymentReceipt::query()->where('receipt_number', $number)->first();

        if ($receipt !== null) {
            // The branch that issued it answers questions about it (branches v2 D4).
            $view['companyPhone'] = Company::forBranch($receipt->branch_id)?->phone;
            $snapshot = $receipt->snapshot ?? [];

            $outcome = match (true) {
                $receipt->isVoid() => 'void',
                $code === null || $receipt->security_code === null => 'check_amount',
                ReceiptSecurityCode::matches($receipt->security_code, $code) => 'genuine',
                default => 'mismatch',
            };

            if ($outcome === 'mismatch') {
                $this->alerts->codeMismatch($receipt, (string) $code, $request->ip());
            }

            return view('receipts.check', [...$view, 'outcome' => $outcome, 'receipt' => [
                'amount' => (string) $receipt->amount,
                'issued_at' => $receipt->issued_at,
                'customer' => $snapshot['customer']['name'] ?? null,
                'collected_by' => $snapshot['payment']['collected_by'] ?? null,
                'paid_by' => $snapshot['payment']['paid_by_label'] ?? null,
                'periods' => $snapshot['payment']['periods'] ?? [],
            ]]);
        }

        $leaf = ReceiptLeaf::query()->with('block.holder')->where('number', $number)->first();

        if ($leaf === null) {
            return view('receipts.check', [...$view, 'outcome' => 'unknown']);
        }

        if ($leaf->status === ReceiptLeaf::STATUS_CANCELLED) {
            return view('receipts.check', [...$view, 'outcome' => 'cancelled']);
        }

        // Unused: issued to a collector, but nothing recorded on it yet.
        $this->alerts->unreportedScan($leaf, $code, $request->ip());

        return view('receipts.check', [...$view, 'outcome' => 'unreported', 'holderName' => $leaf->block?->holder?->name]);
    }
}

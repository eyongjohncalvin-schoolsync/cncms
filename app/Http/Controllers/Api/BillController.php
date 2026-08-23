<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ManuscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
    ) {}

    /**
     * Single-customer bill slip PDF. See business-rules.md section 3 and
     * api-spec.md section 9.1: GET /api/v1/bills/{customer_uuid}/print?period=...
     */
    public function print(Request $request, Customer $customer): Response
    {
        $this->authorize('printBill', $customer);

        $period = $request->string('period')->value() ?: null;

        $data = $this->manuscripts->billData($customer, $period);

        return Pdf::loadView('pdf.bill', $data)->stream("bill-{$customer->uuid}.pdf");
    }
}

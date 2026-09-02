<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\CustomerRecordExport;
use App\Models\Company;
use App\Models\Customer;
use App\Services\CustomerRecordExportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Export full record" on Customers/Show (docs/plans/customer-record-export.md)
 * — one downloadable file bundling EVERYTHING CNCMS holds about one
 * customer, for an auditor or a billing dispute. Two formats, one gate
 * (CustomerPolicy::exportRecord → `customers.export_record`, seeded
 * super/admin only), one throttle (`throttle:exports`).
 *
 * Both formats are fed the IDENTICAL CustomerRecordExportService::gather()
 * result, so the PDF and the spreadsheet can never disagree.
 *
 * Every route binds the customer with ->withTrashed() so an ARCHIVED
 * customer can still be exported — "archived, not deleted" means the full
 * history stays auditable (Customer is the app's only SoftDeletes model).
 */
class CustomerRecordExportController extends Controller
{
    public function __construct(
        private readonly CustomerRecordExportService $records,
    ) {}

    /**
     * GET /customers/{customer}/record-export/pdf — the human-readable,
     * company-headed artifact for printing / sharing.
     */
    public function pdf(Customer $customer): Response
    {
        $this->authorize('exportRecord', $customer);

        $data = $this->records->gather($customer);

        // A full-history record for a long-lived customer is a large dompdf
        // layout (hundreds of manuscript + audit rows) — same ceiling bump
        // as ManuscriptController::export()'s PDF branch.
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        return Pdf::loadView('pdf.customer-record', [
            'data' => $data,
            'company' => Company::cached(),
        ])
            ->setPaper('a4', 'portrait')
            ->download('customer-'.$this->slug($customer).'-record.pdf');
    }

    /**
     * GET /customers/{customer}/record-export/xlsx — the structured,
     * multi-sheet workbook (one tab per section) for a spreadsheet or an
     * import into another tool.
     */
    public function data(Customer $customer): BinaryFileResponse
    {
        $this->authorize('exportRecord', $customer);

        $data = $this->records->gather($customer);

        return Excel::download(
            new CustomerRecordExport($data),
            'customer-'.$this->slug($customer).'-record.xlsx',
        );
    }

    private function slug(Customer $customer): string
    {
        // Customer names are frequently ALL CAPS ("JANE DOE") and can
        // collide across the register — suffix the short uuid so the
        // downloaded filename is still unambiguous.
        return Str::slug($customer->name ?: 'customer').'-'.substr($customer->uuid, 0, 8);
    }
}

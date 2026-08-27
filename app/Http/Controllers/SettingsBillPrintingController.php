<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBillPrintingRequest;
use App\Models\Company;
use App\Services\ManuscriptService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Settings — Bill Printing (this cycle's design review). Single-row
 * settings table (bill_template/bills_per_page live on `companies`, see
 * the 2026_08_24_180000 migration) — same "no Service/Repository layer for
 * a one-record settings form" deliberate simplification as
 * SettingsCompanyController/SettingsNotificationController. Gated the same
 * way as Company Info (CompanyPolicy) — everyone with tenant access can
 * view the templates/preview, only super/admin can change the tenant's
 * default.
 */
class SettingsBillPrintingController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
    ) {}

    public function edit(): Response
    {
        $this->authorize('view', Company::class);

        $company = Company::cached();

        return Inertia::render('Settings/BillPrinting', [
            'bill_template' => $company?->bill_template ?? 'classic',
            'bills_per_page' => $company?->bills_per_page ?? 1,
            'templates' => Company::BILL_TEMPLATES,
            'bills_per_page_options' => Company::BILLS_PER_PAGE_OPTIONS,
        ]);
    }

    public function update(UpdateBillPrintingRequest $request): RedirectResponse
    {
        $company = Company::query()->first();

        $company->update($request->validated());

        Company::forgetCache();

        return redirect()->route('settings.bill-printing.edit')->with('success', 'Bill printing settings updated.');
    }

    /**
     * GET /settings/bill-printing/preview/{template} — renders ONE sample
     * bill in the requested template as an inline PDF (Content-Disposition:
     * inline via Barryvdh\DomPDF\Facade\Pdf::stream(), the same call every
     * other PDF endpoint in this app already uses — see CustomerController::
     * printBill()). This is a REAL dompdf render, not an HTML mockup: the
     * product owner's explicit ask this cycle was to preview what will
     * actually print, and dompdf's CSS support has real quirks/limitations
     * an HTML approximation wouldn't faithfully represent.
     *
     * Gated identically to the settings page itself (CompanyPolicy::view()
     * — true for everyone with tenant access) rather than update(), since
     * this is a read-only preview, not a persisted change.
     */
    public function preview(string $template): SymfonyResponse
    {
        $this->authorize('view', Company::class);

        abort_unless(in_array($template, Company::BILL_TEMPLATES, true), 404);

        $data = $this->manuscripts->sampleBillData();

        return Pdf::loadView('pdf.bills.show', [...$data, 'template' => $template])
            ->stream("bill-preview-{$template}.pdf");
    }
}

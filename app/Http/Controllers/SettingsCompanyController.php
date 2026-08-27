<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings — Company Info (web-admin-spec.md section 3.13). Single-row
 * settings table (see TenantDatabaseSeeder::seedCompany) — no
 * Service/Repository layer, this is a deliberate simplification for a
 * one-record settings form.
 */
class SettingsCompanyController extends Controller
{
    public function edit(): Response
    {
        $this->authorize('view', Company::class);

        $company = Company::cached();

        return Inertia::render('Settings/Company', [
            'company' => $company ? [
                'uuid' => $company->uuid,
                'name' => $company->name,
                'location' => $company->location,
                'head_office' => $company->head_office,
                'email' => $company->email,
                'phone' => $company->phone,
                'tech_number' => $company->tech_number,
                'momo_number' => $company->momo_number,
                'momo_name' => $company->momo_name,
                'reconnection_fine' => (string) $company->reconnection_fine,
                'arrears_second_approval_threshold' => (string) $company->arrears_second_approval_threshold,
                'rccm_number' => $company->rccm_number,
                'niu' => $company->niu,
                'logo_url' => $company->getFirstMediaUrl('logo') ?: null,
            ] : null,
        ]);
    }

    public function update(UpdateCompanyRequest $request): RedirectResponse
    {
        $company = Company::query()->first();

        $company->update($request->safe()->except('logo'));

        if ($request->hasFile('logo')) {
            // singleFile() collection (see Company::registerMediaCollections)
            // auto-replaces/deletes any previous logo, so this is safe to
            // call on every re-upload without manually clearing the old one.
            $company->addMediaFromRequest('logo')->toMediaCollection('logo');
        }

        Company::forgetCache();

        return redirect()->route('settings.company.edit')->with('success', 'Company info updated.');
    }
}

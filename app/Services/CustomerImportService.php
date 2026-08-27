<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\CustomerData;
use App\Http\Requests\StoreCustomerRequest;
use App\Imports\CustomersImport;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Bulk customer import from an .xlsx spreadsheet
 * (customer_upload_main.xlsx — cncms-context's "Bulk Import Formats"
 * section). The critical column is `others`: it maps straight to
 * `customers.others`, the opening-arrears balance an onboarding operator's
 * existing customers bring with them — consumed exactly once by the first
 * manuscript:calculate run for that customer (see
 * App\Services\ManuscriptCalculator's class doc comment). This service
 * does nothing special for it beyond passing it through the normal
 * validated create path — the "apply once" behaviour lives entirely in
 * ManuscriptCalculator, not here.
 *
 * Row-level, not all-or-nothing: every row is resolved (zone name -> uuid)
 * and validated independently through the exact same
 * CustomerService::create() write path a manual "Add Customer" form
 * submission uses (same StoreCustomerRequest::rules(), same CustomerData/
 * repository), so a spreadsheet row is held to the same data-integrity bar
 * as a typed one. A bad row (unknown zone, missing bill, etc.) is reported
 * and skipped rather than failing the whole file — mirrors
 * PaymentService::createBulk()'s partial-success shape.
 *
 * Known real-world data quality issues (cncms-context): placeholder rows
 * ("empty 1"), stray subtotal rows ("TOTAL THREE CORNERS"), and messy
 * phone formats. Deliberately NOT handled by name-pattern heuristics here
 * (too fragile — "Empty 1" could be a real customer's name) — a subtotal/
 * placeholder row almost always lacks a real zone and/or bill, so ordinary
 * required-field validation catches it and reports it as a failed row for
 * human review, exactly like any other bad row. Phone format is accepted
 * as-is (StoreCustomerRequest enforces no format on `phone` either), so
 * messy real-world numbers import without being rejected for cosmetics.
 */
final class CustomerImportService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly ZoneService $zones,
    ) {}

    /**
     * @return array{succeeded: array<int, array{row: int, uuid: string, name: string}>, failed: array<int, string>, upload_uuid: string}
     */
    public function import(UploadedFile $file, User $user): array
    {
        $upload = Upload::query()->create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store('imports/customers', 'local'),
            'status' => 'processing',
            'type' => 'customers',
            'imported_by' => $user->id,
        ]);

        try {
            $rows = (Excel::toCollection(new CustomersImport, $file))->first() ?? collect();
        } catch (Throwable $e) {
            $upload->update(['status' => 'failed', 'errors' => [1 => 'Could not read the spreadsheet: '.$e->getMessage()]]);

            throw $e;
        }

        // 29 zones, cached for an hour by ZoneService::all() — one lookup
        // for the whole file rather than a query per row.
        $zonesByName = $this->zones->all()->keyBy('name');

        $succeeded = [];
        $failed = [];
        $rules = (new StoreCustomerRequest)->rules();

        foreach ($rows as $index => $row) {
            // Heading row is row 1, so the first data row is row 2.
            $rowNumber = $index + 2;

            $name = $this->cleanString($row['name'] ?? null);
            $zoneName = $this->cleanString($row['zone'] ?? null);
            $bill = $this->cleanDecimal($row['bill'] ?? null);

            // A wholly blank row (trailing spreadsheet artifact) isn't a
            // real entry — skip it without reporting it as a failure.
            if ($name === null && $zoneName === null && $bill === null) {
                continue;
            }

            if ($zoneName === null) {
                $failed[$rowNumber] = 'The zone field is required.';

                continue;
            }

            $zone = $zonesByName->get($zoneName);

            if ($zone === null) {
                // Deliberately does NOT auto-create the zone or silently
                // skip the row — per cncms-context, a mismatched zone name
                // must be reported so the operator can fix the spelling or
                // create the zone first.
                $failed[$rowNumber] = "Zone \"{$zoneName}\" does not exist. Create it first or check the spelling.";

                continue;
            }

            $data = [
                'zone_uuid' => $zone->uuid,
                'name' => $name,
                'phone' => $this->cleanString($row['phone'] ?? null),
                'level' => $this->cleanString($row['level'] ?? null),
                'location' => $this->cleanString($row['location'] ?? null),
                'bill' => $bill,
                'others' => $this->cleanDecimal($row['others'] ?? null),
                'status' => $this->cleanString($row['status'] ?? null),
            ];

            $validator = Validator::make($data, $rules);

            if ($validator->fails()) {
                $failed[$rowNumber] = $validator->errors()->first();

                continue;
            }

            try {
                $customer = $this->customers->create(CustomerData::fromArray($validator->validated()));
                $succeeded[] = ['row' => $rowNumber, 'uuid' => $customer->uuid, 'name' => $customer->name];
            } catch (Throwable $e) {
                $failed[$rowNumber] = $e->getMessage();
            }
        }

        $upload->update([
            'status' => 'completed',
            'total_rows' => count($succeeded) + count($failed),
            'succeeded_count' => count($succeeded),
            'failed_count' => count($failed),
            'errors' => $failed === [] ? null : $failed,
        ]);

        return ['succeeded' => $succeeded, 'failed' => $failed, 'upload_uuid' => $upload->uuid];
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Spreadsheet numeric cells can arrive as float/int/numeric-string,
     * occasionally with binary floating-point noise (e.g. 2500.09999999996
     * instead of 2500.10). Normalizing to a clean 2-decimal string here —
     * rather than passing the raw cell value straight to the `decimal:0,2`
     * validation rule — keeps a perfectly ordinary spreadsheet amount from
     * failing validation for reasons the person filling it in has no way
     * to see or fix.
     */
    private function cleanDecimal(mixed $value): ?string
    {
        $value = $this->cleanString($value);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value)) {
            // Leave non-numeric junk as-is so the `numeric` validation
            // rule reports it clearly, rather than silently coercing it
            // to "0.00" via (float) cast.
            return $value;
        }

        return number_format((float) $value, 2, '.', '');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ZoneData;
use App\Http\Requests\StoreZoneRequest;
use App\Imports\ZonesImport;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Bulk zone import from an .xlsx spreadsheet (zone_upload.xlsx —
 * cncms-context's "Bulk Import Formats" section: two columns, `name` and
 * `town`).
 *
 * Row-level, not all-or-nothing: every row is validated and created
 * independently through the exact same ZoneService::create() write path a
 * manual "Add Zone" form submission uses (same StoreZoneRequest::rules(),
 * same ZoneData/repository), so a spreadsheet row is held to the same
 * data-integrity bar as a typed one. One bad row (duplicate name, missing
 * field) is reported and skipped rather than failing the whole file —
 * mirrors PaymentService::createBulk()'s partial-success shape.
 *
 * Processing rows one at a time (rather than validating the whole sheet
 * up front) is deliberate: it makes in-file duplicate names self-detect
 * for free — row 2 "Z01" is created, so row 5's "Z01" trips the same
 * `unique:zones,name` rule its already-inserted sibling would trip against
 * the database, with no extra bookkeeping needed here.
 */
final class ZoneImportService
{
    public function __construct(
        private readonly ZoneService $zones,
    ) {}

    /**
     * @return array{succeeded: array<int, array{row: int, uuid: string, name: string}>, failed: array<int, string>, upload_uuid: string}
     */
    public function import(UploadedFile $file, User $user): array
    {
        $upload = Upload::query()->create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $file->store('imports/zones', 'local'),
            'status' => 'processing',
            'type' => 'zones',
            'imported_by' => $user->id,
        ]);

        try {
            $rows = (Excel::toCollection(new ZonesImport, $file))->first() ?? collect();
        } catch (Throwable $e) {
            $upload->update(['status' => 'failed', 'errors' => [1 => 'Could not read the spreadsheet: '.$e->getMessage()]]);

            throw $e;
        }

        $succeeded = [];
        $failed = [];
        $rules = (new StoreZoneRequest)->rules();

        foreach ($rows as $index => $row) {
            // Heading row is row 1, so the first data row is row 2.
            $rowNumber = $index + 2;

            $name = $this->clean($row['name'] ?? null);
            $town = $this->clean($row['town'] ?? null);

            // A wholly blank row (trailing spreadsheet artifact) isn't a
            // real entry — skip it without reporting it as a failure.
            if ($name === null && $town === null) {
                continue;
            }

            $validator = Validator::make(['name' => $name, 'town' => $town], $rules);

            if ($validator->fails()) {
                $failed[$rowNumber] = $validator->errors()->first();

                continue;
            }

            try {
                $zone = $this->zones->create(ZoneData::fromArray($validator->validated()));
                $succeeded[] = ['row' => $rowNumber, 'uuid' => $zone->uuid, 'name' => $zone->name];
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

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
